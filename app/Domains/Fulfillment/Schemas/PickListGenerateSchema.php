<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Schemas;

use App\Domains\Fulfillment\Enums\PickSourceType;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Generate a pick list for a document. */
final class PickListGenerateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'pick_lists';
    }

    protected static function define(): array
    {
        return [
            'type' => Property::enum(PickSourceType::class)->required()->virtual()->label('Source document type'),
            'source_id' => Property::uuid()->required()->virtual()->label('Source document'),
        ];
    }
}
