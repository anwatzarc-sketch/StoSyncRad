<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Enums\StorageLocationLevel;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Schemas\StorageLocationSchema;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Zone / aisle / rack / shelf / bin inside a warehouse (Chapter 06 §6.4).
 *
 * @property string $warehouse_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $full_path
 * @property string $name
 * @property StorageLocationLevel $level
 * @property StorageLocationUsage $usage
 * @property bool $is_pickable
 * @property bool $is_putaway_target
 * @property bool $is_system
 * @property StorageLocationStatus $status
 * @property int $sort_order
 * @property-read Warehouse $warehouse
 * @property-read InventoryLocation|null $inventoryLocation
 */
final class StorageLocation extends BaseModel
{
    protected $table = 'storage_locations';

    protected static ?string $schema = StorageLocationSchema::class;

    protected function casts(): array
    {
        return [
            'level' => StorageLocationLevel::class,
            'usage' => StorageLocationUsage::class,
            'status' => StorageLocationStatus::class,
            'capacity' => 'array',
            'temperature_range' => 'array',
            'is_pickable' => 'boolean',
            'is_putaway_target' => 'boolean',
            'is_system' => 'boolean',
            'counting_frequency_days' => 'integer',
            'last_counted_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function inventoryLocation(): HasOne
    {
        return $this->hasOne(InventoryLocation::class);
    }

    public function isActive(): bool
    {
        return $this->status === StorageLocationStatus::ACTIVE;
    }
}
