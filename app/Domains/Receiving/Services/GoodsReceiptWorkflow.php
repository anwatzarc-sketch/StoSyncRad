<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Services;

use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\QcOutcome;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\QualityCheck;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Inventory\Services\LotService;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Models\PurchaseOrder;
use App\Domains\Procurement\Models\PurchaseOrderLine;
use App\Domains\Procurement\Services\PurchaseOrderWorkflow;
use App\Domains\Receiving\Enums\GoodsReceiptStatus as S;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Domains\Receiving\Models\GoodsReceipt;
use App\Domains\Receiving\Models\GoodsReceiptLine;
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
 * Goods receipt lifecycle and posting (Chapter 08 §8.3).
 */
final class GoodsReceiptWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly PlatformConfig $config,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
        private readonly LotService $lots,
        private readonly CategoryPolicyResolver $policies,
        private readonly PurchaseOrderWorkflow $orders,
        private readonly PutawayService $putaway,
        private readonly QualityCheckEvaluator $qc,
        private readonly Access $access,
    ) {}

    /** @param  array<string, mixed>  $data  validated against GoodsReceiptSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                [$warehouseId, $supplierId, $direct] = $this->resolveSource($data, $actor);
                $gr = new GoodsReceipt;
                $gr->forceFill([
                    'document_number' => $this->nextNumber(DocumentType::GOODS_RECEIPT, $warehouseId),
                    'warehouse_id' => $warehouseId, 'branch_id' => Warehouse::query()->whereKey($warehouseId)->value('branch_id'),
                    'supplier_id' => $supplierId, 'purchase_order_id' => $data['purchase_order_id'] ?? null, 'is_direct' => $direct,
                    'status' => S::DRAFT, 'created_by' => $actor->id, 'received_by' => $actor->id,
                    'document_date' => $data['document_date'] ?? now()->toDateString(), 'received_at' => $data['received_at'] ?? now(),
                ]);
            } else {
                $gr = $this->lockDocument(GoodsReceipt::class, $id);
                $this->authorizeWarehouse($actor, 'receiving.goods_receipt.update', $gr->warehouse_id);
                $this->expectVersion($gr, $data['version'] ?? null);
                $this->assertStatus($gr, [S::DRAFT, S::IN_INSPECTION]);
                $gr->forceFill(['version' => $gr->version + 1]);
            }
            $gr->fill(array_intersect_key($data, array_flip(['document_date', 'received_at', 'supplier_delivery_reference', 'carrier_reference',
                'vehicle_reference', 'reference', 'notes', 'advance_shipment_notice_id'])));
            $gr->forceFill(['updated_by' => $actor->id])->save();

            if (array_key_exists('lines', $data)) {
                $this->writeLines($gr, $data['lines'], $actor);
            }
            $this->audit($actor, $id === null ? 'receiving.goods_receipt.created' : 'receiving.goods_receipt.updated', $gr, null, ['document_number' => $gr->document_number]);

            return $gr->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): GoodsReceipt
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $gr = $this->lockDocument(GoodsReceipt::class, $id);
            $this->authorizeWarehouse($actor, 'receiving.goods_receipt.submit', $gr->warehouse_id);
            $this->expectVersion($gr, $version);
            $requiresApproval = $this->config->bool('receiving.require_approval_before_posting', ['warehouse' => $gr->warehouse_id]);
            $this->transition($gr, $requiresApproval ? S::PENDING_APPROVAL : S::APPROVED,
                ['submitted_by' => $actor->id, 'submitted_at' => now()] + ($requiresApproval ? [] : ['approved_by' => null]));
            $this->approvals->record(DocumentType::GOODS_RECEIPT, $gr->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'receiving.goods_receipt.submitted', $gr);

            return $gr;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null): GoodsReceipt
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment) {
            $gr = $this->lockDocument(GoodsReceipt::class, $id);
            $this->expectVersion($gr, $version);
            $this->assertStatus($gr, [S::PENDING_APPROVAL]);
            $complete = $this->approvals->approve(DocumentType::GOODS_RECEIPT, $gr->id, $actor, $this->receiptValue($gr), null,
                ['warehouse_id' => $gr->warehouse_id, 'branch_id' => $gr->branch_id], $comment);
            if ($complete) {
                $this->transition($gr, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            }
            $this->audit($actor, 'receiving.goods_receipt.approval_recorded', $gr, null, ['fully_approved' => $complete], $comment);

            return $gr;
        });
    }

    /**
     * Record a quality inspection for one receipt line (Chapter 07 §7.7).
     *
     * @param  array<string, mixed>  $data  validated against QualityCheckSchema
     */
    public function recordQualityCheck(string $id, string $lineId, array $data, UserAccount $actor): QualityCheck
    {
        return DB::transaction(function () use ($id, $lineId, $data, $actor) {
            $gr = $this->lockDocument(GoodsReceipt::class, $id);
            $this->authorizeWarehouse($actor, 'receiving.qc.inspect', $gr->warehouse_id);
            $this->assertStatus($gr, [S::DRAFT, S::IN_INSPECTION, S::PENDING_APPROVAL, S::APPROVED]);
            /** @var GoodsReceiptLine $line */
            $line = GoodsReceiptLine::query()->where('goods_receipt_id', $gr->id)->lockForUpdate()->findOrFail($lineId);
            $item = Item::query()->findOrFail($line->item_id);
            $check = $this->qc->record(DocumentType::GOODS_RECEIPT, $gr->id, $line->id, $item, $gr->warehouse_id, null,
                (array) $data['answers'], $data['inspected_quantity'] ?? $line->accepted_quantity, $data['failed_quantity'] ?? '0', $data['notes'] ?? null, $actor);
            $line->forceFill(['quality_check_id' => $check->id])->save();
            if ($gr->status === S::DRAFT) {
                $this->transition($gr, S::IN_INSPECTION);
            }
            $this->audit($actor, 'receiving.qc.recorded', $gr, null, ['line_id' => $line->id, 'outcome' => $check->outcome->value]);

            return $check;
        });
    }

    /**
     * Post the receipt: stock increases only here (Chapter 08 §8.3, Chapter 09 §9.2).
     *
     * @param  array<string, mixed>  $options  over_receipt_override, override_reason, version
     */
    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'receiving.goods_receipt.post', $idempotencyKey, ['goods_receipt_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $gr = $this->lockDocument(GoodsReceipt::class, $id);
                $this->authorizeWarehouse($actor, 'receiving.goods_receipt.post', $gr->warehouse_id);
                $this->expectVersion($gr, $options['version'] ?? null);
                if ($gr->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Goods receipt', $gr->document_number);
                }
                $requiresApproval = $this->config->bool('receiving.require_approval_before_posting', ['warehouse' => $gr->warehouse_id]);
                $this->assertStatus($gr, $requiresApproval ? [S::APPROVED] : [S::DRAFT, S::IN_INSPECTION, S::APPROVED]);
                $this->enforceSod(DocumentType::GOODS_RECEIPT, DocumentAction::POST, $this->documentActors($gr, DocumentType::GOODS_RECEIPT), $actor, $gr->id,
                    null, null, $options['override_reason'] ?? null);

                /** @var Warehouse $warehouse */
                $warehouse = Warehouse::query()->findOrFail($gr->warehouse_id);
                $warehouse->assertOperational(inbound: true);
                $warehouse->assertCapability(WarehouseCapability::RECEIVE_FROM_SUPPLIER);

                $po = null;
                $poLines = collect();
                if ($gr->purchase_order_id !== null) {
                    /** @var PurchaseOrder $po */
                    $po = $this->lockDocument(PurchaseOrder::class, $gr->purchase_order_id);
                    if (! in_array($po->status, [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::SENT, PurchaseOrderStatus::PARTIALLY_RECEIVED], true)) {
                        throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Purchase order {$po->document_number} is {$po->status->value} and cannot be received against.");
                    }
                    $poLines = PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                }

                $supplierLocation = $this->locations->virtual(InventoryLocationType::SUPPLIER)->id;
                $grLines = GoodsReceiptLine::query()->where('goods_receipt_id', $gr->id)->orderBy('line_no')->lockForUpdate()->get();
                if ($grLines->isEmpty()) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The receipt has no lines.');
                }

                $movementLines = [];
                $putaways = [];
                foreach ($grLines as $line) {
                    $item = Item::query()->findOrFail($line->item_id);
                    $accepted = D::exact($line->accepted_base_quantity);
                    $rejected = D::exact($line->rejected_base_quantity);

                    if ($po !== null) {
                        $poLine = $poLines->get($line->purchase_order_line_id) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                            "Line {$line->line_no} is not on purchase order {$po->document_number}.");
                        $this->assertWithinTolerance($poLine, $item, $gr->warehouse_id, $accepted, $line->line_no, $actor, $options);
                        $poLine->forceFill([
                            'received_base_quantity' => D::add($poLine->received_base_quantity, $accepted),
                            'rejected_base_quantity' => D::add($poLine->rejected_base_quantity, $rejected),
                        ])->save();
                        $unitCost = $this->baseUnitCost($poLine, $po);
                    } else {
                        $unitCost = D::exact($line->unit_cost, D::COST_SCALE);
                    }
                    if (! D::isPositive($accepted)) {
                        continue;
                    }

                    $condition = $this->resultingCondition($line, $item);
                    $storage = $this->destination($line, $warehouse, $condition);
                    $location = $this->locations->forStorageLocation($storage->id)->id;

                    $lotId = null;
                    if ($item->tracking_mode === TrackingMode::LOT || ($item->tracking_mode === TrackingMode::SERIAL && $line->lot_number)) {
                        if ($item->tracking_mode === TrackingMode::LOT && ! $line->lot_number && $item->lot_number_policy->value === 'supplier_provided') {
                            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "Line {$line->line_no}: enter the supplier lot number.");
                        }
                        $lot = $this->lots->resolveForReceipt($item, $line->item_variant_id, $line->lot_number, $gr->supplier_id,
                            $line->manufacture_date?->toDateString(), $line->expiry_date?->toDateString(), DocumentType::GOODS_RECEIPT->value, $gr->id, $actor->id);
                        if ($condition === StockCondition::AVAILABLE && $this->policies->qcPolicy($item) !== QcPolicy::NONE) {
                            $lot->forceFill(['qc_status' => 'released', 'qc_released_by' => $actor->id, 'qc_released_at' => now()])->save();
                        }
                        $lotId = $lot->id;
                    }

                    if ($item->tracking_mode === TrackingMode::SERIAL) {
                        $serials = array_values(array_unique((array) ($line->serial_numbers ?? [])));
                        if (! D::isWhole($accepted) || count($serials) !== (int) $accepted) {
                            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL,
                                "Line {$line->line_no}: provide exactly ".D::trim($accepted).' unique serial numbers.', ['line' => $line->line_no]);
                        }
                        foreach ($serials as $serialNumber) {
                            $serial = $this->lots->resolveSerial($item, $line->item_variant_id, (string) $serialNumber, $lotId);
                            $movementLines[] = new MovementLine($item->id, $line->item_variant_id, $supplierLocation, StockCondition::AVAILABLE, $location, $condition, '1',
                                lotId: $lotId, serialId: $serial->id, unitCost: $unitCost, documentUomId: $line->uom_id, documentQuantity: '1',
                                conversionFactor: $line->conversion_factor_snapshot, sourceLineType: 'goods_receipt_line', sourceLineId: $line->id);
                        }
                    } else {
                        $movementLines[] = new MovementLine($item->id, $line->item_variant_id, $supplierLocation, StockCondition::AVAILABLE, $location, $condition, $accepted,
                            lotId: $lotId, unitCost: $unitCost, documentUomId: $line->uom_id, documentQuantity: $line->accepted_quantity,
                            conversionFactor: $line->conversion_factor_snapshot, sourceLineType: 'goods_receipt_line', sourceLineId: $line->id);
                    }
                    $line->forceFill(['inventory_lot_id' => $lotId, 'resulting_condition' => $condition, 'destination_storage_location_id' => $storage->id, 'unit_cost' => $unitCost])->save();

                    if ($storage->usage === StorageLocationUsage::RECEIVING && $this->config->bool('receiving.putaway.enabled', ['warehouse' => $warehouse->id])) {
                        $putaways[] = [$line, $item, $lotId, $storage, $condition, $accepted];
                    }
                    DB::update('UPDATE items SET last_purchase_cost = ?, last_purchased_at = ? WHERE id = ?', [$unitCost, now(), $item->id]);
                }
                if ($movementLines === []) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Nothing to receive: every line is fully rejected.');
                }

                $movement = $this->poster->post(new MovementRequest(MovementType::RECEIPT, DocumentType::GOODS_RECEIPT, $gr->id, $gr->document_number, $gr->id,
                    $movementLines, businessDate: $gr->document_date?->toDateString(), warehouseId: $gr->warehouse_id, partnerId: $gr->supplier_id), $ctx);

                foreach ($putaways as [$line, $item, $lotId, $storage, $condition, $qty]) {
                    $this->putaway->createTask($warehouse, $gr->id, null, $item, $line->item_variant_id, $lotId, null, $storage->id, $condition, $qty);
                }
                $gr->forceFill(['status' => S::POSTED, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_movement_id' => $movement->id, 'version' => $gr->version + 1])->save();
                if ($po !== null) {
                    $this->orders->refreshReceiptStatus($po);
                }

                $ctx->audit('receiving.goods_receipt.posted', 'goods_receipts', $gr->id, null,
                    ['document_number' => $gr->document_number, 'movement_number' => $movement->movement_number, 'lines' => count($movementLines)],
                    $options['override_reason'] ?? null);
                $ctx->publish('receiving.goods_receipt.posted', 'goods_receipts', $gr->id,
                    ['document_number' => $gr->document_number, 'warehouse_id' => $gr->warehouse_id, 'purchase_order_id' => $gr->purchase_order_id]);
                if ($grLines->contains(fn ($l) => D::isPositive($l->rejected_base_quantity))) {
                    $ctx->publish('receiving.goods_receipt.rejections_recorded', 'goods_receipts', $gr->id, ['document_number' => $gr->document_number]);
                }

                return new PostingOutcome(['goods_receipt_id' => $gr->id, 'document_number' => $gr->document_number, 'status' => S::POSTED->value,
                    'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, DocumentType::GOODS_RECEIPT->value, $gr->id);
            },
            failureAudit: ['receiving.goods_receipt.post_failed', 'goods_receipts', $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): GoodsReceipt
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $gr = $this->lockDocument(GoodsReceipt::class, $id);
            $this->authorizeWarehouse($actor, 'receiving.goods_receipt.cancel', $gr->warehouse_id);
            $this->expectVersion($gr, $version);
            $this->transition($gr, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'receiving.goods_receipt.cancelled', $gr, null, null, $reason);

            return $gr;
        });
    }

    // ---------------------------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: bool} [warehouseId, supplierId, direct] */
    private function resolveSource(array $data, UserAccount $actor): array
    {
        if (! empty($data['purchase_order_id'])) {
            $po = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
            $this->authorizeWarehouse($actor, 'receiving.goods_receipt.create', $po->warehouse_id);
            if (! in_array($po->status, [PurchaseOrderStatus::APPROVED, PurchaseOrderStatus::SENT, PurchaseOrderStatus::PARTIALLY_RECEIVED], true)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Purchase order {$po->document_number} is not open for receiving.");
            }

            return [$po->warehouse_id, $po->supplier_id, false];
        }
        if (empty($data['warehouse_id']) || empty($data['supplier_id'])) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Direct receipts need a warehouse and a supplier.', ['fields' => ['warehouse_id', 'supplier_id']]);
        }
        $this->authorizeWarehouse($actor, 'receiving.goods_receipt.direct_receipt', $data['warehouse_id']);
        if (! $this->config->bool('receiving.direct_receipt.enabled', ['warehouse' => $data['warehouse_id']])) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Direct receipts without a purchase order are disabled for this warehouse.');
        }

        return [$data['warehouse_id'], $data['supplier_id'], true];
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function writeLines(GoodsReceipt $gr, array $lines, UserAccount $actor): void
    {
        GoodsReceiptLine::query()->where('goods_receipt_id', $gr->id)->delete();
        $poLines = $gr->purchase_order_id ? PurchaseOrderLine::query()->where('purchase_order_id', $gr->purchase_order_id)->get()->keyBy('id') : collect();
        foreach (array_values($lines) as $i => $line) {
            $poLine = null;
            if ($gr->purchase_order_id !== null) {
                $poLine = $poLines->get($line['purchase_order_line_id'] ?? '') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                    "Line ".($i + 1).' must reference a line of the purchase order.', ['field' => "lines.$i.purchase_order_line_id"]);
                if ($poLine->item_id !== $line['item_id']) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The item does not match the purchase order line.', ['field' => "lines.$i.item_id"]);
                }
            } elseif (! isset($line['unit_cost'])) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Direct receipt lines need a unit cost.', ['field' => "lines.$i.unit_cost"]);
            }
            $uomId = $line['uom_id'] ?? $poLine?->uom_id;
            $rejected = $line['rejected_quantity'] ?? '0';
            if (D::gt($rejected, $line['received_quantity'])) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Rejected quantity cannot exceed the received quantity.', ['field' => "lines.$i.rejected_quantity"]);
            }
            $accepted = D::sub($line['received_quantity'], $rejected);
            if (($line['receiving_condition'] ?? null) === ReceivingCondition::REJECTED->value) {
                [$accepted, $rejected] = ['0', D::exact($line['received_quantity'])];
            }
            [$uomId, $acceptedBase, $factor] = $this->lines->toBase($line['item_id'], $uomId, D::isPositive($accepted) ? $accepted : $line['received_quantity']);
            if (! D::isPositive($accepted)) {
                $acceptedBase = '0';
            }
            $rejectedBase = '0';
            if (D::isPositive($rejected)) {
                [, $rejectedBase] = $this->lines->toBase($line['item_id'], $uomId, $rejected);
            }
            if (! empty($line['destination_storage_location_id'])) {
                $sl = StorageLocation::query()->findOrFail($line['destination_storage_location_id']);
                if ($sl->warehouse_id !== $gr->warehouse_id) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The destination location belongs to another warehouse.', ['field' => "lines.$i.destination_storage_location_id"]);
                }
            }
            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $gr->id, 'line_no' => $i + 1, 'purchase_order_line_id' => $poLine?->id, 'item_id' => $line['item_id'],
                'item_variant_id' => $line['item_variant_id'] ?? $poLine?->item_variant_id,
                'expected_quantity' => $poLine ? D::div(D::sub(D::sub($poLine->base_quantity, $poLine->received_base_quantity), $poLine->cancelled_base_quantity), $factor, 6) : null,
                'received_quantity' => $line['received_quantity'], 'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected,
                'uom_id' => $uomId, 'conversion_factor_snapshot' => $factor, 'accepted_base_quantity' => $acceptedBase, 'rejected_base_quantity' => $rejectedBase,
                'lot_number' => $line['lot_number'] ?? null, 'manufacture_date' => $line['manufacture_date'] ?? null, 'expiry_date' => $line['expiry_date'] ?? null,
                'serial_numbers' => $line['serial_numbers'] ?? null, 'destination_storage_location_id' => $line['destination_storage_location_id'] ?? null,
                'receiving_condition' => $line['receiving_condition'] ?? ReceivingCondition::GOOD->value,
                'rejection_reason_lookup_value_id' => $line['rejection_reason_lookup_value_id'] ?? null, 'inspection_notes' => $line['inspection_notes'] ?? null,
                'unit_cost' => $line['unit_cost'] ?? '0',
            ]);
        }
    }

    /** Over-receipt tolerance (receiving.over_receipt.*), overridable with permission + reason. */
    private function assertWithinTolerance(PurchaseOrderLine $poLine, Item $item, string $warehouseId, string $accepted, int $lineNo, UserAccount $actor, array $options): void
    {
        $scope = ['warehouse' => $warehouseId, 'category' => $item->category_id, 'item' => $item->id];
        $pct = (string) $this->config->get('receiving.over_receipt.tolerance_percent', $scope);
        $abs = (string) $this->config->get('receiving.over_receipt.tolerance_absolute', $scope);
        $limit = D::add(D::add($poLine->base_quantity, D::mul($poLine->base_quantity, D::div($pct, '100', 8), D::QTY_SCALE)), $abs);
        $limit = D::sub($limit, $poLine->cancelled_base_quantity);
        $after = D::add($poLine->received_base_quantity, $accepted);
        if (D::gt($after, $limit)) {
            $override = ($options['over_receipt_override'] ?? false) === true && $this->access->can($actor, 'receiving.over_receipt.override', $warehouseId)
                && ! empty($options['override_reason']);
            if (! $override) {
                throw DomainException::of(ErrorCode::OVER_RECEIPT, "Line $lineNo exceeds the ordered quantity beyond the allowed tolerance.",
                    ['line' => $lineNo, 'ordered' => $poLine->base_quantity, 'already_received' => $poLine->received_base_quantity, 'accepted' => $accepted, 'limit' => $limit],
                    'Reduce the quantity or ask an authorized user to override with a reason.');
            }
        }
    }

    private function baseUnitCost(PurchaseOrderLine $poLine, PurchaseOrder $po): string
    {
        $net = D::sub($poLine->unit_price, D::mul($poLine->unit_price, D::div($poLine->discount_percent, '100', 10), 10), 10);
        $perBase = D::div($net, $poLine->conversion_factor_snapshot, 10);

        return D::mul($perBase, $po->exchange_rate ?? '1', D::COST_SCALE);
    }

    private function resultingCondition(GoodsReceiptLine $line, Item $item): StockCondition
    {
        $policy = $this->policies->qcPolicy($item);

        return match ($line->receiving_condition) {
            ReceivingCondition::DAMAGED => StockCondition::DAMAGED,
            ReceivingCondition::QUARANTINE => StockCondition::QUARANTINE,
            default => match ($policy) {
                QcPolicy::QUARANTINE_ON_RECEIPT => StockCondition::QUARANTINE,
                QcPolicy::INSPECT_ON_RECEIPT => $this->hasPassingCheck($line) ? StockCondition::AVAILABLE : StockCondition::QUARANTINE,
                default => StockCondition::AVAILABLE,
            },
        };
    }

    private function hasPassingCheck(GoodsReceiptLine $line): bool
    {
        return $line->quality_check_id !== null && in_array(
            QualityCheck::query()->whereKey($line->quality_check_id)->value('outcome'),
            [QcOutcome::PASS->value, QcOutcome::CONDITIONAL->value], true);
    }

    private function destination(GoodsReceiptLine $line, Warehouse $warehouse, StockCondition $condition): StorageLocation
    {
        $usage = match ($condition) {
            StockCondition::QUARANTINE => StorageLocationUsage::QUARANTINE,
            StockCondition::DAMAGED => StorageLocationUsage::DAMAGED,
            default => StorageLocationUsage::RECEIVING,
        };
        $id = $condition === StockCondition::AVAILABLE && $line->destination_storage_location_id !== null
            ? $line->destination_storage_location_id
            : ($warehouse->defaultLocationId($usage) ?? $warehouse->default_receiving_location_id);
        $location = StorageLocation::query()->findOrFail($id);
        if ($location->warehouse_id !== $warehouse->id || ! $location->isActive()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Line {$line->line_no}: the destination location is not usable in this warehouse.");
        }

        return $location;
    }

    private function receiptValue(GoodsReceipt $gr): string
    {
        return (string) DB::table('goods_receipt_lines')->where('goods_receipt_id', $gr->id)->selectRaw('COALESCE(SUM(accepted_base_quantity * unit_cost), 0) AS v')->value('v');
    }
}
