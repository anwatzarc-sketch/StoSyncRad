<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Schemas;

use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Tune a segregation-of-duties rule. */
final class SodRuleUpdateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'sod_rules';
    }

    protected static function define(): array
    {
        return [
            'rule' => Property::enum(SodRuleType::class)->nullable()->virtual()->label('Rule'),
            'is_active' => Property::boolean()->nullable()->virtual()->label('Active'),
            'threshold' => Property::json()->nullable()->virtual()->label('Threshold'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
