<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Manual reservation of available stock. */
final class ReservationCreateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_reservations';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->required()->references(Warehouse::class)->virtual()->label('Warehouse'),
            'item_id' => Property::uuid()->required()->references(Item::class)->virtual()->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->virtual()->label('Variant'),
            'lot_id' => Property::uuid()->nullable()->references(InventoryLot::class)->virtual()->label('Lot'),
            'quantity' => Property::quantity()->required()->positive()->virtual()->label('Quantity'),
            'expires_at' => Property::datetime()->nullable()->virtual()->label('Expires'),
            'note' => Property::text(1000)->nullable()->trim()->virtual()->label('Note'),
        ];
    }
}
