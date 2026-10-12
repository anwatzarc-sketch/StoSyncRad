<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class ProcurementKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::bool('procurement.po.revision_requires_reapproval', true, 'Revision re-approval', 'Material changes to an approved PO send it back for approval.'),
            self::bool('procurement.po.block_suppliers_on_hold', true, 'Block on-hold suppliers', 'Prevent new POs to suppliers on hold or blacklisted.'),
            self::bool('procurement.po.require_purchase_request', false, 'Require purchase request', 'Purchase orders must reference an approved purchase request.'),
            self::int('procurement.po.max_lines', 500, 'Maximum PO lines', 'Maximum lines on one purchase order.', 'G', 1, 5000),
        ];
    }
}
