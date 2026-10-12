<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Open an investigation on a transfer discrepancy. */
final class DiscrepancyInvestigateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_discrepancies';
    }

    protected static function define(): array
    {
        return [
            'notes' => Property::text(2000)->nullable()->trim()->virtual()->label('Investigation notes'),
        ];
    }
}
