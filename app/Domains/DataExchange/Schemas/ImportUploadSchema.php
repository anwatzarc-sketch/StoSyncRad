<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Schemas;

use App\Domains\DataExchange\Enums\ImportType;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Upload an import file (CSV/XLSX as the `file` part). */
final class ImportUploadSchema extends TableSchema
{
    public static function table(): string
    {
        return 'import_batches';
    }

    protected static function define(): array
    {
        return [
            'type' => Property::enum(ImportType::class)->nullable()->virtual()->label('Import type'),
        ];
    }
}
