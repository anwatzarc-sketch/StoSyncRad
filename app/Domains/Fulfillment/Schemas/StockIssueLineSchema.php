<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class StockIssueLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_issue_lines';
    }

    protected static function define(): array
    {
        return [
            'stock_request_line_id' => Property::uuid()->nullable()->label('Request line'),
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'storage_location_id' => Property::uuid()->required()->references(StorageLocation::class, 'status', ['active'])->label('Picked from'),
            'inventory_lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->label('Lot'),
            'serial_numbers' => Property::arrayOf(Property::string(120)->trim())->nullable()->max(2000)->label('Serial numbers'),
            'quantity' => Property::quantity()->required()->positive()->label('Quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class)->label('Unit'),
            'short_pick_reason' => Property::text(500)->nullable()->label('Short-pick reason'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
