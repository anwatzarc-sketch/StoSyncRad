<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Add or change a lookup value. */
final class LookupValueSaveSchema extends TableSchema
{
    public static function table(): string
    {
        return 'lookup_values';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(64)->required()->trim()->pattern('/^[a-z0-9][a-z0-9_]{0,63}$/')->virtual()->label('Code'),
            'label' => Property::string(190)->required()->trim()->virtual()->label('Label'),
            'sort_order' => Property::integer()->nullable()->min(0)->max(100000)->virtual()->label('Sort order'),
            'is_active' => Property::boolean()->nullable()->virtual()->label('Active'),
            'metadata' => Property::json()->nullable()->virtual()->label('Metadata'),
        ];
    }
}
