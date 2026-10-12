<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Security event catalog (security_events.event_type). */
enum SecurityEventType: string implements SystemEnum
{
    use HasSystemEnum;

    case LOGIN_SUCCESS = 'login_success';
    case LOGIN_FAILURE = 'login_failure';
    case LOCKOUT = 'lockout';
    case LOGOUT = 'logout';
    case PASSWORD_CHANGED = 'password_changed';
    case PASSWORD_RESET_REQUESTED = 'password_reset_requested';
    case PASSWORD_RESET_COMPLETED = 'password_reset_completed';
    case MFA_ENROLLED = 'mfa_enrolled';
    case MFA_RESET = 'mfa_reset';
    case MFA_STEP_UP = 'mfa_step_up';
    case SESSION_REVOKED = 'session_revoked';
    case TOKEN_ISSUED = 'token_issued';
    case TOKEN_REVOKED = 'token_revoked';
    case ACCESS_DENIED = 'access_denied';
    case ACCOUNT_LOCKED = 'account_locked';
    case ACCOUNT_DEACTIVATED = 'account_deactivated';
}
