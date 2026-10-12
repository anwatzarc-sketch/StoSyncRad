<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Setup\HeadlessSetup;
use App\Support\Exceptions\DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Headless Platform Setup from a Config Bundle (Chapter 02 §2.3, Chapter 16 §16.4). The Super Admin
 * password is never part of the bundle: it is prompted for, or read from SETUP_SUPERADMIN_PASSWORD_FILE.
 */
final class PlatformSetupCommand extends Command
{
    protected $signature = 'platform:setup
        {--bundle= : Path to bundle.json}
        {--brand= : Path to a brand kit zip (brand.json + assets/<kind>.<ext>)}
        {--admin-name= : Super Admin full name}
        {--admin-email= : Super Admin email}
        {--admin-username= : Super Admin username (defaults to the email)}
        {--non-interactive : Never prompt (requires the admin options and SETUP_SUPERADMIN_PASSWORD_FILE)}';

    protected $description = 'Run every Platform Setup step from a Config Bundle and lock setup.';

    public function handle(HeadlessSetup $headless): int
    {
        $path = (string) $this->option('bundle');
        if ($path === '' || ! is_file($path)) {
            $this->error('Give --bundle=/path/to/bundle.json (export one with platform:bundle:export or start from a reference bundle).');

            return self::INVALID;
        }
        $bundle = json_decode((string) file_get_contents($path), true);
        if (! is_array($bundle)) {
            $this->error('The bundle is not valid JSON.');

            return self::INVALID;
        }
        $interactive = ! $this->option('non-interactive') && $this->input->isInteractive();
        $admin = [
            'full_name' => (string) ($this->option('admin-name') ?: ($interactive ? $this->ask('Super Admin full name') : '')),
            'email' => (string) ($this->option('admin-email') ?: ($interactive ? $this->ask('Super Admin email') : '')),
            'username' => $this->option('admin-username') ?: null,
            'password' => $this->password($interactive),
        ];
        if ($admin['full_name'] === '' || $admin['email'] === '' || $admin['password'] === '') {
            $this->error('The Super Admin name, email and password are required.');

            return self::INVALID;
        }

        try {
            $result = $headless->run($bundle, $admin, $this->option('brand') ?: null, fn (string $line) => $this->line($line));
        } catch (ValidationException $e) {
            $this->error('Setup stopped: '.$e->getMessage());
            foreach ($e->errors() as $field => $messages) {
                $this->line("  - {$field}: ".implode(' ', (array) $messages));
            }
            $this->line('Fix the bundle and run the command again; completed steps are resumed.');

            return self::FAILURE;
        } catch (DomainException $e) {
            $this->error('Setup stopped: '.$e->getMessage());
            foreach ((array) ($e->details['errors'] ?? []) as $error) {
                $this->line('  - '.(is_array($error) ? (($error['section'] ?? '').': '.($error['message'] ?? json_encode($error))) : $error));
            }
            $this->line('Fix the bundle and run the command again; completed steps are resumed.');

            return self::FAILURE;
        }

        foreach ($result['warnings'] as $w) {
            $this->warn("{$w['section']}: {$w['message']}");
        }
        foreach ($result['secrets'] as $what => $secret) {
            $this->line("Secret for {$what} (shown once): {$secret}");
        }
        $this->newLine();
        $this->info('Platform Setup is complete and locked.');
        $this->line("Sign in as <options=bold>{$result['super_admin']}</>. Multi-factor enrolment is required at first sign-in when policy demands it;");
        $this->line('keep the recovery codes shown then in a safe place. Next: php artisan platform:health --strict');

        return self::SUCCESS;
    }

    private function password(bool $interactive): string
    {
        $file = (string) env('SETUP_SUPERADMIN_PASSWORD_FILE', '');
        if ($file !== '') {
            if (! is_readable($file)) {
                $this->error('SETUP_SUPERADMIN_PASSWORD_FILE is not readable.');

                return '';
            }

            return rtrim((string) file_get_contents($file), "\r\n");
        }
        if (! $interactive) {
            return '';
        }
        $first = (string) $this->secret('Super Admin password');
        if ($first !== (string) $this->secret('Repeat the password')) {
            $this->error('The passwords differ.');

            return '';
        }

        return $first;
    }
}
