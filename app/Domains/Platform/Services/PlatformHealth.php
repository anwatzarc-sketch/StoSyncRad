<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Platform\Config\PlatformConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runtime health (Chapter 14 §14.9, Chapter 16 §16.3): the environment preflight (which already
 * probes the DB roles) plus invariants a running deployment must keep. Each check: {check, status: pass|warn|fail, message, remediation?}.
 * Used by `platform:health`, the admin health endpoint and `/health/ready`.
 */
final class PlatformHealth
{
    public const HEARTBEAT_KEY = 'platform.scheduler.heartbeat';

    /** Ledger, audit and history tables that must refuse UPDATE/DELETE. */
    public const APPEND_ONLY_TABLES = ['stock_movements', 'stock_movement_lines', 'audit_events', 'security_events', 'approval_records',
        'configuration_entry_versions', 'valuation_layer_consumptions', 'valuation_variances', 'landed_cost_allocations', 'report_definition_versions',
        'template_versions', 'shipment_events', 'purchase_order_revisions', 'transfer_discrepancy_resolutions', 'item_category_moves'];

    public function __construct(
        private readonly Preflight $preflight,
        private readonly SetupState $setup,
        private readonly PlatformConfig $config,
    ) {}

    /** @return array<int, array{check: string, status: string, message: string, remediation?: string}> */
    public function checks(): array
    {
        $checks = $this->preflight->run();
        $checks[] = $this->setupState();
        $checks[] = $this->appendOnly();
        $checks[] = $this->scheduler();
        $checks[] = $this->queue();
        $checks[] = $this->outbox();

        return $checks;
    }

    /** Liveness of the dependencies a request needs (database + cache); for `/health/ready`. */
    public function ready(): array
    {
        $out = ['database' => false, 'cache' => false];
        try {
            DB::select('select 1');
            $out['database'] = true;
        } catch (Throwable) {
        }
        try {
            Cache::put('platform.health.probe', 1, 10);
            $out['cache'] = Cache::get('platform.health.probe') === 1;
        } catch (Throwable) {
        }

        return $out;
    }

    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    private function setupState(): array
    {
        $this->setup->refresh();

        return $this->setup->isComplete()
            ? ['check' => 'setup_state', 'status' => 'pass', 'message' => 'Platform Setup is locked ('.$this->setup->state()->value.').']
            : ['check' => 'setup_state', 'status' => 'warn', 'message' => 'Platform Setup is not completed.', 'remediation' => 'Run php artisan platform:bootstrap and finish setup.'];
    }

    private function appendOnly(): array
    {
        $present = collect(DB::select("SELECT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname LIKE 'trg_%_append_only' AND NOT t.tgisinternal"))
            ->pluck('relname')->all();
        $missing = array_values(array_diff(self::APPEND_ONLY_TABLES, $present));

        return $missing === []
            ? ['check' => 'append_only', 'status' => 'pass', 'message' => count(self::APPEND_ONLY_TABLES).' ledger/audit tables refuse updates and deletes.']
            : ['check' => 'append_only', 'status' => 'fail', 'message' => 'Append-only protection missing on: '.implode(', ', $missing).'.',
                'remediation' => 'Run php artisan migrate --database=pgsql_deploy --force and php artisan platform:upgrade.'];
    }

    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $window = max(1, $this->config->int('platform.health.scheduler_heartbeat_minutes'));
        if (! is_string($beat)) {
            return ['check' => 'scheduler', 'status' => 'warn', 'message' => 'No scheduler heartbeat recorded yet.',
                'remediation' => 'Run `php artisan schedule:run` every minute (cron / hosting scheduled task).'];
        }
        $age = now()->diffInMinutes($beat, true);

        return $age <= $window
            ? ['check' => 'scheduler', 'status' => 'pass', 'message' => 'Scheduler heartbeat '.(int) $age.' min ago.']
            : ['check' => 'scheduler', 'status' => 'fail', 'message' => 'Scheduler heartbeat is '.(int) $age." min old (limit {$window}).",
                'remediation' => 'Check the cron entry for php artisan schedule:run.'];
    }

    private function queue(): array
    {
        $failed = DB::table('failed_jobs')->count();
        $stale = DB::table('jobs')->where('available_at', '<', now()->subMinutes(15)->getTimestamp())->whereNull('reserved_at')->count();
        if ($stale > 0) {
            return ['check' => 'queue_backlog', 'status' => 'fail', 'message' => "$stale queued jobs waiting over 15 minutes.", 'remediation' => 'Start or restart the queue workers (queue:work).'];
        }

        return $failed > 0
            ? ['check' => 'queue_backlog', 'status' => 'warn', 'message' => "$failed failed jobs.", 'remediation' => 'Inspect with php artisan queue:failed; retry or forget them.']
            : ['check' => 'queue_backlog', 'status' => 'pass', 'message' => 'Queue is draining; no failed jobs.'];
    }

    private function outbox(): array
    {
        $dead = DB::table('outbox_events')->where('status', 'dead')->count();
        $backlog = DB::table('outbox_events')->where('status', 'pending')->where('created_at', '<', now()->subMinutes(10))->count();
        if ($backlog > 0) {
            return ['check' => 'outbox', 'status' => 'fail', 'message' => "$backlog events pending over 10 minutes.", 'remediation' => 'Check the scheduler (outbox:relay runs every minute).'];
        }

        return $dead > 0
            ? ['check' => 'outbox', 'status' => 'warn', 'message' => "$dead dead-lettered events.", 'remediation' => 'Review in Administration → Outbox and retry.']
            : ['check' => 'outbox', 'status' => 'pass', 'message' => 'Outbox is relaying.'];
    }
}
