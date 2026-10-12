<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\AccountObligations;
use App\Domains\IdentityAccess\Services\StepUpVerifier;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `permission:<code>` — the primary permission bound to every endpoint
 * (Chapter 04 §4.4). Warehouse scope, document state and SoD are verified inside the action
 * once the target record has been resolved server-side (§4.6); this middleware verifies the
 * actor holds the permission in *some* scope so unauthorized callers never reach the action.
 * Pending account obligations (password change, MFA enrolment) block every bound endpoint first;
 * critical permissions (and configured patterns) also demand a recent step-up (§4.2).
 */
final class RequirePermission
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditRecorder $audit,
        private readonly StepUpVerifier $stepUp,
        private readonly AccountObligations $obligations,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $account = $request->user();
        if ($account === null) {
            throw new AuthenticationException;
        }

        $this->obligations->assertNone($account);

        if (! $this->access->hasPermissionAnywhere($account, $permission)) {
            $this->audit->accessDenied($account, $permission, $request);

            throw DomainException::permissionDenied($permission);
        }

        if ($this->stepUp->requiredFor($permission) && ! $this->stepUp->isSatisfied($request, $account)) {
            throw DomainException::of(ErrorCode::STEP_UP_REQUIRED, 'Recent multi-factor verification is required for this operation.',
                ['permission' => $permission], 'Re-verify and resubmit with the same Idempotency-Key.');
        }

        $request->attributes->set('primary_permission', $permission);

        return $next($request);
    }
}
