<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class ReceivingKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::pct('receiving.over_receipt.tolerance_percent', '0', 'Over-receipt tolerance (%)', 'Percentage above the ordered quantity that may be received without override.', 'GWCI'),
            self::dec('receiving.over_receipt.tolerance_absolute', '0', 'Over-receipt tolerance (absolute)', 'Absolute base-unit quantity above ordered that may be received without override.', 'GWCI', '0'),
            self::bool('receiving.direct_receipt.enabled', false, 'Direct receipts', 'Allow goods receipts without a purchase order (permission still required).', 'GW'),
            self::bool('receiving.require_lot_for_lot_items', true, 'Require lots', 'Lot-tracked items must carry a lot at receipt.'),
            self::bool('receiving.require_serials_for_serial_items', true, 'Require serials', 'Serial-tracked items must carry one serial per unit at receipt.'),
            self::bool('receiving.auto_create_supplier_return_for_rejected', true, 'Draft returns for rejections', 'Create a draft supplier return when receipt lines have rejected quantities.'),
            self::bool('receiving.putaway.enabled', true, 'Put-away tasks', 'Create put-away tasks from the receiving location after posting.', 'GW'),
            self::bool('receiving.require_approval_before_posting', false, 'Receipt approval', 'Goods receipts need approval before posting.', 'GW'),
        ];
    }
}
