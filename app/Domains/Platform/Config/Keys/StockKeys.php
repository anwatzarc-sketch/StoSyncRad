<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Inventory\Enums\FefoStrictness;
use App\Domains\Inventory\Enums\NegativeStockPolicy;
use App\Domains\Inventory\Enums\ReservationPolicy;
use App\Domains\Organization\Enums\InTransitLocationMode;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Support\Enums\EnforcementLevel;

final class StockKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('stock.negative_stock_policy', NegativeStockPolicy::class, 'forbid', 'Negative stock policy', 'Forbid negative availability, or allow it only with the override permission.', 'GW'),
            self::enum('stock.in_transit_location_mode', InTransitLocationMode::class, 'per_lane', 'In-transit locations', 'One in-transit location per source/destination lane, or one company-wide.'),
            self::int('stock.backdating_window_days', 7, 'Backdating window', 'Days a business date may precede today. Postings always use the current timestamp.', 'G', 0, 90),
            self::enum('stock.reservation.policy', ReservationPolicy::class, 'on_approval', 'Reservation policy', 'Reserve stock when requests are approved, when picking starts, or never.', 'GW'),
            self::int('stock.reservation.auto_release_days', 30, 'Reservation auto-release', 'Days after which unconsumed reservations are released (0 = never).', 'GW', 0, 365),
            self::list('stock.expiry.alert_horizons_days', [7, 30, 90], 'Expiry alert horizons', 'Days-before-expiry thresholds that raise alerts.', 'GC', null, 'integer', 1, 10),
            self::bool('stock.expiry.block_on_expiry', true, 'Block expired stock', 'Move stock to the expired condition at expiry and block issue.', 'GC'),
            self::enum('stock.expiry.enforce_removal_date', EnforcementLevel::class, 'warn', 'Removal-date enforcement', 'Behaviour when issuing stock past its removal date.', 'GC'),
            self::enum('stock.fefo.strictness', FefoStrictness::class, 'suggest', 'FEFO strictness', 'Enforce or merely suggest first-expired-first-out picking.', 'GWC'),
            self::bool('stock.quarantine.require_release_evidence', false, 'Quarantine release evidence', 'Require an attachment when releasing stock from quarantine.'),
            self::int('stock.reversal.window_days', 30, 'Reversal window', 'Days after posting during which a movement may be reversed.', 'G', 0, 3650),
            self::bool('stock.reversal.requires_approval', true, 'Reversal approval', 'Reversals require approval by a second authorized user.'),
            self::bool('stock.relocation.requires_approval', false, 'Relocation approval', 'Relocations require approval before posting.', 'GW'),
            self::bool('stock.condition_change.requires_approval', true, 'Condition change approval', 'Condition changes require approval before posting.', 'GW'),
        ];
    }
}
