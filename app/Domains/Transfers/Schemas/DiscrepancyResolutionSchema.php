<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Transfers\Enums\DiscrepancyResolution;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** POST /transfers/{id}/discrepancies/{discrepancy}/resolutions */
final class DiscrepancyResolutionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_discrepancy_resolutions';
    }

    protected static function define(): array
    {
        return [
            'resolution' => Property::enum(DiscrepancyResolution::class)->required()->label('Resolution'),
            'quantity' => Property::quantity()->nullable()->positive()->label('Quantity (default: all unresolved)'),
            'reason' => Property::text(2000)->required()->trim()->label('Reason'),
            'destination_storage_location_id' => Property::uuid()->nullable()->references(StorageLocation::class, 'status', ['active'])->label('Location'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
