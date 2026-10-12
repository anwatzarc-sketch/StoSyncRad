<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Seeds\CategoryTaxonomySeed;
use App\Domains\Platform\Seeds\QcChecklistsSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\StandardUnitsSeed;
use App\Domains\Platform\Seeds\StorageCategoriesSeed;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Step 8 — catalog foundations: units, storage categories, QC checklists, the category taxonomy
 * (all of Appendix E or selected top-level groups) and catalog/inventory policies.
 * Payload: {config: {catalog.*, inventory.*}, taxonomy: "all" | "none" | [top-level codes]}
 */
final class CatalogFoundationsStep extends ConfigOnlyStep
{
    public function __construct(ConfigApplier $applier, private readonly SeedRunner $seeds)
    {
        parent::__construct($applier);
    }

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::CATALOG_FOUNDATIONS;
    }

    protected function prefixes(): array
    {
        return ['catalog.', 'inventory.'];
    }

    protected function after(array $payload, SetupContext $context): void
    {
        $this->seeds->run(StandardUnitsSeed::class);
        $this->seeds->run(StorageCategoriesSeed::class);
        $this->seeds->run(QcChecklistsSeed::class);

        $taxonomy = $payload['taxonomy'] ?? 'all';
        $known = array_keys(CategoryTaxonomySeed::data());
        if (is_array($taxonomy)) {
            $unknown = array_diff($taxonomy, $known);
            if ($unknown !== []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown taxonomy groups: '.implode(', ', $unknown), ['allowed_values' => $known]);
            }
            $this->seeds->run(CategoryTaxonomySeed::class, ['groups' => array_values($taxonomy)]);
        } elseif ($taxonomy === 'all') {
            $this->seeds->run(CategoryTaxonomySeed::class);
        } elseif ($taxonomy !== 'none') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, '`taxonomy` must be "all", "none" or a list of group codes.');
        }

        $leaves = DB::table('item_categories as c')->where('c.status', 'active')
            ->whereNotExists(fn ($q) => $q->from('item_categories as ch')->whereColumn('ch.parent_id', 'c.id'))->count();
        if ($leaves === 0) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'At least one leaf category is required; import the taxonomy or create categories first.');
        }
    }

    public function current(): array
    {
        return parent::current() + [
            'taxonomy_groups' => array_map(fn ($code, $row) => ['code' => $code, 'name' => $row[0], 'subcategories' => count($row[2]) + 1],
                array_keys(CategoryTaxonomySeed::data()), CategoryTaxonomySeed::data()),
            'existing_categories' => DB::table('item_categories')->count(),
        ];
    }
}
