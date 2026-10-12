<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Inspection results for return lines. */
final class InspectionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'internal_return_lines';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(InspectionLineSchema::class)->required()->min(1)->max(2000)->virtual()->label('Inspected lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
