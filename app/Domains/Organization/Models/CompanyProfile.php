<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Schemas\CompanyProfileSchema;
use App\Support\Eloquent\BaseModel;

/**
 * The single company configuration record (Chapter 06 §6.1). Not a tenant registry.
 *
 * @property string $legal_name
 * @property string|null $trading_name
 * @property string $base_currency
 * @property int $fiscal_year_start_month
 */
final class CompanyProfile extends BaseModel
{
    protected $table = 'company_profile';

    protected static ?string $schema = CompanyProfileSchema::class;

    protected function casts(): array
    {
        return [
            'registered_address' => 'array',
            'operational_address' => 'array',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    public function displayName(): string
    {
        return $this->trading_name ?: $this->legal_name;
    }
}
