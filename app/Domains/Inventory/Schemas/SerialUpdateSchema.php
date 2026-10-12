<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Serial warranty and attributes. */
final class SerialUpdateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'item_serials';
    }

    protected static function define(): array
    {
        return [
            'warranty_until' => Property::date()->nullable()->virtual()->label('Warranty until'),
            'attributes' => Property::json()->nullable()->virtual()->label('Attributes'),
        ];
    }
}
