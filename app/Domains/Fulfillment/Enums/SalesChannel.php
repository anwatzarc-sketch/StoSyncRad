<?php

declare(strict_types=1);

namespace App\Domains\Fulfillment\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Sales channel (stock-side fulfillment only). */
enum SalesChannel: string implements SystemEnum
{
    use HasSystemEnum;

    case RETAIL_COUNTER = 'retail_counter';
    case ECOMMERCE = 'ecommerce';
    case WHOLESALE = 'wholesale';
    case CUSTOMER_PORTAL = 'customer_portal';

    /** Feature flag that must be on for orders of this channel. */
    public function featureFlag(): string
    {
        return match ($this) {
            self::ECOMMERCE => 'feature.ecommerce_fulfillment',
            self::CUSTOMER_PORTAL => 'feature.customer_portal',
            self::RETAIL_COUNTER, self::WHOLESALE => 'feature.retail_sales',
        };
    }
}
