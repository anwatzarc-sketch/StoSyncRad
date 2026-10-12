<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Services\ConfigBundleService;
use App\Support\Exceptions\DomainException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * `platform:bundle:import` — dry-run by default (prints the per-section diff); `--apply` writes it
 * in one transaction (Chapter 03 §3.8).
 */
final class PlatformBundleImportCommand extends Command
{
    protected $signature = 'platform:bundle:import {path : bundle.json} {--apply : Apply the bundle (default is a dry run)}';

    protected $description = 'Validate and apply (or dry-run) a Config Bundle on this deployment.';

    public function handle(ConfigBundleService $bundles): int
    {
        $bundle = json_decode((string) @file_get_contents((string) $this->argument('path')), true);
        if (! is_array($bundle)) {
            $this->error('The bundle file is missing or is not valid JSON.');

            return self::INVALID;
        }
        $actor = UserAccount::query()->where('is_super_admin', true)->orderBy('created_at')->first();
        try {
            $report = $bundles->import($bundle, $actor, dryRun: ! $this->option('apply'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());
            foreach ((array) ($e->details['errors'] ?? []) as $error) {
                $this->line("  - {$error['section']}: {$error['message']}");
            }

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error($e->getMessage());
            foreach ($e->errors() as $field => $messages) {
                $this->line("  - {$field}: ".implode(' ', (array) $messages));
            }

            return self::FAILURE;
        }
        $rows = [];
        foreach ($report['sections'] as $section => $counts) {
            $rows[] = [$section, $counts['created'] ?? 0, $counts['updated'] ?? 0, $counts['unchanged'] ?? 0];
        }
        $this->table(['Section', 'Created', 'Updated', 'Unchanged'], $rows);
        foreach ($report['warnings'] as $w) {
            $this->warn("{$w['section']}: {$w['message']}");
        }
        foreach ($report['secrets'] as $what => $secret) {
            $this->line("Secret for {$what} (shown once): {$secret}");
        }
        $report['dry_run'] ? $this->comment('Dry run — nothing was changed. Re-run with --apply to write.') : $this->info('Bundle applied.');

        return self::SUCCESS;
    }
}
