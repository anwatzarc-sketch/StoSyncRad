<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class QcChecklistTemplateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'qc_checklist_templates';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(60)->requiredOnCreate()->trim()->pattern('/^[a-z0-9_-]{2,60}$/')->unique()->label('Code'),
            'name' => Property::string(160)->required()->trim()->label('Name'),
            'description' => Property::text(4000)->nullable()->label('Description'),
            'checks' => Property::arrayOf(QcCheckDefinitionSchema::class)->required()->min(1)->max(100)->label('Checks'),
            'is_active' => Property::boolean()->default(true)->label('Active'),
        ];
    }
}
