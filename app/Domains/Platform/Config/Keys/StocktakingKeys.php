<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Domains\Stocktaking\Enums\StocktakeFreezePolicy;
use App\Domains\Stocktaking\Enums\StocktakeType;

final class StocktakingKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('stocktaking.default_type', StocktakeType::class, 'cycle', 'Default stocktake type', 'Type proposed for new stocktakes.', 'GW'),
            self::enum('stocktaking.default_freeze_policy', StocktakeFreezePolicy::class, 'snapshot_and_reconcile', 'Freeze policy', 'Freeze counted positions, or snapshot and reconcile intervening movements.', 'GW'),
            self::bool('stocktaking.blind_by_default', true, 'Blind counts', 'Hide book quantities from counters by default.', 'GW'),
            self::pct('stocktaking.recount_threshold_percent', '2', 'Recount threshold (%)', 'Variance percentage that requires a recount.', 'GW'),
            self::pct('stocktaking.supervisor_threshold_percent', '5', 'Supervisor threshold (%)', 'Variance percentage that requires supervisor verification.', 'GW'),
            self::dec('stocktaking.approval_threshold_value', '10000', 'Approval threshold (value)', 'Absolute variance value requiring approval.', 'GW', '0'),
            self::bool('stocktaking.counter_cannot_approve', true, 'Counter cannot approve', 'A counter may not approve adjustments from their own counts.'),
        ];
    }
}
