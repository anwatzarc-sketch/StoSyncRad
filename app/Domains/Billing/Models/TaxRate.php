<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Schemas\TaxRateSchema;
use App\Support\Eloquent\BaseModel;

/** A tax rate (e.g. VAT 15%, exempt 0%) applied to invoice lines through the item or the default rate. */
final class TaxRate extends BaseModel
{
    protected $table = 'tax_rates';

    protected static ?string $schema = TaxRateSchema::class;

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }
}
