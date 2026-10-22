<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

/**
 * Feature flags (Chapter 03 §3.3). Disabling a feature hides navigation, blocks endpoints
 * with FEATURE_DISABLED and removes its datasets from the reporting catalog.
 */
final class FeatureKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::bool('feature.retail_sales', false, 'Retail sales', 'Retail counter sales orders and deliveries.'),
            self::bool('feature.ecommerce_fulfillment', false, 'E-commerce fulfillment', 'Order intake, pick waves, packing and shipments.'),
            self::bool('feature.customer_returns', true, 'Customer returns', 'RMA-based customer returns.'),
            self::bool('feature.allocation_planning', true, 'Allocation planning', 'HQ allocation plans that generate transfers.'),
            self::bool('feature.serial_tracking', true, 'Serial tracking', 'Serial-tracked items.'),
            self::bool('feature.kits', false, 'Kits', 'Kit items exploded at issue/sale.'),
            self::bool('feature.landed_cost', false, 'Landed cost', 'Landed-cost allocation onto receipts.'),
            self::bool('feature.camera_scanning', true, 'Camera scanning', 'Camera barcode scanning in supported browsers.'),
            self::bool('feature.dark_mode', false, 'Dark mode', 'Dark theme (enable only after verifying brand contrast).'),
            self::bool('feature.production_store', false, 'Production store', 'Issue to and receive from production.'),
            self::bool('feature.asn', false, 'Advance shipment notices', 'Supplier ASNs pre-filling goods receipts.'),
            self::bool('feature.recalls', true, 'Recalls', 'Lot recalls that lock stock everywhere.'),
            self::bool('feature.multi_currency_purchasing', false, 'Multi-currency purchasing', 'Purchase orders in currencies other than the base currency.'),
            self::bool('feature.customer_portal', false, 'Customer portal', 'Public landing page, customer registration and the customer portal (catalog, quotations, orders, invoices, payment confirmations, deliveries).'),
            self::bool('feature.marketing_site', true, 'Product website', 'Public StockSync product pages (home, features, solutions, security, onboarding, contact) for visitors who are not signed in. Off: visitors go straight to sign-in.'),
        ];
    }
}
