<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** How e-mail leaves the system: the server environment (.env), the SMTP account set in Configuration, or the log file (testing). */
enum MailTransport: string implements SystemEnum
{
    use HasSystemEnum;

    case ENVIRONMENT = 'environment';
    case SMTP = 'smtp';
    case LOG = 'log';
}
