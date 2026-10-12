<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Saved report definition (dataset + fields, filters, grouping, layout). */
final class ReportDefinitionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'report_definitions';
    }

    protected static function define(): array
    {
        return [
            'name' => Property::string(190)->requiredOnCreate()->trim()->virtual()->label('Name'),
            'description' => Property::text(2000)->nullable()->trim()->virtual()->label('Description'),
            'dataset_code' => Property::string(120)->requiredOnCreate()->pattern('/^[a-z][a-z0-9_]{1,119}$/')->virtual()->label('Dataset'),
            'definition' => Property::json()->requiredOnCreate()->virtual()->label('Definition'),
            'tags' => Property::arrayOf(Property::string(40)->trim())->nullable()->max(20)->virtual()->label('Tags'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
            'change_note' => Property::text(1000)->nullable()->trim()->virtual()->label('Change note'),
        ];
    }
}
