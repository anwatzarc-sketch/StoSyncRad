<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\Organization\Models\StorageLocation;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Confirm a put-away: move received stock to its storage bin. */
final class PutawayPostSchema extends TableSchema
{
    public static function table(): string
    {
        return 'putaway_tasks';
    }

    protected static function define(): array
    {
        return [
            'to_storage_location_id' => Property::uuid()->required()->references(StorageLocation::class, 'status', ['active'])->virtual()->label('Put into'),
            'quantity' => Property::quantity()->nullable()->positive()->virtual()->label('Quantity (blank = all)'),
        ];
    }
}
