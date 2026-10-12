<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Services\PlatformHealth;
use Illuminate\Console\Command;

/**
 * `platform:health` (Chapter 16 §16.3 step 8): environment preflight plus runtime invariants —
 * append-only triggers, reporting role read-only, setup state, queue, outbox and scheduler heartbeat.
 * Exits non-zero on any failure; `--strict` also fails on warnings.
 */
final class PlatformHealthCommand extends Command
{
    protected $signature = 'platform:health {--strict : Treat warnings as failures} {--json : Print JSON}';

    protected $description = 'Check the deployment and its runtime invariants.';

    public function handle(PlatformHealth $health): int
    {
        $checks = $health->checks();
        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Check', 'Status', 'Message', 'Remediation'], array_map(fn ($c) => [$c['check'], strtoupper($c['status']), $c['message'], $c['remediation'] ?? ''], $checks));
        }
        $statuses = array_column($checks, 'status');
        $failed = in_array('fail', $statuses, true) || ($this->option('strict') && in_array('warn', $statuses, true));
        $failed ? $this->error('Health check failed.') : $this->info('Healthy.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
