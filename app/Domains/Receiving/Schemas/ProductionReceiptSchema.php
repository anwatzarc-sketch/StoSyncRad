<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Finished goods received from production (feature.production_store). */
final class ProductionReceiptSchema extends TableSchema
{
    public static function table(): string
    {
        return 'stock_movements';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->required()->references(Warehouse::class)->virtual()->label('Warehouse'),
            'reference' => Property::string(120)->nullable()->trim()->virtual()->label('Production order / reference'),
            'lines' => Property::arrayOf(ProductionReceiptLineSchema::class)->required()->min(1)->max(500)->virtual()->label('Lines'),
        ];
    }
}
