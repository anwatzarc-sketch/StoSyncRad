<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** SMTP connection security: STARTTLS (usually port 587) or implicit TLS/SSL (usually port 465). */
enum MailSecurity: string implements SystemEnum
{
    use HasSystemEnum;

    case STARTTLS = 'starttls';
    case SSL = 'ssl';
}
