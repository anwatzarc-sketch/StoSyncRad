<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Fulfillment\Models\SalesOrder;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Group confirmed e-commerce orders into a pick wave. */
final class PickWaveSchema extends TableSchema
{
    public static function table(): string
    {
        return 'pick_waves';
    }

    protected static function define(): array
    {
        return [
            'warehouse_id' => Property::uuid()->required()->references(Warehouse::class, 'status', ['active'])->virtual()->label('Warehouse'),
            'sales_order_ids' => Property::arrayOf(Property::uuid()->references(SalesOrder::class))->nullable()->max(1000)->virtual()->label('Orders (blank = oldest eligible)'),
        ];
    }
}
