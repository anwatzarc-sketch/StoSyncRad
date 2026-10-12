<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Who may create a customer portal account. */
enum RegistrationMode: string implements SystemEnum
{
    use HasSystemEnum;

    case APPROVAL = 'approval';
    case INVITATION_ONLY = 'invitation_only';
    case CLOSED = 'closed';
}
