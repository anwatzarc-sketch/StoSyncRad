<?php

declare(strict_types=1);

namespace App\Domains\Stocktaking\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StockAdjustmentLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_adjustment_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class, 'status', ['active', 'blocked'])->label('Location'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->label('Lot', 'Existing lot.'),
            'lot_number' => Property::string(80)->nullable()->trim()->label('New lot number', 'Found stock of a lot not yet registered.'),
            'expiry_date' => Property::date()->nullable()->label('Expiry date'),
            'serial_number' => Property::string(120)->nullable()->trim()->label('Serial number'),
            'stock_condition' => Property::enum(StockCondition::class)->default(StockCondition::AVAILABLE->value)->label('Condition'),
            'quantity' => Property::quantity()->required()->label('Quantity (base unit)', 'Positive = found / increase, negative = loss / decrease.'),
            'unit_cost' => Property::unitCost()->nullable()->nonNegative()->label('Unit cost', 'Increases only; defaults to the current cost.'),
            'reason' => Property::text(1000)->nullable()->label('Line reason / evidence'),
        ];
    }
}
