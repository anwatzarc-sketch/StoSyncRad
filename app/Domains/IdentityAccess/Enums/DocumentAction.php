<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/**
 * Recorded document actions referenced by separation-of-duties rules (Chapter 04 §4.7).
 * Each document records which account performed each action; SoD compares them.
 */
enum DocumentAction: string implements SystemEnum
{
    use HasSystemEnum;

    case CREATE = 'create';
    case SUBMIT = 'submit';
    case APPROVE = 'approve';
    case POST = 'post';
    case DISPATCH = 'dispatch';
    case RECEIVE = 'receive';
    case COUNT = 'count';
    case REVERSE = 'reverse';
    case REQUEST = 'request';
    case RESOLVE = 'resolve';
    case RELEASE = 'release';
}
