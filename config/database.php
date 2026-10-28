<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

/*
|--------------------------------------------------------------------------
| MySQL 8.0.32 or later is the only supported database; 8.4 LTS is the reference
| version (Master Spec 2.0, README §Precedence)
|--------------------------------------------------------------------------
| Five connections map to four least-privilege accounts (database/mysql/setup.sql):
|   mysql            application runtime account (DML; no DDL; append-only tables protected)
|   mysql_deploy     deployment account          (migrations and platform:* commands only)
|   mysql_reporting  reporting reader account    (SELECT on the <db>_reporting views; read-only)
|   mysql_audit      runtime account, separate connection used ONLY to persist
|                    failed-attempt audit rows after a rollback (Chapter 09 §9.9)
|   mysql_numbering  runtime account, short autonomous transactions for document numbers
| Every connection runs READ COMMITTED with a case-sensitive collation, matching the semantics the
| domain code was written against, and exchanges timestamps in UTC (Chapter 05 §5.6).
*/

// TLS to the database server: set DB_SSL_CA (and DB_SSL_VERIFY=false only for self-signed test servers).
$sslOptions = [];
if (extension_loaded('pdo_mysql') && env('DB_SSL_CA')) {
    $sslOptions = [
        (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('DB_SSL_CA'),
        (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT) => (bool) env('DB_SSL_VERIFY', true),
    ];
}

$mysql = static function (string $userEnv, string $passEnv, array $overrides = []) use ($sslOptions): array {
    return array_merge([
        'driver' => 'mysql',
        'url' => env('DB_URL'),
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'stosyncrad'),
        'username' => env($userEnv),
        'password' => env($passEnv),
        'unix_socket' => env('DB_SOCKET', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_0900_as_cs',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => 'InnoDB',
        'timezone' => '+00:00',
        'isolation_level' => 'READ COMMITTED',
        'options' => $sslOptions,
    ], $overrides);
};

return [

    'default' => env('DB_CONNECTION', 'mysql'),

    // Account names used by RuntimeGrants and the append-only triggers.
    'accounts' => [
        'app' => env('DB_USERNAME', 'stosyncrad_app'),
        'deploy' => env('DB_DEPLOY_USERNAME', 'stosyncrad_deploy'),
        'reporting' => env('DB_REPORTING_USERNAME', 'stosyncrad_reporting'),
        'host' => env('DB_ACCOUNT_HOST', '%'),   // the host part the accounts were created with
        // false where the deploy account cannot GRANT (hosting panels such as Plesk): the runtime
        // account then holds UPDATE/DELETE database-wide and only the append-only triggers protect
        // ledger and audit rows.
        'manage_grants' => (bool) env('DB_MANAGE_GRANTS', true),
    ],

    'connections' => [

        'mysql' => $mysql('DB_USERNAME', 'DB_PASSWORD'),

        'mysql_deploy' => $mysql('DB_DEPLOY_USERNAME', 'DB_DEPLOY_PASSWORD'),

        // Its default database is the companion reporting database, which holds only the views.
        'mysql_reporting' => $mysql('DB_REPORTING_USERNAME', 'DB_REPORTING_PASSWORD', [
            'database' => env('DB_REPORTING_DATABASE') ?: env('DB_DATABASE', 'stosyncrad').'_reporting',
        ]),

        'mysql_audit' => $mysql('DB_USERNAME', 'DB_PASSWORD'),

        // Autonomous short transactions for document number allocation (no lock held by postings).
        'mysql_numbering' => $mysql('DB_USERNAME', 'DB_PASSWORD'),

        // Pure-logic unit tests only. Never evidence for locking, constraints or recovery.
        'sqlite_unit' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    /*
    | Session-level safety settings applied on connect for each connection (milliseconds).
    |   statement_timeout  → max_execution_time (MySQL applies it to SELECT statements)
    |   lock_timeout       → innodb_lock_wait_timeout (whole seconds, rounded up, minimum 1)
    | MySQL has no idle-in-transaction timeout; wait_timeout only closes idle connections.
    */
    'timeouts' => [
        'mysql' => [
            'statement_timeout' => (int) env('DB_STATEMENT_TIMEOUT_MS', 15000),
            'lock_timeout' => (int) env('DB_LOCK_TIMEOUT_MS', 5000),
        ],
        'mysql_reporting' => [
            'statement_timeout' => (int) env('DB_REPORTING_STATEMENT_TIMEOUT_MS', 15000),
            'lock_timeout' => (int) env('DB_REPORTING_LOCK_TIMEOUT_MS', 2000),
        ],
        'mysql_audit' => [
            'statement_timeout' => 5000,
            'lock_timeout' => 2000,
        ],
        'mysql_numbering' => [
            'statement_timeout' => 5000,
            'lock_timeout' => (int) env('DB_LOCK_TIMEOUT_MS', 5000),
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
