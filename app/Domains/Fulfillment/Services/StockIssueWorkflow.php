<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Services;

use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\Fulfillment\Enums\PartialFulfillmentPolicy;
use App\Domains\Fulfillment\Enums\StockIssueStatus as S;
use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\Fulfillment\Models\StockIssue;
use App\Domains\Fulfillment\Models\StockIssueLine;
use App\Domains\Fulfillment\Models\StockRequest;
use App\Domains\Fulfillment\Models\StockRequestLine;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Documents\LineQuantities;
use App\Support\Documents\ManagesDocuments;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Stock issues (Chapter 08 §8.6–8.7). Posting moves the picked positions to the internal recipient
 * (customer virtual location) or to production, consuming the request's reservations.
 */
final class StockIssueWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly PickedStock $picked,
        private readonly StockRequestWorkflow $requests,
        private readonly CategoryPolicyResolver $policies,
    ) {}

    /** @param  array<string, mixed>  $data  validated against StockIssueSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): StockIssue
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $request = null;
                if (! empty($data['stock_request_id'])) {
                    $request = StockRequest::query()->findOrFail($data['stock_request_id']);
                    if (! in_array($request->status, [StockRequestStatus::APPROVED, StockRequestStatus::PARTIALLY_FULFILLED], true)) {
                        throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Request {$request->document_number} is not approved for issue.");
                    }
                    $warehouseId = $request->warehouse_id;
                    $this->authorizeWarehouse($actor, 'fulfillment.stock_issue.create', $warehouseId);
                } elseif (! empty($data['is_production_issue'])) {
                    $warehouseId = $data['warehouse_id'] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Production issues need a warehouse.', ['field' => 'warehouse_id']);
                    if (! $this->config->feature('feature.production_store')) {
                        throw DomainException::featureDisabled('feature.production_store');
                    }
                    $this->authorizeWarehouse($actor, 'fulfillment.production.issue', $warehouseId);
                } else {
                    $warehouseId = $data['warehouse_id'] ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Direct issues need a warehouse.', ['field' => 'warehouse_id']);
                    $this->authorizeWarehouse($actor, 'fulfillment.stock_issue.direct_issue', $warehouseId);
                    if (! $this->config->bool('fulfillment.issue.direct_issue.enabled', ['warehouse' => $warehouseId])) {
                        throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Direct issues without a request are disabled for this warehouse.');
                    }
                }
                $issue = new StockIssue;
                $issue->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::STOCK_ISSUE, $warehouseId),
                    'warehouse_id' => $warehouseId, 'branch_id' => Warehouse::query()->whereKey($warehouseId)->value('branch_id'),
                    'stock_request_id' => $request?->id, 'recipient_partner_id' => $data['recipient_partner_id'] ?? $request?->recipient_partner_id,
                    'is_direct' => $request === null, 'is_production_issue' => (bool) ($data['is_production_issue'] ?? false),
                    'status' => S::DRAFT, 'created_by' => $actor->id, 'document_date' => $data['document_date'] ?? now()->toDateString(),
                ]);
            } else {
                $issue = $this->lockDocument(StockIssue::class, $id);
                $this->authorizeWarehouse($actor, 'fulfillment.stock_issue.update', $issue->warehouse_id);
                $this->expectVersion($issue, $data['version'] ?? null);
                $this->assertStatus($issue, [S::DRAFT]);
                $issue->forceFill(['version' => $issue->version + 1]);
            }
            $issue->fill(array_intersect_key($data, array_flip(['recipient_partner_id', 'recipient_name', 'document_date', 'reference', 'notes'])));
            $issue->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                $this->replaceLines($issue, $data['lines']);
            }
            $this->audit($actor, $id === null ? 'fulfillment.stock_issue.created' : 'fulfillment.stock_issue.updated', $issue, null, ['document_number' => $issue->document_number]);

            return $issue->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): StockIssue
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $issue = $this->lockDocument(StockIssue::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.stock_issue.submit', $issue->warehouse_id);
            $this->expectVersion($issue, $version);
            $this->transition($issue, $this->requiresApproval($issue) ? S::PENDING_APPROVAL : S::APPROVED, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::STOCK_ISSUE, $issue->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'fulfillment.stock_issue.submitted', $issue);

            return $issue;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): StockIssue
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $issue = $this->lockDocument(StockIssue::class, $id);
            $this->expectVersion($issue, $version);
            $this->assertStatus($issue, [S::PENDING_APPROVAL]);
            $value = $this->value($issue);
            $this->enforceSod(DocumentType::STOCK_ISSUE, DocumentAction::APPROVE, $this->documentActors($issue, DocumentType::STOCK_ISSUE), $actor, $issue->id, $value, null, $overrideReason);
            $complete = $this->approvals->approve(DocumentType::STOCK_ISSUE, $issue->id, $actor, $value, null,
                ['warehouse_id' => $issue->warehouse_id, 'branch_id' => $issue->branch_id], $comment);
            if ($complete) {
                $this->transition($issue, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                $issue->forceFill(['version' => $issue->version + 1])->save();
            }
            $this->audit($actor, 'fulfillment.stock_issue.approval_recorded', $issue, null, ['fully_approved' => $complete], $comment);

            return $issue;
        });
    }

    /** @param  array<string, mixed>  $options  version, override_reason */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'fulfillment.stock_issue.post', $idempotencyKey, ['stock_issue_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $issue = $this->lockDocument(StockIssue::class, $id);
                $this->authorizeWarehouse($actor, $issue->is_production_issue ? 'fulfillment.production.issue' : 'fulfillment.stock_issue.post', $issue->warehouse_id);
                $this->expectVersion($issue, $options['version'] ?? null);
                if ($issue->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Stock issue', $issue->document_number);
                }
                $this->assertStatus($issue, $this->requiresApproval($issue) ? [S::APPROVED] : [S::DRAFT, S::APPROVED]);
                $warehouse = Warehouse::query()->findOrFail($issue->warehouse_id);
                $warehouse->assertOperational();
                $warehouse->assertCapability($issue->is_production_issue ? WarehouseCapability::ISSUE_TO_PRODUCTION : WarehouseCapability::ISSUE_INTERNAL);

                $request = $issue->stock_request_id ? $this->lockDocument(StockRequest::class, $issue->stock_request_id) : null;
                if ($request !== null && ! in_array($request->status, [StockRequestStatus::APPROVED, StockRequestStatus::PARTIALLY_FULFILLED], true)) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Request {$request->document_number} is {$request->status->value}; it can no longer be issued against.");
                }
                $requestLines = $request ? StockRequestLine::query()->where('stock_request_id', $request->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();

                $type = $issue->is_production_issue ? MovementType::PRODUCTION_ISSUE : MovementType::ISSUE;
                $destination = $this->locations->virtual($issue->is_production_issue ? InventoryLocationType::PRODUCTION : InventoryLocationType::CUSTOMER)->id;
                $issueLines = StockIssueLine::query()->where('stock_issue_id', $issue->id)->orderBy('line_no')->get();
                if ($issueLines->isEmpty()) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The issue has no lines.');
                }
                $issued = [];
                $movementLines = [];
                foreach ($issueLines as $line) {
                    $qty = D::exact($line->base_quantity);
                    $consume = [];
                    if ($line->stock_request_line_id !== null) {
                        $rl = $requestLines->get($line->stock_request_line_id);
                        $remaining = D::sub($rl->approved_base_quantity, D::add($rl->fulfilled_base_quantity, $issued[$rl->id] ?? '0'));
                        if (D::gt($qty, $remaining)) {
                            throw DomainException::of(ErrorCode::QUANTITY_EXCEEDS_OUTSTANDING, "Line {$line->line_no} exceeds the approved request quantity.",
                                ['line' => $line->line_no, 'outstanding' => D::trim($remaining)]);
                        }
                        $issued[$rl->id] = D::add($issued[$rl->id] ?? '0', $qty);
                        $consume = $this->picked->secure(ReservationReferenceType::STOCK_REQUEST, $request->id, $rl->id, $line->item_id, $line->item_variant_id,
                            $issue->warehouse_id, ['storage_location_id' => $line->storage_location_id, 'lot_id' => $line->inventory_lot_id,
                                'serial_id' => $line->item_serial_id, 'quantity' => $qty], $actor);
                    }
                    $movementLines[] = new MovementLine($line->item_id, $line->item_variant_id, $this->picked->location($line->storage_location_id), StockCondition::AVAILABLE,
                        $destination, StockCondition::AVAILABLE, $qty, lotId: $line->inventory_lot_id, serialId: $line->item_serial_id,
                        documentUomId: $line->uom_id, documentQuantity: $line->quantity, conversionFactor: $line->conversion_factor_snapshot,
                        sourceLineType: 'stock_issue_line', sourceLineId: $line->id, consumeReservations: $consume);
                }
                if ($request !== null && PartialFulfillmentPolicy::from((string) $this->config->get('fulfillment.request.partial_fulfillment', ['warehouse' => $issue->warehouse_id])) === PartialFulfillmentPolicy::FORBID) {
                    foreach ($requestLines as $rl) {
                        if (! D::eq(D::add($rl->fulfilled_base_quantity, $issued[$rl->id] ?? '0'), $rl->approved_base_quantity)) {
                            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Partial fulfillment is not allowed for this warehouse; issue the full approved quantity.');
                        }
                    }
                }
                $this->enforceSod(DocumentType::STOCK_ISSUE, DocumentAction::POST, $this->documentActors($issue, DocumentType::STOCK_ISSUE), $actor, $issue->id,
                    null, null, $options['override_reason'] ?? null);

                $movement = $this->poster->post(new MovementRequest($type, DocumentType::STOCK_ISSUE, $issue->id, $issue->document_number, $issue->id, $movementLines,
                    businessDate: $issue->document_date?->toDateString(), warehouseId: $issue->warehouse_id, partnerId: $issue->recipient_partner_id), $ctx);

                $costs = DB::table('stock_movement_lines')->where('stock_movement_id', $movement->id)->pluck('unit_cost_snapshot', 'source_line_id');
                foreach ($issueLines as $line) {
                    $line->forceFill(['unit_cost_snapshot' => $costs[$line->id] ?? null])->save();
                }
                foreach ($issued as $rlId => $qty) {
                    $rl = $requestLines[$rlId];
                    $rl->forceFill(['fulfilled_base_quantity' => D::add($rl->fulfilled_base_quantity, $qty)])->save();
                }
                $issue->forceFill(['status' => S::POSTED, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_movement_id' => $movement->id, 'version' => $issue->version + 1])->save();
                if ($request !== null) {
                    $this->requests->refreshFulfillment($request);
                }
                $ctx->audit('fulfillment.stock_issue.posted', 'stock_issues', $issue->id, null, ['document_number' => $issue->document_number, 'movement_number' => $movement->movement_number]);
                $ctx->publish('fulfillment.stock_issue.posted', 'stock_issues', $issue->id, ['document_number' => $issue->document_number, 'warehouse_id' => $issue->warehouse_id]);

                return new PostingOutcome(['stock_issue_id' => $issue->id, 'document_number' => $issue->document_number, 'status' => S::POSTED->value,
                    'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, DocumentType::STOCK_ISSUE->value, $issue->id);
            }, failureAudit: ['fulfillment.stock_issue.post_failed', 'stock_issues', $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): StockIssue
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $issue = $this->lockDocument(StockIssue::class, $id);
            $this->authorizeWarehouse($actor, 'fulfillment.stock_issue.cancel', $issue->warehouse_id);
            $this->expectVersion($issue, $version);
            $this->transition($issue, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'fulfillment.stock_issue.cancelled', $issue, null, null, $reason);

            return $issue;
        });
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function replaceLines(StockIssue $issue, array $lines): void
    {
        StockIssueLine::query()->where('stock_issue_id', $issue->id)->delete();
        $requestLines = $issue->stock_request_id ? StockRequestLine::query()->where('stock_request_id', $issue->stock_request_id)->get()->keyBy('id') : collect();
        $no = 0;
        foreach (array_values($lines) as $i => $line) {
            if ($issue->stock_request_id !== null) {
                $rl = $requestLines->get($line['stock_request_line_id'] ?? '') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                    'Each line must reference a line of the request.', ['field' => "lines.$i.stock_request_line_id"]);
                if ($rl->item_id !== $line['item_id']) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The item does not match the request line.', ['field' => "lines.$i.item_id"]);
                }
            }
            $item = $this->lines->item($line['item_id'], false);
            [$uomId, $base, $factor] = $this->lines->toBase($item->id, $line['uom_id'] ?? null, $line['quantity']);
            $rows = $this->picked->expand($item, $issue->warehouse_id, $line['storage_location_id'], $line['inventory_lot_id'] ?? null,
                $line['serial_numbers'] ?? null, $base, "lines.$i");
            foreach ($rows as $row) {
                $perSerial = $row['serial_id'] !== null;
                StockIssueLine::query()->create([
                    'stock_issue_id' => $issue->id, 'line_no' => ++$no, 'stock_request_line_id' => $line['stock_request_line_id'] ?? null,
                    'item_id' => $item->id, 'item_variant_id' => $line['item_variant_id'] ?? null, 'storage_location_id' => $row['storage_location_id'],
                    'inventory_lot_id' => $row['lot_id'], 'item_serial_id' => $row['serial_id'],
                    'uom_id' => $perSerial ? $item->base_uom_id : $uomId, 'conversion_factor_snapshot' => $perSerial ? '1' : $factor,
                    'quantity' => $perSerial ? '1' : $line['quantity'], 'base_quantity' => $row['quantity'],
                    'short_pick_reason' => $line['short_pick_reason'] ?? null, 'notes' => $line['notes'] ?? null,
                ]);
            }
        }
    }

    private function requiresApproval(StockIssue $issue): bool
    {
        foreach (StockIssueLine::query()->where('stock_issue_id', $issue->id)->distinct()->pluck('item_id') as $itemId) {
            if ($this->policies->requiresApprovalForIssue($this->lines->item($itemId, false))) {
                return true;
            }
        }

        return false;
    }

    private function value(StockIssue $issue): string
    {
        return D::round((string) DB::table('stock_issue_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')->where('l.stock_issue_id', $issue->id)
            ->selectRaw('COALESCE(SUM(l.base_quantity * i.current_average_cost), 0) AS v')->value('v'), D::MONEY_SCALE);
    }
}
