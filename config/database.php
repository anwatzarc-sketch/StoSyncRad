<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| PostgreSQL is the only supported database (Master Spec 2.0, README §Precedence)
|--------------------------------------------------------------------------
| Four connections map to four least-privilege database roles:
|   pgsql            application runtime role  (DML; no DDL; append-only tables protected)
|   pgsql_deploy     deployment role            (migrations and platform:* commands only)
|   pgsql_reporting  reporting reader role      (SELECT on reporting.* views; read-only txns)
|   pgsql_audit      runtime role, separate connection used ONLY to persist
|                    failed-attempt audit rows after a rollback (Chapter 09 §9.9)
*/

$pgsql = static function (string $userEnv, string $passEnv, array $overrides = []): array {
    return array_merge([
        'driver' => 'pgsql',
        'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'stosyncrad'),
        'username' => env($userEnv),
        'password' => env($passEnv),
        'charset' => env('DB_CHARSET', 'utf8'),
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'timezone' => 'UTC',   // timestamps are stored and exchanged in UTC (Chapter 05 §5.6)
        'sslmode' => env('DB_SSLMODE', 'prefer'),
        'options' => [],
    ], $overrides);
};

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    // Login names of the runtime and reporting roles, used by migrations for GRANT/REVOKE.
    'roles' => [
        'app' => env('DB_USERNAME', 'stosyncrad_app'),
        'reporting' => env('DB_REPORTING_USERNAME', 'stosyncrad_reporting'),
    ],

    'connections' => [

        'pgsql' => $pgsql('DB_USERNAME', 'DB_PASSWORD'),

        'pgsql_deploy' => $pgsql('DB_DEPLOY_USERNAME', 'DB_DEPLOY_PASSWORD'),

        'pgsql_reporting' => $pgsql('DB_REPORTING_USERNAME', 'DB_REPORTING_PASSWORD', [
            'search_path' => 'reporting,public',
        ]),

        'pgsql_audit' => $pgsql('DB_USERNAME', 'DB_PASSWORD'),

        // Autonomous short transactions for document number allocation (no lock held by postings).
        'pgsql_numbering' => $pgsql('DB_USERNAME', 'DB_PASSWORD'),

        // Pure-logic unit tests only. Never evidence for locking, constraints or recovery.
        'sqlite_unit' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    /*
    | Session-level safety settings applied on connect for each role (milliseconds).
    | Role-level defaults are also set in database/postgres/roles.sql; these allow the
    | application to tighten (never loosen beyond the role) per environment.
    */
    'timeouts' => [
        'pgsql' => [
            'statement_timeout' => (int) env('DB_STATEMENT_TIMEOUT_MS', 15000),
            'lock_timeout' => (int) env('DB_LOCK_TIMEOUT_MS', 5000),
            'idle_in_transaction_session_timeout' => (int) env('DB_IDLE_IN_TRANSACTION_TIMEOUT_MS', 30000),
        ],
        'pgsql_reporting' => [
            'statement_timeout' => (int) env('DB_REPORTING_STATEMENT_TIMEOUT_MS', 15000),
            'lock_timeout' => (int) env('DB_REPORTING_LOCK_TIMEOUT_MS', 2000),
            'idle_in_transaction_session_timeout' => (int) env('DB_REPORTING_IDLE_TIMEOUT_MS', 180000),
        ],
        'pgsql_audit' => [
            'statement_timeout' => 5000,
            'lock_timeout' => 2000,
            'idle_in_transaction_session_timeout' => 10000,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'stosyncrad'), '_').'_database_'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];
