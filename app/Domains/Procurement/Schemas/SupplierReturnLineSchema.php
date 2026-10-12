<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class SupplierReturnLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'supplier_return_lines';
    }

    protected static function define(): array
    {
        return [
            'goods_receipt_line_id' => Property::uuid()->nullable()->label('Receipt line'),
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class)->label('Return from location'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->label('Lot'),
            'serial_number' => Property::string(120)->nullable()->trim()->virtual()->label('Serial number'),
            'stock_condition' => Property::enum(StockCondition::class)->default(StockCondition::DAMAGED->value)->label('Condition of returned stock'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class)->label('Unit'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
