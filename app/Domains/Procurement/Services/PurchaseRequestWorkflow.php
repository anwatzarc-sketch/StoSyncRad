<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Services;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Procurement\Enums\PurchaseRequestStatus as S;
use App\Domains\Procurement\Models\PurchaseRequest;
use App\Domains\Procurement\Models\PurchaseRequestLine;
use App\Domains\Procurement\Schemas\PurchaseRequestSchema;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use Illuminate\Support\Facades\DB;

/**
 * Purchase request lifecycle (Chapter 08 §8.2): draft → submitted → approved → (partially_)ordered,
 * with rejection, return-for-correction and cancellation. A request never changes stock.
 */
final class PurchaseRequestWorkflow
{
    use ManagesDocuments;

    public function __construct(private readonly LineQuantities $lines, private readonly ApprovalService $approvals, private readonly PlatformConfig $config) {}

    /** @param  array<string, mixed>  $data  validated against PurchaseRequestSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $this->authorizeWarehouse($actor, 'procurement.purchase_request.create', $data['warehouse_id']);
                $pr = new PurchaseRequest;
                $pr->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::PURCHASE_REQUEST, $data['warehouse_id']),
                    'warehouse_id' => $data['warehouse_id'],
                    'branch_id' => Warehouse::query()->whereKey($data['warehouse_id'])->value('branch_id'),
                    'status' => S::DRAFT, 'requester_id' => $actor->id, 'created_by' => $actor->id,
                    'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $pr = $this->lockDocument(PurchaseRequest::class, $id);
                $this->authorizeWarehouse($actor, 'procurement.purchase_request.update', $pr->warehouse_id);
                $this->expectVersion($pr, $data['version'] ?? null);
                $this->assertStatus($pr, [S::DRAFT]);
                $pr->forceFill(['version' => $pr->version + 1]);
            }
            $pr->fill(array_intersect_key(PurchaseRequestSchema::columns($data), array_flip(['document_date', 'required_by', 'justification', 'priority', 'currency', 'reference', 'notes'])));
            $pr->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                PurchaseRequestLine::query()->where('purchase_request_id', $pr->id)->delete();
                $total = '0';
                foreach (array_values($data['lines']) as $i => $line) {
                    [$uomId, $base, $factor] = $this->lines->toBase($line['item_id'], $line['uom_id'] ?? null, $line['quantity']);
                    PurchaseRequestLine::query()->create([
                        'purchase_request_id' => $pr->id, 'line_no' => $i + 1, 'item_id' => $line['item_id'], 'item_variant_id' => $line['item_variant_id'] ?? null,
                        'quantity' => $line['quantity'], 'uom_id' => $uomId, 'conversion_factor_snapshot' => $factor, 'base_quantity' => $base,
                        'estimated_unit_cost' => $line['estimated_unit_cost'] ?? null, 'suggested_supplier_id' => $line['suggested_supplier_id'] ?? null,
                        'notes' => $line['notes'] ?? null,
                    ]);
                    if (isset($line['estimated_unit_cost'])) {
                        $total = D::add($total, D::mul($line['quantity'], $line['estimated_unit_cost'], D::MONEY_SCALE), D::MONEY_SCALE);
                    }
                }
                $pr->forceFill(['estimated_total' => $total])->save();
            }
            $this->audit($actor, $id === null ? 'procurement.purchase_request.created' : 'procurement.purchase_request.updated', $pr, null, ['document_number' => $pr->document_number]);

            return $pr->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $pr = $this->lockDocument(PurchaseRequest::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_request.submit', $pr->warehouse_id);
            $this->expectVersion($pr, $version);
            if (! $pr->lines()->exists()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add at least one line before submitting.');
            }
            $this->transition($pr, S::SUBMITTED, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::PURCHASE_REQUEST, $pr->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'procurement.purchase_request.submitted', $pr);
            $this->publish('procurement.purchase_request.submitted', $pr, ['warehouse_id' => $pr->warehouse_id, 'document_number' => $pr->document_number]);

            return $pr;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $pr = $this->lockDocument(PurchaseRequest::class, $id);
            $this->expectVersion($pr, $version);
            $this->assertStatus($pr, [S::SUBMITTED]);
            $this->enforceSod(DocumentType::PURCHASE_REQUEST, DocumentAction::APPROVE, $this->documentActors($pr, DocumentType::PURCHASE_REQUEST),
                $actor, $pr->id, $pr->estimated_total, null, $overrideReason);
            $scope = ['warehouse_id' => $pr->warehouse_id, 'branch_id' => $pr->branch_id];
            $complete = $this->approvals->approve(DocumentType::PURCHASE_REQUEST, $pr->id, $actor, (string) $pr->estimated_total, null, $scope, $comment);
            if ($complete) {
                $this->transition($pr, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
                $this->publish('procurement.purchase_request.approved', $pr, ['document_number' => $pr->document_number]);
            } else {
                $pr->forceFill(['version' => $pr->version + 1])->save();
            }
            $this->audit($actor, 'procurement.purchase_request.approval_recorded', $pr, null, ['fully_approved' => $complete], $comment);

            return $pr;
        });
    }

    public function reject(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PurchaseRequest
    {
        return $this->decide($id, $actor, $version, $reason, S::REJECTED, ApprovalAction::REJECTED, 'procurement.purchase_request.reject');
    }

    public function returnForCorrection(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PurchaseRequest
    {
        return $this->decide($id, $actor, $version, $reason, S::DRAFT, ApprovalAction::RETURNED_FOR_CORRECTION, 'procurement.purchase_request.reject');
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $pr = $this->lockDocument(PurchaseRequest::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.purchase_request.cancel', $pr->warehouse_id);
            $this->expectVersion($pr, $version);
            if (D::isPositive((string) $pr->lines()->sum('ordered_base_quantity'))) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Requests that are already on purchase orders cannot be cancelled.');
            }
            $this->transition($pr, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'procurement.purchase_request.cancelled', $pr, null, null, $reason);

            return $pr;
        });
    }

    private function decide(string $id, UserAccount $actor, ?int $version, ?string $reason, S $to, ApprovalAction $action, string $permission): PurchaseRequest
    {
        return DB::transaction(function () use ($id, $actor, $version, $reason, $to, $action, $permission) {
            $pr = $this->lockDocument(PurchaseRequest::class, $id);
            $this->authorizeWarehouse($actor, $permission, $pr->warehouse_id);
            $this->expectVersion($pr, $version);
            $this->assertStatus($pr, [S::SUBMITTED]);
            if (($reason === null || trim($reason) === '') && $this->config->bool('approval.require_comment_on_reject')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A reason is required.', ['field' => 'reason']);
            }
            $extra = $to === S::REJECTED ? ['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $reason] : [];
            $this->transition($pr, $to, $extra);
            $this->approvals->record(DocumentType::PURCHASE_REQUEST, $pr->id, $actor, $action, $reason);
            $this->audit($actor, 'procurement.purchase_request.'.$action->value, $pr, null, null, $reason);
            $this->publish('procurement.purchase_request.'.$action->value, $pr, ['document_number' => $pr->document_number]);

            return $pr;
        });
    }
}
