<?php

declare(strict_types=1);

namespace App\Support\Documents\Schemas;

use App\Domains\Platform\Models\LookupValue;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Payload of document decisions: submit / approve / reject / cancel / close / send.
 * `version` enforces optimistic concurrency; reasons are required where policy demands.
 */
final class DecisionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'approval_records';
    }

    protected static function define(): array
    {
        return [
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
            'comment' => Property::text(2000)->nullable()->trim()->virtual()->label('Comment'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
            'reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->virtual()->label('Reason code'),
            'override_reason' => Property::text(2000)->nullable()->trim()->virtual()->label('SoD override reason'),
        ];
    }
}
