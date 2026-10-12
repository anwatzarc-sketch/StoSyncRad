<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Environment preflight (Chapter 02 §2.4). Each check reports pass / warn / fail with remediation.
 * Fails block Setup Step 1 and Lock & Launch. Not final: tests substitute the host-dependent checks.
 */
class Preflight
{
    public const REQUIRED_EXTENSIONS = ['pdo_pgsql', 'pgsql', 'mbstring', 'intl', 'zip', 'bcmath', 'openssl', 'fileinfo', 'ctype', 'json', 'xml', 'tokenizer', 'curl'];

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
            $version = (int) DB::selectOne('SHOW server_version_num')->server_version_num;
            $version >= 160000 ? $add('postgres_version', 'pass', 'PostgreSQL '.intdiv($version, 10000))
                : $add('postgres_version', 'fail', 'PostgreSQL '.intdiv($version, 10000).' is not supported', 'Upgrade to PostgreSQL 16 or later.');
            $timeout = DB::selectOne('SHOW statement_timeout')->statement_timeout;
            $timeout !== '0' ? $add('statement_timeout', 'pass', "statement_timeout = $timeout")
                : $add('statement_timeout', 'warn', 'statement_timeout is unlimited for the runtime role', 'Run database/postgres/roles.sql.');
            $this->privilegeProbes($add);
        } catch (Throwable $e) {
            $add('database', 'fail', 'Cannot reach PostgreSQL: '.$e->getMessage(), 'Check DB_* environment variables and that the server is running.');
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
        $runtime = DB::selectOne("SELECT has_table_privilege(current_user, 'stock_movement_lines', 'DELETE') AS d,
                                         has_column_privilege(current_user, 'stock_movement_lines', 'quantity', 'UPDATE') AS u,
                                         has_table_privilege(current_user, 'audit_events', 'DELETE') AS a");
        ($runtime->d || $runtime->u || $runtime->a)
            ? $add('runtime_role_append_only', 'fail', 'The runtime role can modify ledger or audit rows', 'Connect with the least-privilege runtime role (stosyncrad_app).')
            : $add('runtime_role_append_only', 'pass', 'Runtime role cannot modify ledger or audit rows');

        try {
            $reporting = DB::connection('pgsql_reporting');
            $readOnly = $reporting->selectOne('SHOW default_transaction_read_only')->default_transaction_read_only;
            $canWrite = $reporting->selectOne("SELECT has_table_privilege(current_user, 'stock_movements', 'INSERT') AS w")->w;
            ($readOnly === 'on' && ! $canWrite)
                ? $add('reporting_role', 'pass', 'Reporting role is read-only')
                : $add('reporting_role', 'fail', 'Reporting role can write or is not read-only', 'Re-run database/postgres/roles.sql for stosyncrad_reporting.');
            $reporting->disconnect();
        } catch (Throwable $e) {
            $add('reporting_role', 'fail', 'Reporting connection failed: '.$e->getMessage(), 'Check DB_REPORTING_* variables.');
        }
    }
}
