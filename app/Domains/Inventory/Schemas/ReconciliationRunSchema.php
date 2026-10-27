<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Scope of a ledger/projection reconciliation run. */
final class ReconciliationRunSchema extends TableSchema
{
    public static function table(): string
    {
        return 'reconciliation_runs';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class)->virtual()->label('Warehouse'),
            'item_ids' => Property::arrayOf(Property::uuid()->references(Item::class))->nullable()->max(5000)->virtual()->label('Items'),
        ];
    }
}
