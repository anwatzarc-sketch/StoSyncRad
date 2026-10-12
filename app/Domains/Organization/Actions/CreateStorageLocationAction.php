<?php

declare(strict_types=1);

namespace App\Domains\Organization\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Organization\Schemas\StorageLocationSchema;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Creates a storage location and its physical inventory location (Chapter 06 §6.4–6.5).
 * full_path = WAREHOUSE-CODE/…parent codes…/CODE.
 */
final class CreateStorageLocationAction
{
    public function __construct(private readonly AuditRecorder $audit, private readonly InventoryLocationResolver $locations) {}

    /** @param  array<string, mixed>  $data  validated against StorageLocationSchema */
    public function execute(array $data, ?UserAccount $actor, bool $system = false): StorageLocation
    {
        return DB::transaction(function () use ($data, $actor, $system) {
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($data['warehouse_id']);
            if ($warehouse->status === WarehouseStatus::CLOSED) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Warehouse {$warehouse->code} is closed.");
            }

            $prefix = $warehouse->code;
            if (! empty($data['parent_id'])) {
                /** @var StorageLocation $parent */
                $parent = StorageLocation::query()->findOrFail($data['parent_id']);
                if ($parent->warehouse_id !== $warehouse->id) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The parent location belongs to another warehouse.', ['parent_id' => $parent->id]);
                }
                $prefix = $parent->full_path;
            }

            $location = new StorageLocation(StorageLocationSchema::columns($data));
            $location->forceFill([
                'full_path' => $prefix.'/'.$data['code'],
                'is_system' => $system,
                'status' => StorageLocationStatus::ACTIVE,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();

            $this->locations->createPhysical($location);
            $this->audit->record($actor, 'organization.storage_location.created', 'storage_location', $location->id, null,
                $location->only(['warehouse_id', 'full_path', 'usage', 'level']));

            return $location;
        });
    }
}
