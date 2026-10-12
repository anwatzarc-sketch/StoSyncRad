<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Approval tiers of a document type. */
final class ApprovalPolicyUpdateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'approval_policies';
    }

    protected static function define(): array
    {
        return [
            'tiers' => Property::arrayOf(ApprovalTierLineSchema::class)->required()->min(1)->max(10)->virtual()->label('Tiers'),
            'is_active' => Property::boolean()->nullable()->virtual()->label('Active'),
        ];
    }
}
