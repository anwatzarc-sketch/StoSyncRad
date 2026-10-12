<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Jobs;

use App\Domains\ReportingEngine\Services\ReportRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** User-initiated export on the `reporting` queue (never scheduled, never cached). */
final class RunReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $runId) {}

    public function handle(ReportRunner $runner): void
    {
        $runner->executeExport($this->runId);
    }
}
