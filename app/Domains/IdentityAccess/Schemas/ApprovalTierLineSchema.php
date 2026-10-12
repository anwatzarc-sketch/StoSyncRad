<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** One approval tier: value band, approvals needed and the approving permission. */
final class ApprovalTierLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'approval_policies';
    }

    protected static function define(): array
    {
        return [
            'min_value' => Property::money()->required()->nonNegative()->virtual()->label('From value'),
            'max_value' => Property::money()->nullable()->positive()->virtual()->label('Up to value (blank = unlimited)'),
            'required_approvals' => Property::integer()->required()->min(1)->max(5)->virtual()->label('Approvals required'),
            'required_permission' => Property::string(120)->required()->pattern('/^[a-z_]+(\.[a-z_]+){2}$/')->virtual()->label('Approver permission'),
            'sequential' => Property::boolean()->default(false)->virtual()->label('Sequential'),
        ];
    }
}
