<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Domains\Transfers\Enums\BatchVerifyStrictness;
use App\Domains\Transfers\Enums\TransferSide;

final class TransfersKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('transfers.create_side', TransferSide::class, 'either', 'Who creates transfers', 'Whether transfers are raised by the source, the destination, or either side.'),
            self::enum('transfers.approve_side', TransferSide::class, 'source', 'Who approves transfers', 'Which side\'s approver must approve: source, destination, or both.'),
            self::bool('transfers.allow_same_warehouse_relocation', false, 'Same-warehouse transfers', 'Allow a transfer whose source and destination warehouse are the same.'),
            self::pct('transfers.discrepancy.tolerance_percent', '0', 'Discrepancy tolerance (%)', 'Shortage percentage per line that is auto-accepted without review.', 'GW'),
            self::int('transfers.overdue_days', 7, 'Overdue after (days)', 'Days in transit after which a transfer is flagged overdue.', 'G', 1, 365),
            self::bool('transfers.require_carrier', false, 'Require carrier', 'Dispatches must record a carrier.'),
            self::enum('transfers.batch_verify.strictness', BatchVerifyStrictness::class, 'lot_and_serial', 'Batch verification', 'Lots and serials scanned at receipt must match the dispatch.'),
        ];
    }
}
