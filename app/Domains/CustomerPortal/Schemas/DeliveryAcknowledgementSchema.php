<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Domains\CustomerPortal\Enums\DeliveryOutcome;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Customer confirmation of a delivery. */
final class DeliveryAcknowledgementSchema extends TableSchema
{
    public static function table(): string
    {
        return 'delivery_acknowledgements';
    }

    protected static function define(): array
    {
        return [
            'outcome' => Property::enum(DeliveryOutcome::class)->required()->virtual()->label('What arrived'),
            'note' => Property::text(2000)->nullable()->trim()->virtual()->label('Notes'),
            'issues' => Property::arrayOf(DeliveryIssueLineSchema::class)->nullable()->max(200)->virtual()->label('Issues'),
        ];
    }
}
