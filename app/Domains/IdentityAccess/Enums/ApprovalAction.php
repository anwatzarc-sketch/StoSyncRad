<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Recorded approval decisions (approval_records.action). */
enum ApprovalAction: string implements SystemEnum
{
    use HasSystemEnum;

    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case RETURNED_FOR_CORRECTION = 'returned_for_correction';
    case OVERRIDDEN = 'overridden';
    case CANCELLED = 'cancelled';
}
