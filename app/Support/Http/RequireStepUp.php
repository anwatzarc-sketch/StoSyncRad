<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Domains\IdentityAccess\Services\StepUpVerifier;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `step_up` — requires a recent MFA re-verification for critical operations
 * (Chapter 04 §4.2, Chapter 14 §14.7). Also applied automatically by RequirePermission for
 * permissions whose sensitivity is `critical` (see StepUpVerifier::requiredFor()).
 */
final class RequireStepUp
{
    public function __construct(private readonly StepUpVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        if ($account !== null && ! $this->verifier->isSatisfied($request, $account)) {
            throw DomainException::of(ErrorCode::STEP_UP_REQUIRED,
                'Recent multi-factor verification is required for this operation.',
                [], 'Re-verify with your authenticator and resubmit with the same Idempotency-Key.');
        }

        return $next($request);
    }
}
