<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Replace a template body (sandboxed template language). */
final class TemplateSaveSchema extends TableSchema
{
    public static function table(): string
    {
        return 'templates';
    }

    protected static function define(): array
    {
        return [
            'body' => Property::text(200000)->required()->virtual()->label('Template'),
            'is_active' => Property::boolean()->nullable()->virtual()->label('Active'),
        ];
    }
}
