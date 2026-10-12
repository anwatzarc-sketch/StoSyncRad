<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\ItemType;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Enums\UomCategoryKind;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemCategory;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Catalog\Models\UomCategory;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Actions\CreateBranchAction;
use App\Domains\Organization\Actions\CreateWarehouseAction;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\ConfigScopeType;
use App\Domains\Platform\Config\SetConfigurationValueAction;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Support\Str;

/**
 * Fixture builders for inventory tests. Everything goes through the real actions/engine.
 */
trait BuildsInventory
{
    protected ?UnitOfMeasure $pce = null;

    protected ?ItemCategory $category = null;

    protected function branch(string $code = 'HQ', BranchKind $kind = BranchKind::HEADQUARTERS): Branch
    {
        return app(CreateBranchAction::class)->execute(['code' => $code, 'name' => "Branch $code", 'kind' => $kind->value], null);
    }

    protected function warehouse(string $code = 'WH1', ?Branch $branch = null): Warehouse
    {
        $branch ??= $this->branch('B'.Str::upper(Str::random(4)));

        return app(CreateWarehouseAction::class)->execute(['branch_id' => $branch->id, 'code' => $code, 'name' => "Warehouse $code"], null);
    }

    protected function pce(): UnitOfMeasure
    {
        if ($this->pce !== null) {
            return $this->pce;
        }
        $cat = UomCategory::query()->create(['code' => 'unit_'.Str::lower(Str::random(5)), 'name' => 'Units', 'kind' => UomCategoryKind::UNIT]);
        $this->pce = UnitOfMeasure::query()->forceCreate([
            'uom_category_id' => $cat->id, 'code' => 'PCE'.Str::upper(Str::random(3)), 'name' => 'Piece', 'symbol' => 'pc',
            'ratio_to_reference' => '1', 'rounding_precision' => 0, 'is_reference' => true, 'is_whole_number' => false, 'status' => 'active',
        ]);

        return $this->pce;
    }

    protected function category(): ItemCategory
    {
        if ($this->category !== null) {
            return $this->category;
        }
        $code = 'CAT'.Str::upper(Str::random(5));
        $cat = new ItemCategory(['code' => $code, 'name' => 'Test '.$code]);
        $cat->forceFill(['full_path' => 'Test '.$code, 'code_path' => $code, 'depth' => 0, 'status' => 'active'])->save();

        return $this->category = $cat;
    }

    protected function item(TrackingMode $tracking = TrackingMode::NONE, CostingMethod $costing = CostingMethod::MOVING_AVERAGE, ?string $standardCost = null): Item
    {
        $item = new Item([
            'sku' => 'SKU-'.Str::upper(Str::random(8)), 'name' => 'Test item', 'item_type' => ItemType::STOCKABLE->value,
            'category_id' => $this->category()->id, 'base_uom_id' => $this->pce()->id,
        ]);
        $item->forceFill([
            'status' => ItemStatus::ACTIVE, 'tracking_mode' => $tracking, 'costing_method' => $costing, 'standard_cost' => $standardCost,
        ])->save();

        return $item->refresh();
    }

    protected function setConfig(string $key, mixed $value, ConfigScopeType $scope = ConfigScopeType::GLOBAL, ?string $scopeId = null): void
    {
        app(SetConfigurationValueAction::class)->execute($key, $value, null, $scope, $scopeId);
    }

    protected function lot(Item $item, string $number = 'LOT-1', ?string $expiry = null, LotQcStatus $qc = LotQcStatus::RELEASED): InventoryLot
    {
        return InventoryLot::query()->forceCreate([
            'item_id' => $item->id, 'lot_number' => $number, 'expiry_date' => $expiry, 'qc_status' => $qc,
            'lock_status' => 'unlocked', 'status' => 'active',
        ]);
    }

    protected function stockLocationId(Warehouse $warehouse, StorageLocationUsage $usage = StorageLocationUsage::STORAGE): string
    {
        return app(InventoryLocationResolver::class)->warehouseDefault($warehouse, $usage)->id;
    }

    /** Post a supplier receipt straight into the warehouse's stock location. */
    protected function receive(Item $item, Warehouse $warehouse, string $qty, string $unitCost = '10', ?InventoryLot $lot = null, ?string $serialId = null,
        StockCondition $condition = StockCondition::AVAILABLE): PostingOutcome
    {
        $supplier = app(InventoryLocationResolver::class)->virtual(InventoryLocationType::SUPPLIER)->id;

        return $this->postMovement(MovementType::RECEIPT, DocumentType::GOODS_RECEIPT, $warehouse, [
            new MovementLine($item->id, null, $supplier, StockCondition::AVAILABLE, $this->stockLocationId($warehouse), $condition, $qty,
                lotId: $lot?->id, serialId: $serialId, unitCost: $unitCost),
        ]);
    }

    /** Post an issue from the warehouse stock location to the customer virtual location. */
    protected function issue(Item $item, Warehouse $warehouse, string $qty, ?InventoryLot $lot = null, ?string $idempotencyKey = null, array $consume = []): PostingOutcome
    {
        $customer = app(InventoryLocationResolver::class)->virtual(InventoryLocationType::CUSTOMER)->id;

        return $this->postMovement(MovementType::ISSUE, DocumentType::STOCK_ISSUE, $warehouse, [
            new MovementLine($item->id, null, $this->stockLocationId($warehouse), StockCondition::AVAILABLE, $customer, StockCondition::AVAILABLE, $qty,
                lotId: $lot?->id, consumeReservations: $consume),
        ], $idempotencyKey);
    }

    /** @param  array<int, MovementLine>  $lines */
    protected function postMovement(MovementType $type, DocumentType $docType, Warehouse $warehouse, array $lines, ?string $idempotencyKey = null, ?string $reversalOf = null): PostingOutcome
    {
        $docId = (string) Str::uuid7();

        return app(PostingPipeline::class)->run(null, 'test.'.$type->value, $idempotencyKey ?? (string) Str::uuid7(),
            ['doc' => $docId, 'type' => $type->value],
            function (PostingContext $ctx) use ($type, $docType, $warehouse, $lines, $docId, $reversalOf) {
                $movement = app(InventoryPoster::class)->post(new MovementRequest($type, $docType, $docId, 'TEST-'.substr($docId, -6), $docId, $lines,
                    warehouseId: $warehouse->id, reversalOfMovementId: $reversalOf), $ctx);

                return new PostingOutcome(['movement_id' => $movement->id, 'movement_number' => $movement->movement_number], 201, $docType->value, $docId);
            });
    }

    protected function onHand(Item $item, Warehouse $warehouse, StockCondition $condition = StockCondition::AVAILABLE, ?InventoryLot $lot = null): string
    {
        return (string) (\Illuminate\Support\Facades\DB::table('inventory_balances')
            ->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)->where('stock_condition', $condition->value)
            ->when($lot !== null, fn ($q) => $q->where('inventory_lot_id', $lot->id))
            ->sum('quantity_on_hand'));
    }
}
