<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class OpeningBalanceLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'opening_balance_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class, 'status', ['active'])->label('Location'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity (base unit)'),
            'unit_cost' => Property::unitCost()->required()->nonNegative()->label('Unit cost'),
            'lot_number' => Property::string(80)->nullable()->trim()->label('Lot number'),
            'expiry_date' => Property::date()->nullable()->label('Expiry date'),
            'manufacture_date' => Property::date()->nullable()->label('Manufacture date'),
            'serial_numbers' => Property::arrayOf(Property::string(120)->trim())->nullable()->max(2000)->label('Serial numbers'),
            'stock_condition' => Property::enum(StockCondition::class)->default(StockCondition::AVAILABLE->value)->label('Condition'),
            'notes' => Property::text(1000)->nullable()->label('Evidence / notes'),
        ];
    }
}
