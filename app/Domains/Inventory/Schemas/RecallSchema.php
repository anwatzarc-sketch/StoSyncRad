<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Inventory\Models\InventoryLot;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Initiate a lot recall. */
final class RecallSchema extends TableSchema
{
    public static function table(): string
    {
        return 'recalls';
    }

    protected static function define(): array
    {
        return [
            'reason' => Property::text(4000)->required()->trim()->virtual()->label('Reason'),
            'lot_ids' => Property::arrayOf(Property::uuid()->references(InventoryLot::class))->required()->min(1)->max(500)->virtual()->label('Lots'),
        ];
    }
}
