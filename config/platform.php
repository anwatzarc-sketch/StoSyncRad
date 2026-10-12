<?php

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
        App\Domains\Platform\Permissions::class,
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
    ],

    // Classes providing configuration key definitions (Chapter 03 §3.2 / Appendix C).
    'config_key_providers' => [
        App\Domains\Platform\Config\Keys\PlatformKeys::class,
        App\Domains\Platform\Config\Keys\LocaleKeys::class,
        App\Domains\Platform\Config\Keys\IdentityKeys::class,
        App\Domains\Platform\Config\Keys\SecurityKeys::class,
        App\Domains\Platform\Config\Keys\CatalogKeys::class,
        App\Domains\Platform\Config\Keys\InventoryKeys::class,
        App\Domains\Platform\Config\Keys\StockKeys::class,
        App\Domains\Platform\Config\Keys\ProcurementKeys::class,
        App\Domains\Platform\Config\Keys\ReceivingKeys::class,
        App\Domains\Platform\Config\Keys\TransfersKeys::class,
        App\Domains\Platform\Config\Keys\FulfillmentKeys::class,
        App\Domains\Platform\Config\Keys\StocktakingKeys::class,
        App\Domains\Platform\Config\Keys\PlanningKeys::class,
        App\Domains\Platform\Config\Keys\ApprovalKeys::class,
        App\Domains\Platform\Config\Keys\ReportingKeys::class,
        App\Domains\Platform\Config\Keys\MessagingKeys::class,
        App\Domains\Platform\Config\Keys\FeatureKeys::class,
        App\Domains\Platform\Config\Keys\ApiKeys::class,
        App\Domains\Platform\Config\Keys\DbKeys::class,
        App\Domains\Platform\Config\Keys\ExchangeKeys::class,
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
        App\Domains\ReportingEngine\Datasets\DatasetCatalog::class,
    ],

    // Route names reachable without a permission binding (Chapter 04 §4.4, Appendix B).
    'public_route_names' => [
        'api.v1.auth.*', 'api.v1.me', 'api.v1.me.*', 'api.v1.meta.*', 'api.v1.integrations.*',
        'health.live', 'health.ready', 'setup.*', 'login', 'login.attempt', 'logout', 'app', 'app.*',
        'password.request', 'password.email', 'password.reset', 'password.update',
        'mfa.challenge', 'mfa.verify', 'brand.manifest', 'brand.theme', 'brand.asset', 'offline',
        'pwa.reset', 'pwa.sw', 'password.reset.page', 'storage.local',
    ],

    // Route names reachable before Platform Setup is locked.
    'pre_setup_route_names' => [
        'health.live', 'health.ready', 'setup.*', 'brand.manifest', 'brand.theme', 'brand.asset', 'offline', 'pwa.reset', 'pwa.sw', 'sanctum.csrf-cookie', 'api.v1.meta.brand',
    ],

    // Connection used to persist failed-attempt audit rows after a rollback (Chapter 09 §9.9).
    'failed_attempt_audit_connection' => env('AUDIT_FAILED_ATTEMPT_CONNECTION', 'pgsql_audit'),

    // Connection for document-number allocation. Null = allocate on the caller's connection
    // (used in tests, where seeded sequences live inside the test transaction).
    'numbering_connection' => env('NUMBERING_CONNECTION', 'pgsql_numbering'),

    // Sentinel UUID used for "absent" position dimensions so unique constraints stay total (Chapter 07 §7.3).
    'sentinel_uuid' => '00000000-0000-7000-8000-000000000000',

    // Bootstrap token lifetime for first-run setup.
    'setup_token_ttl_minutes' => (int) env('SETUP_TOKEN_TTL_MINUTES', 30),

];
