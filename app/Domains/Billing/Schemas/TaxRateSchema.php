<?php

declare(strict_types=1);

namespace App\Domains\Billing\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Tax rate master data. */
final class TaxRateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'tax_rates';
    }

    protected static function define(): array
    {
        return [
            'code' => Property::string(40)->required()->trim()->pattern('/^[A-Z0-9][A-Z0-9_-]{0,39}$/')->unique()->searchable()->sortable()->label('Code'),
            'name' => Property::string(120)->required()->trim()->searchable()->label('Name'),
            'rate' => Property::percentage()->required()->label('Rate (%)'),
            'is_default' => Property::boolean()->default(false)->filterable()->label('Default rate', 'Used for items without their own tax rate.'),
            'is_active' => Property::boolean()->default(true)->filterable()->label('Active'),
            'description' => Property::text(1000)->nullable()->label('Description'),
        ];
    }
}
