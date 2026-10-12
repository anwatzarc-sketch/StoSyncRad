<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Catalog\Enums\OutOfStockBehavior;
use App\Domains\Fulfillment\Enums\FulfillmentPostOn;
use App\Domains\Fulfillment\Enums\PartialFulfillmentPolicy;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class FulfillmentKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('fulfillment.request.partial_fulfillment', PartialFulfillmentPolicy::class, 'allow', 'Partial fulfillment', 'Whether stock requests may be fulfilled in several issues.', 'GW'),
            self::int('fulfillment.request.max_lines', 200, 'Maximum request lines', 'Maximum lines on one stock request.', 'G', 1, 2000),
            self::bool('fulfillment.issue.direct_issue.enabled', false, 'Direct issues', 'Allow issues without a stock request (permission still required).', 'GW'),
            self::enum('fulfillment.returns.default_condition', StockCondition::class, 'returned_pending_inspection', 'Returned stock condition', 'Condition assigned to returned goods before inspection.'),
            self::uuid('fulfillment.sales.walk_in_customer_partner_id', null, 'Walk-in customer', 'Partner used for anonymous retail counter sales.', 'GB'),
            self::enum('fulfillment.sales.post_on', FulfillmentPostOn::class, 'ship', 'Post sales on', 'Remove stock when shipped, or only on delivery confirmation.', 'GW'),
            self::enum('fulfillment.ecommerce.backorder_policy', OutOfStockBehavior::class, 'block', 'E-commerce backorders', 'Behaviour when an e-commerce order exceeds available stock.'),
            self::int('fulfillment.ecommerce.pick_wave_size', 50, 'Pick wave size', 'Orders grouped into one pick wave.', 'GW', 1, 1000),
            self::bool('fulfillment.customer_return.require_rma', true, 'Require RMA', 'Customer returns must be authorized before receipt.'),
        ];
    }
}
