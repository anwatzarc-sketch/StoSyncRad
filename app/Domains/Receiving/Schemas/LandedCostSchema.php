<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\Receiving\Enums\LandedCostBasis;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Allocate freight, duty or handling to a posted goods receipt. */
final class LandedCostSchema extends TableSchema
{
    public static function table(): string
    {
        return 'landed_cost_allocations';
    }

    protected static function define(): array
    {
        return [
            'amount' => Property::money()->required()->positive()->classification(DataClassification::COST_SENSITIVE->value)->virtual()->label('Amount'),
            'basis' => Property::enum(LandedCostBasis::class)->default(LandedCostBasis::VALUE->value)->virtual()->label('Allocate by'),
            'description' => Property::string(200)->required()->trim()->virtual()->label('Description', 'e.g. sea freight, import duty'),
            'currency' => Property::string(3)->nullable()->pattern('/^[A-Z]{3}$/')->virtual()->label('Currency'),
        ];
    }
}
