<?php

declare(strict_types=1);

namespace App\Domains\Organization\Models;

use App\Domains\Organization\Enums\InventoryLocationType;
use App\Support\Eloquent\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Physical storage location or controlled virtual location the ledger moves stock between
 * (Chapter 06 §6.5). Created only by the platform (storage-location creation, setup seeds,
 * in-transit lane resolution) — never directly by users.
 *
 * @property InventoryLocationType $location_type
 * @property string|null $warehouse_id
 * @property string|null $storage_location_id
 * @property string|null $lane_source_warehouse_id
 * @property string|null $lane_destination_warehouse_id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 * @property-read StorageLocation|null $storageLocation
 * @property-read Warehouse|null $warehouse
 */
final class InventoryLocation extends BaseModel
{
    protected $table = 'inventory_locations';

    protected $fillable = ['location_type', 'warehouse_id', 'storage_location_id', 'lane_source_warehouse_id', 'lane_destination_warehouse_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['location_type' => InventoryLocationType::class, 'is_active' => 'boolean'];
    }

    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isPhysical(): bool
    {
        return $this->location_type === InventoryLocationType::PHYSICAL;
    }
}
