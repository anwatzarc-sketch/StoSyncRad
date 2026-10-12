<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Render a template body against sample data. */
final class TemplatePreviewSchema extends TableSchema
{
    public static function table(): string
    {
        return 'templates';
    }

    protected static function define(): array
    {
        return [
            'body' => Property::text(200000)->required()->virtual()->label('Template'),
            'sample' => Property::json()->nullable()->virtual()->label('Sample data'),
        ];
    }
}
