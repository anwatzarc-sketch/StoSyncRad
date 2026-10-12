<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Fulfillment\Enums\StockRequestStatus as S;
use App\Domains\Fulfillment\Models\StockRequest;
use App\Domains\Fulfillment\Models\StockRequestLine;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\ReservationPolicy;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Services\ReservationService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Internal stock requisitions (Chapter 08 §8.6). A request never moves stock; on approval it may
 * reserve eligible stock (stock.reservation.policy = on_approval).
 */
final class StockRequestWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly ReservationService $reservations,
        private readonly PlatformConfig $config,
        private readonly Access $access,
    ) {}

    /** @param  array<string, mixed>  $data  validated against StockRequestSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): StockRequest
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $this->authorizeCreate($actor, $data['warehouse_id']);
                $max = $this->config->int('fulfillment.request.max_lines');
                if (count($data['lines']) > $max) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, "A request may have at most $max lines.");
                }
                $req = new StockRequest;
                $req->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::STOCK_REQUEST, $data['warehouse_id']),
                    'warehouse_id' => $data['warehouse_id'], 'branch_id' => Warehouse::query()->whereKey($data['warehouse_id'])->value('branch_id'),
                    'status' => S::DRAFT, 'requester_id' => $actor->id, 'created_by' => $actor->id,
                    'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $req = $this->lockDocument(StockRequest::class, $id);
                $this->authorizeOwnOr($actor, $req, 'fulfillment.stock_request.update');
                $this->expectVersion($req, $data['version'] ?? null);
                $this->assertStatus($req, [S::DRAFT]);
                $req->forceFill(['version' => $req->version + 1]);
            }
            $req->fill(array_intersect_key($data, array_flip(['recipient_partner_id', 'department_lookup_value_id', 'cost_centre', 'document_date',
                'required_by', 'purpose', 'priority', 'reference', 'notes'])));
            $req->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                StockRequestLine::query()->where('stock_request_id', $req->id)->delete();
                foreach (array_values($data['lines']) as $i => $line) {
                    [$uomId, $base, $factor] = $this->lines->toBase($line['item_id'], $line['uom_id'] ?? null, $line['quantity']);
                    StockRequestLine::query()->create([
                        'stock_request_id' => $req->id, 'line_no' => $i + 1, 'item_id' => $line['item_id'], 'item_variant_id' => $line['item_variant_id'] ?? null,
                        'uom_id' => $uomId, 'conversion_factor_snapshot' => $factor, 'requested_quantity' => $line['quantity'],
                        'requested_base_quantity' => $base, 'notes' => $line['notes'] ?? null,
                    ]);
                }
            }
            $this->audit($actor, $id === null ? 'fulfillment.stock_request.created' : 'fulfillment.stock_request.updated', $req, null, ['document_number' => $req->document_number]);

            return $req->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): StockRequest
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $req = $this->lockDocument(StockRequest::class, $id);
            $this->authorizeOwnOr($actor, $req, 'fulfillment.stock_request.submit');
            $this->expectVersion($req, $version);
            $this->transition($req, S::SUBMITTED, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::STOCK_REQUEST, $req->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'fulfillment.stock_request.submitted', $req);
            $this->publish('fulfillment.stock_request.submitted', $req, ['document_number' => $req->document_number, 'warehouse_id' => $req->warehouse_id]);

            return $req;
        });
    }

    /** @param  array<int, array{line_id: string, approved_quantity: string}>|null  $approvedLines */
    public function approve(string $id, UserAccount $actor, ?array $approvedLines = null, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): StockRequest
    {
        return DB::transaction(function () use ($id, $actor, $approvedLines, $version, $comment, $overrideReason) {
            $req = $this->lockDocument(StockRequest::class, $id);
            $this->expectVersion($req, $version);
            $this->assertStatus($req, [S::SUBMITTED]);
            $lines = StockRequestLine::query()->where('stock_request_id', $req->id)->orderBy('line_no')->lockForUpdate()->get()->keyBy('id');
            $approved = $lines->map(fn ($l) => D::exact($l->requested_base_quantity))->all();
            foreach ((array) $approvedLines as $row) {
                $line = $lines->get($row['line_id']) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown request line.');
                if (D::gt($row['approved_quantity'], $line->requested_base_quantity)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Approved quantity cannot exceed the requested quantity.', ['line' => $line->line_no]);
                }
                $approved[$line->id] = D::exact($row['approved_quantity']);
            }
            $value = '0';
            foreach ($lines as $line) {
                $cost = (string) (DB::table('items')->where('id', $line->item_id)->value('current_average_cost') ?? '0');
                $value = D::add($value, D::mul($approved[$line->id], $cost, D::MONEY_SCALE), D::MONEY_SCALE);
            }
            $this->enforceSod(DocumentType::STOCK_REQUEST, DocumentAction::APPROVE, $this->documentActors($req, DocumentType::STOCK_REQUEST), $actor, $req->id, $value, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::STOCK_REQUEST, $req->id, $actor, $value, null, ['warehouse_id' => $req->warehouse_id, 'branch_id' => $req->branch_id], $comment);
            if (! $complete) {
                $req->forceFill(['version' => $req->version + 1])->save();

                return $req;
            }
            foreach ($lines as $line) {
                $line->forceFill(['approved_base_quantity' => $approved[$line->id]])->save();
            }
            $this->transition($req, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            if (ReservationPolicy::from((string) $this->config->get('stock.reservation.policy', ['warehouse' => $req->warehouse_id])) === ReservationPolicy::ON_APPROVAL) {
                $days = $this->config->int('stock.reservation.auto_release_days', ['warehouse' => $req->warehouse_id]);
                foreach ($lines as $line) {
                    if (D::isPositive($approved[$line->id])) {
                        $this->reservations->reserveFromWarehouse(ReservationReferenceType::STOCK_REQUEST, $req->id, $line->id, $this->lines->item($line->item_id, false),
                            $line->item_variant_id, $req->warehouse_id, $approved[$line->id], $actor, allowPartial: true,
                            expiresAt: $days > 0 ? now()->addDays($days)->toImmutable() : null);
                    }
                }
            }
            $this->audit($actor, 'fulfillment.stock_request.approved', $req, null, ['value' => $value], $comment);
            $this->publish('fulfillment.stock_request.approved', $req, ['document_number' => $req->document_number, 'warehouse_id' => $req->warehouse_id]);

            return $req;
        });
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): StockRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $req = $this->lockDocument(StockRequest::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.stock_request.reject', $req->warehouse_id);
            $this->expectVersion($req, $version);
            if (($reason === null || trim($reason) === '') && $this->config->bool('approval.require_comment_on_reject')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A reason is required.', ['field' => 'reason']);
            }
            $this->transition($req, S::REJECTED, ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $this->approvals->record(DocumentType::STOCK_REQUEST, $req->id, $actor, ApprovalAction::REJECTED, $reason);
            $this->audit($actor, 'fulfillment.stock_request.rejected', $req, null, null, $reason);
            $this->publish('fulfillment.stock_request.rejected', $req, ['document_number' => $req->document_number, 'requester_id' => $req->requester_id]);

            return $req;
        });
    }

    /** Cancel; releases outstanding reservations. A partially fulfilled request closes its remainder. */
    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): StockRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $req = $this->lockDocument(StockRequest::class, $id);
            $this->authorizeOwnOr($actor, $req, 'fulfillment.stock_request.cancel', 'fulfillment.stock_request.cancel_own');
            $this->expectVersion($req, $version);
            $this->reservations->releaseForReference(ReservationReferenceType::STOCK_REQUEST, $req->id, $actor, 'Request cancelled');
            $this->transition($req, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'fulfillment.stock_request.cancelled', $req, null, null, $reason);

            return $req;
        });
    }

    /** Re-derive request status after issues post (Chapter 08 §8.6). */
    public function refreshFulfillment(StockRequest $req): void
    {
        $lines = StockRequestLine::query()->where('stock_request_id', $req->id)->get();
        $any = $lines->contains(fn ($l) => D::isPositive($l->fulfilled_base_quantity));
        $all = $lines->every(fn ($l) => D::gte($l->fulfilled_base_quantity, $l->approved_base_quantity));
        $target = $all ? S::FULFILLED : ($any ? S::PARTIALLY_FULFILLED : $req->status);
        if ($target !== $req->status) {
            $this->transition($req, $target);
            if ($target === S::FULFILLED) {
                $this->reservations->releaseForReference(ReservationReferenceType::STOCK_REQUEST, $req->id, null, 'Request fulfilled');
            }
        }
    }

    private function authorizeCreate(UserAccount $actor, string $warehouseId): void
    {
        if ($this->access->can($actor, 'fulfillment.stock_request.create', $warehouseId)) {
            return;
        }
        if (! $this->access->can($actor, 'fulfillment.stock_request.create_own')) {
            throw DomainException::of(ErrorCode::WAREHOUSE_SCOPE_DENIED, 'You may not request stock from this warehouse.');
        }
    }

    private function authorizeOwnOr(UserAccount $actor, StockRequest $req, string $permission, string $ownPermission = 'fulfillment.stock_request.create_own'): void
    {
        if ($req->requester_id === $actor->id && $this->access->can($actor, $ownPermission)) {
            return;
        }
        $this->authorizeWarehouse($actor, $permission, $req->warehouse_id);
    }
}
