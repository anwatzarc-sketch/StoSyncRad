<?php

use App\Domains\CustomerPortal\CustomerPermissions;
use App\Domains\Platform\Config\Keys\ApiKeys;
use App\Domains\Platform\Config\Keys\ApprovalKeys;
use App\Domains\Platform\Config\Keys\CatalogKeys;
use App\Domains\Platform\Config\Keys\DbKeys;
use App\Domains\Platform\Config\Keys\ExchangeKeys;
use App\Domains\Platform\Config\Keys\FeatureKeys;
use App\Domains\Platform\Config\Keys\FulfillmentKeys;
use App\Domains\Platform\Config\Keys\IdentityKeys;
use App\Domains\Platform\Config\Keys\InventoryKeys;
use App\Domains\Platform\Config\Keys\LocaleKeys;
use App\Domains\Platform\Config\Keys\MessagingKeys;
use App\Domains\Platform\Config\Keys\PlanningKeys;
use App\Domains\Platform\Config\Keys\PlatformKeys;
use App\Domains\Platform\Config\Keys\PortalKeys;
use App\Domains\Platform\Config\Keys\ProcurementKeys;
use App\Domains\Platform\Config\Keys\ReceivingKeys;
use App\Domains\Platform\Config\Keys\ReportingKeys;
use App\Domains\Platform\Config\Keys\SecurityKeys;
use App\Domains\Platform\Config\Keys\StockKeys;
use App\Domains\Platform\Config\Keys\StocktakingKeys;
use App\Domains\Platform\Config\Keys\TransfersKeys;
use App\Domains\Platform\Permissions;
use App\Domains\ReportingEngine\Datasets\DatasetCatalog;

/*
|--------------------------------------------------------------------------
| Platform wiring (infrastructure only — business configuration lives in the
| configuration registry, see App\Domains\Platform\Config).
|--------------------------------------------------------------------------
*/

return [

    // Engineering codename. Never shown to end users; the deployed brand comes from the active brand kit.
    'codename' => 'StoSyncRad',
    'version' => '2.0.0',

    // Classes providing permission definitions (Chapter 04 §4.4). Order = module order in the UI.
    'permission_providers' => [
        Permissions::class,
        App\Domains\IdentityAccess\Permissions::class,
        App\Domains\Organization\Permissions::class,
        App\Domains\Catalog\Permissions::class,
        App\Domains\Partners\Permissions::class,
        App\Domains\Inventory\Permissions::class,
        App\Domains\Planning\Permissions::class,
        App\Domains\Procurement\Permissions::class,
        App\Domains\Receiving\Permissions::class,
        App\Domains\Transfers\Permissions::class,
        App\Domains\Fulfillment\Permissions::class,
        App\Domains\Stocktaking\Permissions::class,
        App\Domains\ReportingEngine\Permissions::class,
        App\Domains\Audit\Permissions::class,
        App\Domains\Messaging\Permissions::class,
        App\Domains\DataExchange\Permissions::class,
        App\Domains\Sales\Permissions::class,
        App\Domains\Billing\Permissions::class,
        App\Domains\CustomerPortal\Permissions::class,
        CustomerPermissions::class,
    ],

    // Classes providing configuration key definitions (Chapter 03 §3.2 / Appendix C).
    'config_key_providers' => [
        PlatformKeys::class,
        LocaleKeys::class,
        IdentityKeys::class,
        SecurityKeys::class,
        CatalogKeys::class,
        InventoryKeys::class,
        StockKeys::class,
        ProcurementKeys::class,
        ReceivingKeys::class,
        TransfersKeys::class,
        FulfillmentKeys::class,
        StocktakingKeys::class,
        PlanningKeys::class,
        ApprovalKeys::class,
        ReportingKeys::class,
        MessagingKeys::class,
        FeatureKeys::class,
        ApiKeys::class,
        DbKeys::class,
        ExchangeKeys::class,
        PortalKeys::class,
    ],

    // Directories scanned for SystemEnum implementations (Chapter 05 §5.2).
    'enum_directories' => [
        app_path('Support/Enums'),
        app_path('Domains'),
    ],

    // Directories scanned for TableSchema implementations (Chapter 05 §5.3).
    'schema_directories' => [
        app_path('Domains'),
    ],

    // Reporting datasets (Chapter 10 §10.2).
    'dataset_providers' => [
        DatasetCatalog::class,
    ],

    // Route names reachable without a permission binding (Chapter 04 §4.4, Appendix B).
    'public_route_names' => [
        'api.v1.auth.*', 'api.v1.me', 'api.v1.me.*', 'api.v1.meta.*', 'api.v1.integrations.*',
        'health.live', 'health.ready', 'setup.*', 'login', 'login.attempt', 'logout', 'app', 'app.*',
        'password.request', 'password.email', 'password.reset', 'password.update',
        'mfa.challenge', 'mfa.verify', 'brand.manifest', 'brand.theme', 'brand.asset', 'offline',
        'pwa.reset', 'pwa.sw', 'password.reset.page', 'storage.local', 'marketing.*',
        // Customer portal: visitor pages and API (customer pages use their own portal:customer guard)
        'portal.public.*', 'portal.dashboard', 'portal.catalog', 'portal.product', 'portal.cart', 'portal.quotations', 'portal.quotation',
        'portal.orders', 'portal.order', 'portal.invoices', 'portal.invoice', 'portal.payments', 'portal.payment', 'portal.account', 'portal.team',
        'api.v1.portal.public.*',
    ],

    // Route names reachable before Platform Setup is locked.
    'pre_setup_route_names' => [
        'health.live', 'health.ready', 'setup.*', 'brand.manifest', 'brand.theme', 'brand.asset', 'offline', 'pwa.reset', 'pwa.sw', 'sanctum.csrf-cookie', 'api.v1.meta.brand',
        'marketing.robots',
    ],

    // Connection used to persist failed-attempt audit rows after a rollback (Chapter 09 §9.9).
    'failed_attempt_audit_connection' => env('AUDIT_FAILED_ATTEMPT_CONNECTION', 'mysql_audit'),

    // Connection for document-number allocation. Null = allocate on the caller's connection
    // (used in tests, where seeded sequences live inside the test transaction).
    'numbering_connection' => env('NUMBERING_CONNECTION', 'mysql_numbering'),

    // Sentinel UUID used for "absent" position dimensions so unique constraints stay total (Chapter 07 §7.3).
    'sentinel_uuid' => '00000000-0000-7000-8000-000000000000',

    // Bootstrap token lifetime for first-run setup.
    'setup_token_ttl_minutes' => (int) env('SETUP_TOKEN_TTL_MINUTES', 30),

];
