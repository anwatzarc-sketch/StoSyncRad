<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Services;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\ApprovalService;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Models\Partner;
use App\Domains\Procurement\Enums\SupplierReturnStatus as S;
use App\Domains\Procurement\Models\SupplierReturn;
use App\Domains\Procurement\Models\SupplierReturnLine;
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
use Illuminate\Support\Str;

/**
 * Returns to supplier (Chapter 07 §7.13 PostSupplierReturnAction): draft → approval → posting
 * `position → supplier` at the original receipt cost when linked to a receipt line (FIFO consumes
 * that receipt's layer). Linked purchase order lines record the returned quantity.
 */
final class SupplierReturnWorkflow
{
    use ManagesDocuments;

    public function __construct(
        private readonly LineQuantities $lines,
        private readonly ApprovalService $approvals,
        private readonly PostingPipeline $pipeline,
        private readonly InventoryPoster $poster,
        private readonly InventoryLocationResolver $locations,
    ) {}

    /** @param  array<string, mixed>  $data  validated against SupplierReturnSchema */
    public function save(array $data, UserAccount $actor, ?string $id = null): SupplierReturn
    {
        return DB::transaction(function () use ($data, $actor, $id) {
            if ($id === null) {
                $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
                $this->authorizeWarehouse($actor, 'procurement.supplier_return.create', $warehouse->id);
                if (! Partner::query()->findOrFail($data['supplier_id'])->hasType(PartnerType::SUPPLIER)) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The partner is not a supplier.', ['field' => 'supplier_id']);
                }
                $ret = new SupplierReturn;
                $ret->forceFill(['document_number' => $this->nextNumber(DocumentType::SUPPLIER_RETURN, $warehouse->id), 'warehouse_id' => $warehouse->id,
                    'branch_id' => $warehouse->branch_id, 'supplier_id' => $data['supplier_id'], 'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                    'status' => S::DRAFT, 'created_by' => $actor->id, 'document_date' => $data['document_date'] ?? now()->toDateString()]);
            } else {
                $ret = $this->lockDocument(SupplierReturn::class, $id);
                $this->authorizeWarehouse($actor, 'procurement.supplier_return.update', $ret->warehouse_id);
                $this->expectVersion($ret, $data['version'] ?? null);
                $this->assertStatus($ret, [S::DRAFT]);
                $ret->forceFill(['version' => $ret->version + 1]);
            }
            $ret->forceFill(array_intersect_key($data, array_flip(['reason_lookup_value_id', 'reason', 'document_date', 'priority', 'reference', 'notes']))
                + ['updated_by' => $actor->id])->save();
            if (array_key_exists('lines', $data)) {
                SupplierReturnLine::query()->where('supplier_return_id', $ret->id)->delete();
                foreach (array_values($data['lines']) as $i => $line) {
                    $item = $this->lines->item($line['item_id'], false);
                    $storage = StorageLocation::query()->findOrFail($line['storage_location_id']);
                    if ($storage->warehouse_id !== $ret->warehouse_id) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The location belongs to another warehouse.', ['field' => "lines.$i.storage_location_id"]);
                    }
                    [$uomId, $base, $factor] = $this->lines->toBase($item->id, $line['uom_id'] ?? null, $line['quantity']);
                    $grLine = isset($line['goods_receipt_line_id']) ? GoodsReceiptLine::query()->findOrFail($line['goods_receipt_line_id']) : null;
                    if ($grLine !== null && $grLine->item_id !== $item->id) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The receipt line is for another item.', ['field' => "lines.$i.goods_receipt_line_id"]);
                    }
                    $serialId = null;
                    if ($item->tracking_mode->requiresSerial()) {
                        if (! D::eq($base, '1') || empty($line['serial_number'])) {
                            throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_SERIAL, 'Return serialized units one serial per line.', ['field' => "lines.$i.serial_number"]);
                        }
                        $serialId = ItemSerial::query()->where('item_id', $item->id)->where('serial_number', $line['serial_number'])->value('id')
                            ?? throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, 'Unknown serial.', ['field' => "lines.$i.serial_number"]);
                    }
                    SupplierReturnLine::query()->forceCreate(['supplier_return_id' => $ret->id, 'line_no' => $i + 1, 'goods_receipt_line_id' => $grLine?->id,
                        'purchase_order_line_id' => $grLine?->purchase_order_line_id, 'item_id' => $item->id, 'item_variant_id' => $line['item_variant_id'] ?? null,
                        'inventory_lot_id' => $line['inventory_lot_id'] ?? $grLine?->inventory_lot_id, 'item_serial_id' => $serialId, 'storage_location_id' => $storage->id,
                        'stock_condition' => StockCondition::from($line['stock_condition'] ?? StockCondition::DAMAGED->value), 'quantity' => $line['quantity'],
                        'uom_id' => $uomId, 'conversion_factor_snapshot' => $factor, 'base_quantity' => $base, 'unit_cost' => $grLine?->unit_cost, 'notes' => $line['notes'] ?? null]);
                }
            }
            $this->audit($actor, $id === null ? 'procurement.supplier_return.created' : 'procurement.supplier_return.updated', $ret);

            return $ret->refresh();
        });
    }

    public function submit(string $id, UserAccount $actor, ?int $version = null): SupplierReturn
    {
        return DB::transaction(function () use ($id, $actor, $version) {
            $ret = $this->lockDocument(SupplierReturn::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.supplier_return.submit', $ret->warehouse_id);
            $this->expectVersion($ret, $version);
            $this->transition($ret, S::PENDING_APPROVAL, ['submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->approvals->record(DocumentType::SUPPLIER_RETURN, $ret->id, $actor, ApprovalAction::SUBMITTED);
            $this->audit($actor, 'procurement.supplier_return.submitted', $ret);

            return $ret;
        });
    }

    public function approve(string $id, UserAccount $actor, ?int $version = null, ?string $comment = null, ?string $overrideReason = null): SupplierReturn
    {
        return DB::transaction(function () use ($id, $actor, $version, $comment, $overrideReason) {
            $ret = $this->lockDocument(SupplierReturn::class, $id);
            $this->expectVersion($ret, $version);
            $this->assertStatus($ret, [S::PENDING_APPROVAL]);
            $value = D::trim((string) DB::table('supplier_return_lines as l')->join('items as i', 'i.id', '=', 'l.item_id')->where('l.supplier_return_id', $ret->id)
                ->selectRaw('COALESCE(SUM(l.base_quantity * COALESCE(l.unit_cost, i.current_average_cost)), 0) AS v')->value('v'));
            $this->enforceSod(DocumentType::SUPPLIER_RETURN, DocumentAction::APPROVE, $this->documentActors($ret, DocumentType::SUPPLIER_RETURN), $actor, $ret->id, $value, null, $overrideReason);
            if ($this->approvals->approve(DocumentType::SUPPLIER_RETURN, $ret->id, $actor, $value, null, ['warehouse_id' => $ret->warehouse_id, 'branch_id' => $ret->branch_id], $comment)) {
                $this->transition($ret, S::APPROVED, ['approved_by' => $actor->id, 'approved_at' => now()]);
            }
            $this->audit($actor, 'procurement.supplier_return.approval_recorded', $ret, null, null, $comment);

            return $ret;
        });
    }

    public function post(string $id, UserAccount $actor, ?string $idempotencyKey, array $options = []): PostingOutcome
    {
        return $this->pipeline->run($actor, 'procurement.supplier_return.post', $idempotencyKey, ['supplier_return_id' => $id] + $options,
            function (PostingContext $ctx) use ($id, $actor, $options) {
                $ret = $this->lockDocument(SupplierReturn::class, $id);
                $this->authorizeWarehouse($actor, 'procurement.supplier_return.post', $ret->warehouse_id);
                $this->expectVersion($ret, $options['version'] ?? null);
                if ($ret->status === S::POSTED) {
                    throw DomainException::alreadyPosted('Supplier return', $ret->document_number);
                }
                $this->assertStatus($ret, [S::APPROVED]);
                Warehouse::query()->findOrFail($ret->warehouse_id)->assertOperational();
                $supplier = $this->locations->virtual(InventoryLocationType::SUPPLIER)->id;
                $lines = SupplierReturnLine::query()->where('supplier_return_id', $ret->id)->orderBy('line_no')->get();
                $movementLines = $lines->map(fn (SupplierReturnLine $l) => new MovementLine($l->item_id, $l->item_variant_id,
                    $this->locations->forStorageLocation($l->storage_location_id)->id, $l->stock_condition, $supplier, $l->stock_condition, D::exact($l->base_quantity),
                    lotId: $l->inventory_lot_id, serialId: $l->item_serial_id, unitCost: $l->unit_cost, fixedCost: $l->unit_cost !== null,
                    documentUomId: $l->uom_id, documentQuantity: $l->quantity, conversionFactor: $l->conversion_factor_snapshot,
                    sourceLineType: 'supplier_return_line', sourceLineId: $l->id))->all();
                $movement = $this->poster->post(new MovementRequest(MovementType::SUPPLIER_RETURN, DocumentType::SUPPLIER_RETURN, $ret->id, $ret->document_number,
                    (string) Str::uuid7(), $movementLines, businessDate: $ret->document_date?->toDateString(), warehouseId: $ret->warehouse_id, partnerId: $ret->supplier_id,
                    reasonLookupValueId: $ret->reason_lookup_value_id, reasonText: $ret->reason, allowLockedLots: true, allowExpired: true), $ctx);
                foreach ($lines->whereNotNull('purchase_order_line_id')->groupBy('purchase_order_line_id') as $poLineId => $group) {
                    DB::update('UPDATE purchase_order_lines SET returned_base_quantity = returned_base_quantity + ?, updated_at = ? WHERE id = ?',
                        [D::sum($group->pluck('base_quantity')->all()), now(), $poLineId]);
                }
                $ret->forceFill(['status' => S::POSTED, 'posted_by' => $actor->id, 'posted_at' => now(), 'stock_movement_id' => $movement->id, 'version' => $ret->version + 1])->save();
                $ctx->audit('procurement.supplier_return.posted', 'supplier_returns', $ret->id, null, ['movement_number' => $movement->movement_number]);
                $ctx->publish('procurement.supplier_return.posted', 'supplier_returns', $ret->id, ['document_number' => $ret->document_number, 'supplier_id' => $ret->supplier_id]);

                return new PostingOutcome(['supplier_return_id' => $ret->id, 'document_number' => $ret->document_number, 'status' => S::POSTED->value,
                    'stock_movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, DocumentType::SUPPLIER_RETURN->value, $ret->id);
            }, failureAudit: ['procurement.supplier_return.post_failed', 'supplier_returns', $id]);
    }

    public function cancel(string $id, UserAccount $actor, ?string $reason, ?int $version = null): SupplierReturn
    {
        return DB::transaction(function () use ($id, $actor, $reason, $version) {
            $ret = $this->lockDocument(SupplierReturn::class, $id);
            $this->authorizeWarehouse($actor, 'procurement.supplier_return.cancel', $ret->warehouse_id);
            $this->expectVersion($ret, $version);
            $this->transition($ret, S::CANCELLED, ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            $this->audit($actor, 'procurement.supplier_return.cancelled', $ret, null, null, $reason);

            return $ret;
        });
    }
}
