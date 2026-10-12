<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Jobs;

use App\Domains\DataExchange\Services\ImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Background processing of a confirmed import batch (`imports` queue). */
final class ProcessImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $batchId) {}

    public function handle(ImportService $imports): void
    {
        $imports->process($this->batchId);
    }
}
