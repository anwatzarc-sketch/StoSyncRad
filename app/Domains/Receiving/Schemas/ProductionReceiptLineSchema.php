<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class ProductionReceiptLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_movement_lines';
    }

    protected static function define(): array
    {
        return [
            'item_id' => Property::uuid()->required()->references(Item::class)->virtual()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->virtual()->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class, 'status', ['active'])->virtual()->label('Put into'),
            'quantity' => Property::quantity()->required()->positive()->virtual()->label('Quantity'),
            'unit_cost' => Property::unitCost()->required()->nonNegative()->classification(DataClassification::COST_SENSITIVE->value)->virtual()->label('Unit cost'),
            'stock_condition' => Property::enum(StockCondition::class)->default(StockCondition::AVAILABLE->value)->virtual()->label('Condition'),
            'lot_number' => Property::string(80)->nullable()->trim()->pattern('/^[A-Za-z0-9._\/-]{1,80}$/')->virtual()->label('Lot / batch'),
            'manufacture_date' => Property::date()->nullable()->virtual()->label('Manufactured'),
            'expiry_date' => Property::date()->nullable()->after('manufacture_date')->virtual()->label('Expiry'),
            'serial_numbers' => Property::arrayOf(Property::string(120)->trim()->pattern('/^[A-Za-z0-9._\/-]{1,120}$/'))->nullable()->max(5000)->virtual()->label('Serial numbers'),
        ];
    }
}
