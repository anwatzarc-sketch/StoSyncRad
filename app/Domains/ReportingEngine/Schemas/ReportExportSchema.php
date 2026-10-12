<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Queue a PDF/Excel/CSV export (draft exports carry their definition). */
final class ReportExportSchema extends TableSchema
{
    public static function table(): string
    {
        return 'report_runs';
    }

    protected static function define(): array
    {
        return [
            'dataset_code' => Property::string(120)->nullable()->pattern('/^[a-z][a-z0-9_]{1,119}$/')->virtual()->label('Dataset'),
            'definition' => Property::json()->nullable()->virtual()->label('Definition'),
            'parameters' => Property::json()->nullable()->virtual()->label('Parameters'),
        ];
    }
}
