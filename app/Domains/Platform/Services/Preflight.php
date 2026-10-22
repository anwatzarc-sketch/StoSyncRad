<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\ReportingEngine\Services\ReportingSetup;
use App\Domains\ReportingEngine\Services\ReportRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Environment preflight (Chapter 02 §2.4). Each check reports pass / warn / fail with remediation.
 * Fails block Setup Step 1 and Lock & Launch. Not final: tests substitute the host-dependent checks.
 */
class Preflight
{
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'intl', 'zip', 'bcmath', 'openssl', 'fileinfo', 'ctype', 'json', 'xml', 'tokenizer', 'curl'];

    public const MINIMUM_MYSQL_VERSION = '8.4.0';

    /** @return array<int, array{check: string, status: string, message: string, remediation: ?string}> */
    public function run(): array
    {
        $out = [];
        $add = static function (string $check, string $status, string $message, ?string $remediation = null) use (&$out): void {
            $out[] = compact('check', 'status', 'message', 'remediation');
        };
        $production = app()->isProduction();

        version_compare(PHP_VERSION, '8.3.0', '>=')
            ? $add('php_version', 'pass', 'PHP '.PHP_VERSION)
            : $add('php_version', 'fail', 'PHP '.PHP_VERSION.' is too old', 'Install PHP 8.3 or later.');

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn (string $e) => ! extension_loaded($e)));
        $missing === [] ? $add('php_extensions', 'pass', 'Required PHP extensions loaded')
            : $add('php_extensions', 'fail', 'Missing PHP extensions: '.implode(', ', $missing), 'Enable the listed extensions in php.ini.');
        (extension_loaded('gd') || extension_loaded('imagick')) ? $add('image_extension', 'pass', 'Image processing available')
            : $add('image_extension', 'warn', 'Neither gd nor imagick is loaded; brand icons cannot be generated', 'Enable gd or imagick.');

        try {
            $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;
            $number = preg_match('/^\d+\.\d+\.\d+/', $version, $m) === 1 ? $m[0] : '0.0.0';
            if (stripos($version, 'mariadb') !== false) {
                $add('mysql_version', 'fail', "MariaDB $number is not supported", 'Use MySQL '.self::MINIMUM_MYSQL_VERSION.' or later.');
            } else {
                version_compare($number, self::MINIMUM_MYSQL_VERSION, '>=') ? $add('mysql_version', 'pass', "MySQL $number")
                    : $add('mysql_version', 'fail', "MySQL $number is not supported", 'Upgrade to MySQL '.self::MINIMUM_MYSQL_VERSION.' or later.');
            }
            $session = DB::selectOne('SELECT @@SESSION.max_execution_time AS t, @@SESSION.transaction_isolation AS i');
            (int) $session->t > 0 ? $add('statement_timeout', 'pass', "max_execution_time = {$session->t} ms")
                : $add('statement_timeout', 'warn', 'Queries of the runtime connection have no time limit', 'Set DB_STATEMENT_TIMEOUT_MS (config/database.php timeouts).');
            $session->i === 'READ-COMMITTED' ? $add('isolation_level', 'pass', 'Transactions run READ COMMITTED')
                : $add('isolation_level', 'fail', "Transactions run {$session->i}", 'Keep isolation_level READ COMMITTED on every connection in config/database.php.');
            $this->privilegeProbes($add);
        } catch (Throwable $e) {
            $add('database', 'fail', 'Cannot reach MySQL: '.$e->getMessage(), 'Check DB_* environment variables and that the server is running.');
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $dir) {
            is_writable($dir) ? $add('writable:'.basename($dir), 'pass', "$dir is writable")
                : $add('writable:'.basename($dir), 'fail', "$dir is not writable", 'Grant the web server user write access.');
        }
        try {
            Storage::disk('private')->put('.preflight', 'ok');
            Storage::disk('private')->delete('.preflight');
            $add('private_disk', 'pass', 'Private storage disk is writable');
        } catch (Throwable $e) {
            $add('private_disk', 'fail', 'Private storage disk failed: '.$e->getMessage(), 'Configure the `private` filesystem disk.');
        }

        config('app.key') ? $add('app_key', 'pass', 'APP_KEY is set') : $add('app_key', 'fail', 'APP_KEY is missing', 'Run php artisan key:generate.');
        if ($production) {
            config('app.debug') ? $add('app_debug', 'fail', 'APP_DEBUG is enabled in production', 'Set APP_DEBUG=false.') : $add('app_debug', 'pass', 'Debug disabled');
            str_starts_with((string) config('app.url'), 'https://') ? $add('https', 'pass', 'APP_URL uses HTTPS')
                : $add('https', 'fail', 'APP_URL is not HTTPS', 'Serve the application over HTTPS and set APP_URL accordingly.');
        } else {
            $add('environment', 'warn', 'Running in '.app()->environment().' mode', 'Use APP_ENV=production for live deployments.');
        }
        config('queue.default') !== 'sync' ? $add('queue', 'pass', 'Queue driver: '.config('queue.default'))
            : $add('queue', 'warn', 'Queue driver is sync; exports and notifications run inline', 'Set QUEUE_CONNECTION=database.');
        in_array(config('mail.default'), ['log', 'array'], true) ? $add('mail', 'warn', 'Mail is not delivered (driver: '.config('mail.default').')', 'Configure SMTP in .env.')
            : $add('mail', 'pass', 'Mail transport: '.config('mail.default'));

        return $out;
    }

    /** @param  array<int, array<string, mixed>>  $results */
    public static function hasFailures(array $results): bool
    {
        return collect($results)->contains(fn (array $r) => $r['status'] === 'fail');
    }

    private function privilegeProbes(callable $add): void
    {
        $modifiesLedger = $this->hasPrivilege('DELETE', 'stock_movement_lines')
            || $this->hasPrivilege('UPDATE', 'stock_movement_lines', 'quantity')
            || $this->hasPrivilege('DELETE', 'audit_events');
        $modifiesLedger
            ? $add('runtime_role_append_only', 'fail', 'The runtime account can modify ledger or audit rows', 'Connect with the least-privilege runtime account (stosyncrad_app).')
            : $add('runtime_role_append_only', 'pass', 'Runtime account cannot modify ledger or audit rows');

        $probe = app(ReportingSetup::class)->probeReadOnly();
        if (! $probe['reachable']) {
            $add('reporting_role', 'fail', 'Reporting connection failed: '.($probe['error'] ?? ''), 'Check DB_REPORTING_* variables.');
        } elseif ($probe['read_only']) {
            $add('reporting_role', 'pass', 'Reporting account is read-only');
        } else {
            $add('reporting_role', 'fail', 'Reporting account can write, read base tables or is not read-only', 'Re-run database/mysql/setup.sql for stosyncrad_reporting.');
        }
        DB::connection(ReportRunner::CONNECTION)->disconnect();
    }

    /**
     * Whether the runtime connection's own account holds a privilege on a table (or column), granted
     * globally, on the database, on the table or on the column.
     */
    private function hasPrivilege(string $privilege, string $table, ?string $column = null): bool
    {
        $db = DB::connection();
        $schema = $db->getDatabaseName();
        $grantee = "CONCAT('''', SUBSTRING_INDEX(CURRENT_USER(), '@', 1), '''@''', SUBSTRING_INDEX(CURRENT_USER(), '@', -1), '''')";
        $sql = "SELECT EXISTS (SELECT 1 FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = $grantee AND PRIVILEGE_TYPE = ?)
                    OR EXISTS (SELECT 1 FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = $grantee AND TABLE_SCHEMA = ? AND PRIVILEGE_TYPE = ?)
                    OR EXISTS (SELECT 1 FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = $grantee AND TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PRIVILEGE_TYPE = ?)";
        $bindings = [$privilege, $schema, $privilege, $schema, $table, $privilege];
        if ($column !== null) {
            $sql .= " OR EXISTS (SELECT 1 FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = $grantee AND TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND PRIVILEGE_TYPE = ?)";
            array_push($bindings, $schema, $table, $column, $privilege);
        }

        return (bool) $db->selectOne("$sql AS p", $bindings)->p;
    }
}
