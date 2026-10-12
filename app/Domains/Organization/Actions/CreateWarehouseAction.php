<?php

declare(strict_types=1);

namespace App\Domains\Organization\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Enums\StorageLocationLevel;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Organization\Schemas\WarehouseSchema;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Creates a warehouse with its system locations (Chapter 06 §6.3): every warehouse gets one
 * location per operational usage and points its defaults at them. Capabilities default to the
 * branch kind's set when not supplied.
 */
final class CreateWarehouseAction
{
    /** usage → [code, name, pickable, putaway target] */
    public const SYSTEM_LOCATIONS = [
        'receiving' => ['RECV', 'Receiving', false, false],
        'storage' => ['STOCK', 'Stock', true, true],
        'quarantine' => ['QUAR', 'Quarantine', false, false],
        'damaged' => ['DMG', 'Damaged', false, false],
        'scrap' => ['SCRP', 'Scrap', false, false],
        'returns' => ['RTN', 'Returns', false, false],
        'packing' => ['PACK', 'Packing', false, false],
        'dispatch' => ['DISP', 'Dispatch', false, false],
    ];

    public function __construct(
        private readonly CreateStorageLocationAction $createLocation,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /** @param  array<string, mixed>  $data  validated against WarehouseSchema */
    public function execute(array $data, ?UserAccount $actor): Warehouse
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var Branch $branch */
            $branch = Branch::query()->lockForUpdate()->findOrFail($data['branch_id']);
            if ($branch->status !== BranchStatus::ACTIVE) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, "Branch {$branch->code} is not active.");
            }

            $warehouse = new Warehouse(WarehouseSchema::columns($data));
            $warehouse->forceFill([
                'capabilities' => $data['capabilities'] ?? $branch->kind->defaultCapabilities(),
                'status' => WarehouseStatus::ACTIVE,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();

            $defaults = [];
            foreach (self::SYSTEM_LOCATIONS as $usage => [$code, $name, $pickable, $putaway]) {
                $location = $this->createLocation->execute([
                    'warehouse_id' => $warehouse->id,
                    'code' => $code,
                    'name' => $name,
                    'level' => StorageLocationLevel::AREA->value,
                    'usage' => $usage,
                    'is_pickable' => $pickable,
                    'is_putaway_target' => $putaway,
                    'sort_order' => 0,
                ], $actor, system: true);
                $column = 'default_'.StorageLocationUsage::from($usage)->value.'_location_id';
                if (empty($data[$column])) {
                    $defaults[$column] = $location->id;
                }
            }
            $warehouse->forceFill($defaults)->save();

            $this->audit->record($actor, 'organization.warehouse.created', 'warehouse', $warehouse->id, null,
                $warehouse->only(['branch_id', 'code', 'name', 'kind', 'capabilities']));
            $this->outbox->publish('organization.warehouse.created', 'warehouse', $warehouse->id, ['code' => $warehouse->code]);

            return $warehouse->refresh();
        });
    }
}
