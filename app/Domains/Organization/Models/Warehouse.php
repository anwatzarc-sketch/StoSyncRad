<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\IdentityAccess\Models\Person;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Enums\WarehouseKind;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Organization\Schemas\WarehouseSchema;
use App\Support\Eloquent\BaseModel;
use App\Support\Eloquent\Casts\JsonList;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock-holding facility inside a branch; the unit of operational authorization (Chapter 06 §6.3).
 *
 * @property string $branch_id
 * @property string $code
 * @property string $name
 * @property WarehouseKind $kind
 * @property array<int, string> $capabilities
 * @property WarehouseStatus $status
 * @property string|null $default_receiving_location_id
 * @property string|null $default_dispatch_location_id
 * @property string|null $default_quarantine_location_id
 * @property string|null $default_damaged_location_id
 * @property string|null $default_scrap_location_id
 * @property string|null $default_returns_location_id
 * @property string|null $default_packing_location_id
 * @property string|null $default_storage_location_id
 * @property-read Branch $branch
 */
final class Warehouse extends BaseModel
{
    protected $table = 'warehouses';

    protected static ?string $schema = WarehouseSchema::class;

    protected function casts(): array
    {
        return [
            'kind' => WarehouseKind::class,
            'status' => WarehouseStatus::class,
            'capabilities' => JsonList::class.':'.WarehouseCapability::class,
            'address' => 'array',
            'contact' => 'array',
            'operational_calendar' => 'array',
            'metadata' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'manager_person_id');
    }

    public function storageLocations(): HasMany
    {
        return $this->hasMany(StorageLocation::class);
    }

    public function hasCapability(WarehouseCapability $capability): bool
    {
        return in_array($capability->value, $this->capabilities, true);
    }

    /** @throws DomainException when the warehouse lacks the capability or is not operational. */
    public function assertCapability(WarehouseCapability $capability): void
    {
        if (! $this->hasCapability($capability)) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION,
                "Warehouse {$this->code} is not configured for `{$capability->value}`.",
                ['warehouse_id' => $this->id, 'capability' => $capability->value]);
        }
    }

    public function assertOperational(bool $inbound = false): void
    {
        $ok = $this->status === WarehouseStatus::ACTIVE || (! $inbound && $this->status === WarehouseStatus::CLOSING);
        if (! $ok) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION,
                "Warehouse {$this->code} is {$this->status->value} and cannot accept this operation.",
                ['warehouse_id' => $this->id, 'status' => $this->status->value]);
        }
    }

    public function defaultLocationId(StorageLocationUsage $usage): ?string
    {
        return match ($usage) {
            StorageLocationUsage::RECEIVING => $this->default_receiving_location_id,
            StorageLocationUsage::DISPATCH => $this->default_dispatch_location_id,
            StorageLocationUsage::QUARANTINE => $this->default_quarantine_location_id,
            StorageLocationUsage::DAMAGED => $this->default_damaged_location_id,
            StorageLocationUsage::SCRAP => $this->default_scrap_location_id,
            StorageLocationUsage::RETURNS => $this->default_returns_location_id,
            StorageLocationUsage::PACKING => $this->default_packing_location_id,
            default => $this->default_storage_location_id,
        };
    }
}
