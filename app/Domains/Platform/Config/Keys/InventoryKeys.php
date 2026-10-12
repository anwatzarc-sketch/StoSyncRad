<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Catalog\Enums\CostingMethod;
use App\Domains\Inventory\Enums\CostingLevel;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Support\Enums\RoundingMode;

final class InventoryKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('inventory.costing_method', CostingMethod::class, 'moving_average', 'Costing method', 'Company costing method; categories/items may override only before their first posting.', 'GCI')->managedBy('inventory.valuation.manage_policy'),
            self::enum('inventory.costing_level', CostingLevel::class, 'company', 'Average cost level', 'Compute moving-average cost per item company-wide or per warehouse.')->managedBy('inventory.valuation.manage_policy'),
            self::enum('inventory.rounding.money_mode', RoundingMode::class, 'half_even', 'Money rounding', 'Rounding mode at monetary posting boundaries.'),
            self::enum('inventory.rounding.quantity_mode', RoundingMode::class, 'half_up', 'Quantity display rounding', 'Rounding mode for displayed quantities (never for stored quantities).'),
            self::bool('inventory.consignment.enabled', false, 'Consignment stock', 'Track an owner dimension on stock positions.'),
            self::bool('inventory.packages.enabled', false, 'Packages', 'Track a package dimension on stock positions.'),
            self::int('inventory.reconciliation.chunk_size', 5000, 'Reconciliation chunk size', 'Positions processed per reconciliation chunk.', 'G', 100, 100000),
        ];
    }
}
