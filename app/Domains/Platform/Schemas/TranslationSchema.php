<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Override one interface text for a locale. */
final class TranslationSchema extends TableSchema
{
    public static function table(): string
    {
        return 'translation_overrides';
    }

    protected static function define(): array
    {
        return [
            'locale' => Property::string(10)->required()->pattern('/^[a-z]{2,3}(-[A-Z]{2})?$/')->virtual()->label('Locale'),
            'key' => Property::string(190)->required()->pattern('/^[a-z0-9_.]{1,190}$/')->virtual()->label('Text key'),
            'value' => Property::text(2000)->required()->virtual()->label('Text'),
        ];
    }
}
