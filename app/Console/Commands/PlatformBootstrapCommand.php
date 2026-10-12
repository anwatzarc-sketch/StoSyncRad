<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Services\Preflight;
use App\Domains\Platform\Setup\SetupService;
use Illuminate\Console\Command;

/**
 * First-run bootstrap (Chapter 02 §2.3): verify the environment, open Platform Setup and print a
 * single-use, expiring token for the setup wizard (only its hash is stored).
 */
final class PlatformBootstrapCommand extends Command
{
    protected $signature = 'platform:bootstrap {--token-ttl=30 : Token lifetime in minutes} {--print-url : Print the full setup URL}';

    protected $description = 'Check preconditions, open Platform Setup and issue a single-use setup token.';

    public function handle(Preflight $preflight, SetupService $setup): int
    {
        $results = $preflight->run();
        $this->table(['Check', 'Status', 'Message', 'Remediation'], array_map(fn ($r) => [$r['check'], strtoupper($r['status']), $r['message'], $r['remediation'] ?? ''], $results));
        if (Preflight::hasFailures($results)) {
            $this->error('Preconditions failed — fix the items marked FAIL and run this command again.');

            return self::FAILURE;
        }
        $ttl = max(5, min(240, (int) $this->option('token-ttl')));
        $token = $setup->bootstrap($ttl);
        $row = $setup->current();

        $this->newLine();
        $this->info("Platform Setup is open (deployment {$row->deployment_id}).");
        $this->line("Setup token (valid {$ttl} minutes, single use — it is not stored and cannot be shown again):");
        $this->line("  <options=bold>{$token}</>");
        if ($this->option('print-url')) {
            $this->line('Open: '.rtrim((string) config('app.url'), '/').'/setup?token='.$token);
        }
        $this->line('Headless alternative: php artisan platform:setup --bundle=bundle.json [--brand=brandkit.zip]');

        return self::SUCCESS;
    }
}
