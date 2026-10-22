<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Services;

use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\ReportingEngine\Enums\ReportSlotKind;
use App\Support\Database\AdvisoryLock;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DB-backed semaphore capping concurrent report executions per pool (Chapter 10 §10.5) so reports
 * can never exhaust the reporting connection budget or disturb postings. Slots expire on their own
 * if a worker dies.
 */
final class ExecutionSlots
{
    public function __construct(private readonly PlatformConfig $config) {}

    public function acquire(ReportSlotKind $kind, ?string $runId, int $timeoutSeconds): string
    {
        $limit = $kind === ReportSlotKind::INTERACTIVE
            ? $this->config->int('reporting.max_concurrent_interactive')
            : $this->config->int('reporting.max_export_workers');

        return DB::transaction(function () use ($kind, $runId, $timeoutSeconds, $limit) {
            AdvisoryLock::forTransaction(DB::connection(), 'report_slots:'.$kind->value);
            DB::table('report_execution_slots')->where('kind', $kind->value)->where('expires_at', '<', now())->delete();
            $active = DB::table('report_execution_slots')->where('kind', $kind->value)->count();
            if ($active >= $limit) {
                $next = DB::table('report_execution_slots')->where('kind', $kind->value)->min('expires_at');
                throw DomainException::of(ErrorCode::REPORT_CAPACITY, 'Too many reports are running right now. Please retry shortly.',
                    ['retry_after' => max(1, (int) now()->diffInSeconds($next, false))]);
            }
            $id = (string) Str::uuid7();
            DB::table('report_execution_slots')->insert(['id' => $id, 'kind' => $kind->value, 'report_run_id' => $runId,
                'acquired_at' => now(), 'expires_at' => now()->addSeconds($timeoutSeconds + 30)]);

            return $id;
        });
    }

    public function release(string $slotId): void
    {
        DB::table('report_execution_slots')->where('id', $slotId)->delete();
    }
}
