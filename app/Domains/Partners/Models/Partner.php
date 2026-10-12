<?php

declare(strict_types=1);

namespace App\Domains\Partners\Models;

use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Partners\Schemas\PartnerSchema;
use App\Support\Eloquent\BaseModel;
use App\Support\Eloquent\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier, customer, carrier, internal recipient or manufacturer (Chapter 06 §6.9).
 *
 * @property string $code
 * @property string $name
 * @property array<int, string> $partner_types
 * @property PartnerStatus $status
 * @property bool $is_internal
 * @property string|null $default_currency
 * @property int|null $lead_time_days
 */
final class Partner extends BaseModel
{
    protected $table = 'partners';

    protected static ?string $schema = PartnerSchema::class;

    protected function casts(): array
    {
        return [
            'partner_types' => PgTextArray::class.':'.PartnerType::class,
            'status' => PartnerStatus::class,
            'addresses' => 'array',
            'is_internal' => 'boolean',
            'lead_time_days' => 'integer',
            'rating' => 'integer',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartnerContact::class);
    }

    public function hasType(PartnerType $type): bool
    {
        return in_array($type->value, $this->partner_types, true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, PartnerType $type): void
    {
        $query->whereRaw('? = ANY(partner_types)', [$type->value]);
    }

    public function canTrade(): bool
    {
        return $this->status === PartnerStatus::ACTIVE;
    }
}
