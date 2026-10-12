<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Projection reconciliation run status. */
enum ReconciliationRunStatus: string implements SystemEnum
{
    use HasSystemEnum;

    case RUNNING = 'running';
    case COMPLETED_CLEAN = 'completed_clean';
    case COMPLETED_WITH_FINDINGS = 'completed_with_findings';
    case FAILED = 'failed';
}
