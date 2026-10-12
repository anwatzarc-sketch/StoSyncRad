<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Organization\Actions\CreateBranchAction;
use App\Domains\Organization\Actions\CreateStorageLocationAction;
use App\Domains\Organization\Actions\CreateWarehouseAction;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\StorageLocationLevel;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Organization\Schemas\BranchSchema;
use App\Domains\Organization\Schemas\WarehouseSchema;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\VirtualLocationsSeed;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Schema\SchemaValidator;
use App\Support\Schema\ValidationContext;
use Illuminate\Support\Facades\DB;

/**
 * Step 7 — branches, warehouses and storage locations (Chapter 06). Idempotent by code.
 *
 * Payload: {branches: [{code, name, kind, …, warehouses: [{code, name, kind?, capabilities?, location_pattern?}]}]}
 * location_pattern generates a bin hierarchy, e.g. "Z{1-2}/A{01-03}/B{01-04}" → zones / aisles / bins
 * under the warehouse storage area.
 */
final class OrganizationStep implements SetupStepHandler
{
    public const MAX_GENERATED_LOCATIONS = 5000;

    public function __construct(
        private readonly SchemaValidator $validator,
        private readonly CreateBranchAction $createBranch,
        private readonly CreateWarehouseAction $createWarehouse,
        private readonly CreateStorageLocationAction $createLocation,
        private readonly SeedRunner $seeds,
    ) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::ORGANIZATION;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        $branches = (array) ($payload['branches'] ?? []);
        if ($branches === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add at least one branch.', ['field' => 'branches']);
        }

        return DB::transaction(function () use ($branches, $context) {
            $this->seeds->run(VirtualLocationsSeed::class);
            $created = ['branches' => 0, 'warehouses' => 0, 'locations' => 0];
            foreach ($branches as $i => $branchPayload) {
                $warehouses = (array) ($branchPayload['warehouses'] ?? []);
                unset($branchPayload['warehouses']);
                $branch = Branch::query()->where('code', $branchPayload['code'] ?? '')->first();
                if ($branch === null) {
                    $data = $this->validator->validate(BranchSchema::class, $branchPayload, new ValidationContext('create'));
                    $branch = $this->createBranch->execute($data, $context->actor);
                    $created['branches']++;
                }
                foreach ($warehouses as $warehousePayload) {
                    $pattern = $warehousePayload['location_pattern'] ?? null;
                    unset($warehousePayload['location_pattern']);
                    $warehouse = Warehouse::query()->where('code', $warehousePayload['code'] ?? '')->first();
                    if ($warehouse === null) {
                        $data = $this->validator->validate(WarehouseSchema::class, ['branch_id' => $branch->id] + $warehousePayload, new ValidationContext('create'));
                        $warehouse = $this->createWarehouse->execute($data, $context->actor);
                        $created['warehouses']++;
                    }
                    if (is_string($pattern) && $pattern !== '') {
                        $created['locations'] += $this->generateLocations($warehouse, $pattern, $context);
                    }
                }
            }
            $this->assertComplete();

            return $created;
        });
    }

    private function assertComplete(): void
    {
        if (! Branch::query()->where('kind', BranchKind::HEADQUARTERS->value)->exists()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'At least one branch must be of kind `headquarters`.', ['field' => 'branches']);
        }
        if (! Warehouse::query()->exists()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Add at least one warehouse.', ['field' => 'branches.*.warehouses']);
        }
    }

    /** Expand "Z{1-2}/A{01-03}/B{01-04}" into nested locations under the warehouse's storage area. */
    public function generateLocations(Warehouse $warehouse, string $pattern, SetupContext $context): int
    {
        $levels = array_values(array_filter(explode('/', $pattern)));
        $levelEnums = [StorageLocationLevel::ZONE, StorageLocationLevel::AISLE, StorageLocationLevel::RACK, StorageLocationLevel::SHELF, StorageLocationLevel::BIN];
        if (count($levels) > 5) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Location patterns support at most 5 levels.');
        }
        $expansions = array_map(fn (string $level) => $this->expand($level), $levels);
        $total = array_product(array_map('count', $expansions));
        if ($total > self::MAX_GENERATED_LOCATIONS) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "The pattern would create {$total} bins (max ".self::MAX_GENERATED_LOCATIONS.').');
        }
        $root = $warehouse->default_storage_location_id;
        $count = 0;
        $build = function (int $depth, ?string $parentId, string $parentPath) use (&$build, $expansions, $levelEnums, $warehouse, $context, &$count): void {
            foreach ($expansions[$depth] as $code) {
                $leaf = $depth === count($expansions) - 1;
                $existing = StorageLocation::query()->where('warehouse_id', $warehouse->id)->where('full_path', $parentPath.'/'.$code)->first();
                $location = $existing ?? $this->createLocation->execute([
                    'warehouse_id' => $warehouse->id, 'parent_id' => $parentId, 'code' => $code, 'name' => $code,
                    'level' => $leaf ? StorageLocationLevel::BIN->value : $levelEnums[min($depth, 3)]->value,
                    'usage' => StorageLocationUsage::STORAGE->value, 'is_pickable' => $leaf, 'is_putaway_target' => $leaf, 'sort_order' => $count,
                ], $context->actor);
                if ($existing === null) {
                    $count++;
                }
                if (! $leaf) {
                    $build($depth + 1, $location->id, $location->full_path);
                }
            }
        };
        $rootLocation = StorageLocation::query()->findOrFail($root);
        $build(0, $rootLocation->id, $rootLocation->full_path);

        return $count;
    }

    /** "A{01-03}" → [A01, A02, A03]; "Z{1-2}" → [Z1, Z2]; "BULK" → [BULK]. @return array<int, string> */
    private function expand(string $level): array
    {
        if (preg_match('/^([A-Z0-9_-]*)\{(\d+)-(\d+)\}([A-Z0-9_-]*)$/', strtoupper($level), $m) !== 1) {
            if (preg_match('/^[A-Z0-9_-]{1,20}$/', strtoupper($level)) === 1) {
                return [strtoupper($level)];
            }
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Invalid location pattern segment `$level`.", ['expected' => 'PREFIX{start-end}SUFFIX']);
        }
        [$all, $prefix, $from, $to, $suffix] = $m;
        $width = strlen($from);
        $out = [];
        for ($n = (int) $from; $n <= (int) $to; $n++) {
            $out[] = $prefix.str_pad((string) $n, $width, '0', STR_PAD_LEFT).$suffix;
        }

        return $out;
    }

    public function current(): array
    {
        return ['branches' => Branch::query()->with('warehouses:id,branch_id,code,name,kind,capabilities')->orderBy('code')->get(['id', 'code', 'name', 'kind'])->toArray(),
            'branch_kinds' => array_map(fn (BranchKind $k) => ['value' => $k->value, 'label' => $k->label(), 'capabilities' => $k->defaultCapabilities()], BranchKind::cases())];
    }
}
