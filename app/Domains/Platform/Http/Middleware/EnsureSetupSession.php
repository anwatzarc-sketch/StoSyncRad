<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Setup\SetupService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setup routes other than the landing page need an authorized setup session (Chapter 02 §2.3):
 * a session that exchanged this deployment's bootstrap token, or — when setup was reopened — a
 * signed-in holder of platform.setup.reopen.
 */
final class EnsureSetupSession
{
    public const SESSION_DEPLOYMENT = 'setup.deployment_id';

    public function __construct(private readonly SetupService $setup, private readonly Access $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $row = $this->setup->current();
        if (! in_array($row->state, [PlatformSetupState::IN_PROGRESS, PlatformSetupState::REOPENED], true)) {
            throw DomainException::of(ErrorCode::SETUP_TOKEN_INVALID, 'Platform Setup is not open. Run `php artisan platform:bootstrap`.');
        }
        if (! self::authorized($request, $row->deployment_id, $row->state, $this->access)) {
            throw DomainException::of(ErrorCode::SETUP_TOKEN_INVALID, 'Open the setup link printed by `php artisan platform:bootstrap`.');
        }

        return $next($request);
    }

    public static function authorized(Request $request, string $deploymentId, PlatformSetupState $state, Access $access): bool
    {
        if ($request->hasSession() && $request->session()->get(self::SESSION_DEPLOYMENT) === $deploymentId) {
            return true;
        }
        $user = Auth::guard('web')->user();

        return $state === PlatformSetupState::REOPENED && $user instanceof UserAccount && $access->can($user, 'platform.setup.reopen');
    }
}
