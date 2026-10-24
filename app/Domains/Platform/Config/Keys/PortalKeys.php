<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Billing\Enums\InvoiceBasis;
use App\Domains\CustomerPortal\Enums\AvailabilityDisplay;
use App\Domains\CustomerPortal\Enums\RegistrationMode;
use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

/**
 * Customer Portal and billing (CP-1.1). The portal itself is switched on with feature.customer_portal.
 * Nothing here changes how staff work; prices shown to customers are the items' list prices.
 */
final class PortalKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::enum('portal.registration.mode', RegistrationMode::class, 'approval', 'Customer registration',
                'approval: anyone may register and staff approve; invitation_only: staff invite contacts of existing customers; closed: no new accounts.'),
            self::int('portal.registration.verification_hours', 24, 'Email verification link validity', 'Hours a registration email link stays valid.', 'G', 1, 168),
            self::int('portal.invitation.valid_days', 7, 'Invitation validity', 'Days an invitation link stays valid.', 'G', 1, 60),
            self::int('portal.team.max_members', 25, 'Portal users per customer',
                'Most portal accounts (active or being set up, plus open invitations) one customer may have. 0 = no limit.', 'G', 0, 1000),
            self::bool('portal.catalog.show_prices', true, 'Show list prices', 'Show item list prices to signed-in customers (never to public visitors).'),
            self::enum('portal.catalog.availability', AvailabilityDisplay::class, 'band', 'Availability shown to customers',
                'band: in stock / low / out of stock; quantity: available quantity; hidden. Stock locations are never shown.'),
            self::int('portal.catalog.low_stock_threshold', 10, 'Low-stock threshold', 'Available quantity at or below which an item shows as low stock.', 'G', 0, 1000000),
            self::bool('portal.catalog.public_preview', false, 'Public category preview', 'Show catalogue categories (no prices or stock) on the public landing page.'),
            self::uuid('portal.orders.warehouse_id', null, 'Ordering warehouse', 'Warehouse that fulfils customer portal orders; availability is shown for it.'),
            self::bool('portal.orders.direct_ordering', true, 'Direct ordering', 'Customers may order items with a list price directly; otherwise they request a quotation.'),
            self::int('portal.orders.max_lines', 100, 'Maximum order lines', 'Maximum lines on one portal order or quotation request.', 'G', 1, 1000),
            self::int('portal.quotation.validity_days', 14, 'Quotation validity', 'Default days a sent quotation can be accepted.', 'G', 1, 365),
            self::int('portal.evidence.max_file_mb', 10, 'Evidence file size', 'Largest payment evidence file (MB).', 'G', 1, 25),
            self::int('portal.evidence.max_files', 5, 'Evidence files per submission', 'Most evidence files attached to one payment confirmation.', 'G', 1, 10),
            self::pct('billing.invoice.tax_rate_percent', '0', 'Fallback invoice tax rate (%)', 'Used only when no tax rate is marked as default under Customers & billing → Tax rates.')
                ->managedBy('billing.tax.manage'),
            self::enum('billing.invoice.basis', InvoiceBasis::class, 'ordered', 'Invoice from orders',
                'ordered: bill the ordered quantity (advance billing); delivered: bill only what has been delivered.'),
            self::int('billing.invoice.default_due_days', 30, 'Default payment due (days)', 'Days until an invoice is due when the customer has no payment terms.', 'G', 0, 365),
        ];
    }
}
