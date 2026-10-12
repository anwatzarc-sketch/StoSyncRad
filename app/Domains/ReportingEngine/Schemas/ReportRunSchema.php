<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Run a saved report on screen. */
final class ReportRunSchema extends TableSchema
{
    public static function table(): string
    {
        return 'report_runs';
    }

    protected static function define(): array
    {
        return [
            'parameters' => Property::json()->nullable()->virtual()->label('Parameters'),
            'page' => Property::integer()->nullable()->min(1)->virtual()->label('Page'),
            'per_page' => Property::integer()->nullable()->min(1)->max(1000)->virtual()->label('Rows per page'),
        ];
    }
}
