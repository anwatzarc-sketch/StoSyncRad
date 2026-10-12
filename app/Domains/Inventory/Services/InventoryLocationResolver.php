<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Organization\Enums\InTransitLocationMode;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\InventoryLocation;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Maps storage locations and virtual purposes to inventory locations (Chapter 06 §6.5).
 * Users never create inventory locations; this resolver (plus setup seeds) does.
 */
final class InventoryLocationResolver
{
    /** @var array<string, InventoryLocation> */
    private array $memo = [];

    public function __construct(private readonly PlatformConfig $config) {}

    /** Physical inventory location of a storage location (created with it). */
    public function forStorageLocation(string $storageLocationId): InventoryLocation
    {
        $key = 'sl:'.$storageLocationId;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $location = InventoryLocation::query()->where('storage_location_id', $storageLocationId)->first();
        if ($location === null) {
            // Created inside the caller's transaction, which may still roll back: not memoized.
            return $this->createPhysical(StorageLocation::query()->findOrFail($storageLocationId));
        }

        return $this->memo[$key] = $location;
    }

    public function createPhysical(StorageLocation $storage): InventoryLocation
    {
        $id = (string) Str::uuid7();
        DB::table('inventory_locations')->insertOrIgnore([
            'id' => $id,
            'location_type' => InventoryLocationType::PHYSICAL->value,
            'warehouse_id' => $storage->warehouse_id,
            'storage_location_id' => $storage->id,
            'code' => 'PHY:'.$storage->full_path,
            'name' => $storage->full_path,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryLocation::query()->where('storage_location_id', $storage->id)->firstOrFail();
    }

    /** Default physical location of a given usage in a warehouse (receiving, quarantine, damaged…). */
    public function warehouseDefault(Warehouse $warehouse, StorageLocationUsage $usage): InventoryLocation
    {
        $storageId = $warehouse->defaultLocationId($usage);
        if ($storageId === null) {
            $storageId = StorageLocation::query()->where('warehouse_id', $warehouse->id)->where('usage', $usage->value)
                ->orderBy('is_system', 'desc')->orderBy('sort_order')->value('id');
        }
        if ($storageId === null) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION,
                "Warehouse {$warehouse->code} has no `{$usage->value}` location configured.",
                ['warehouse_id' => $warehouse->id, 'usage' => $usage->value]);
        }

        return $this->forStorageLocation($storageId);
    }

    /** Company-level virtual location of a given type (supplier, customer, adjustment, …). */
    public function virtual(InventoryLocationType $type): InventoryLocation
    {
        if ($type === InventoryLocationType::PHYSICAL || $type === InventoryLocationType::IN_TRANSIT) {
            throw new \InvalidArgumentException('Use forStorageLocation()/inTransit() for this type.');
        }
        $key = 'v:'.$type->value;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $code = 'VIRT:'.strtoupper($type->value);
        $created = DB::table('inventory_locations')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'location_type' => $type->value,
            'warehouse_id' => null,
            'storage_location_id' => null,
            'code' => $code,
            'name' => ucfirst(str_replace('_', ' ', $type->value)),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->remember($key, InventoryLocation::query()->where('code', $code)->firstOrFail(), $created);
    }

    /** In-transit location for a lane (or the single company-wide one, per configuration). */
    public function inTransit(string $sourceWarehouseId, string $destinationWarehouseId): InventoryLocation
    {
        $mode = InTransitLocationMode::from((string) $this->config->get('stock.in_transit_location_mode'));
        [$src, $dst, $code] = $mode === InTransitLocationMode::PER_LANE
            ? [$sourceWarehouseId, $destinationWarehouseId, 'TRANSIT:'.$sourceWarehouseId.'>'.$destinationWarehouseId]
            : [null, null, 'TRANSIT:COMPANY'];
        $key = 'it:'.$code;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $name = 'In transit';
        if ($mode === InTransitLocationMode::PER_LANE) {
            $codes = Warehouse::query()->whereIn('id', [$sourceWarehouseId, $destinationWarehouseId])->pluck('code', 'id');
            $name = 'In transit '.($codes[$sourceWarehouseId] ?? '?').' → '.($codes[$destinationWarehouseId] ?? '?');
        }
        $created = DB::table('inventory_locations')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'location_type' => InventoryLocationType::IN_TRANSIT->value,
            'warehouse_id' => null,
            'storage_location_id' => null,
            'lane_source_warehouse_id' => $src,
            'lane_destination_warehouse_id' => $dst,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->remember($key, InventoryLocation::query()->where('code', $code)->firstOrFail(), $created);
    }

    /**
     * Memoize only rows that existed before this call: a row created lazily inside a transaction
     * that later rolls back (e.g. a rejected posting) must not be served from the memo afterwards.
     */
    private function remember(string $key, InventoryLocation $location, int $created): InventoryLocation
    {
        if ($created === 0 || DB::transactionLevel() === 0) {
            $this->memo[$key] = $location;
        }

        return $location;
    }

    /** Seed every company-level virtual location (Setup Step 7). */
    public function seedVirtualLocations(): void
    {
        foreach (InventoryLocationType::cases() as $type) {
            if (! in_array($type, [InventoryLocationType::PHYSICAL, InventoryLocationType::IN_TRANSIT], true)) {
                $this->virtual($type);
            }
        }
    }
}
