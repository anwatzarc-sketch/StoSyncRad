<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Relabel an enum case for display (values never change). */
final class EnumLabelSchema extends TableSchema
{
    public static function table(): string
    {
        return 'enum_labels';
    }

    protected static function define(): array
    {
        return [
            'enum_code' => Property::string(120)->required()->trim()->virtual()->label('Enum'),
            'case_value' => Property::string(64)->required()->trim()->virtual()->label('Case'),
            'locale' => Property::string(10)->required()->pattern('/^[a-z]{2,3}(-[A-Z]{2})?$/')->virtual()->label('Locale'),
            'label' => Property::string(190)->required()->trim()->virtual()->label('Label'),
            'description' => Property::text(1000)->nullable()->trim()->virtual()->label('Description'),
        ];
    }
}
