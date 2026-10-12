<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Http;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The boundary between the Customer Portal and the staff application (CP-1.1 §2), applied to every
 * web and API request by path so a new route cannot forget it: a customer session reaches only portal
 * paths and a few shared ones (sign-out, password reset, brand, PWA); a staff session cannot use the
 * customer API or the signed-in customer pages. Authorization still happens per request.
 */
final class EnsureAccountAudience
{
    /** Reachable by customer sessions besides the portal itself. */
    private const SHARED = [
        'api/v1/auth/logout', 'api/v1/auth/step-up', 'api/v1/auth/password/*', 'api/v1/meta/brand', 'api/v1/meta/enums',
        'password/reset', 'sanctum/csrf-cookie', 'brand/*', 'manifest.webmanifest', 'sw.js', 'offline', 'pwa/reset', 'health/*', 'up',
    ];

    /** Portal pages for visitors; everything else under portal/ is for signed-in customers. */
    public const PUBLIC_PORTAL = ['portal', 'portal/register', 'portal/verify-email', 'portal/security-setup', 'portal/invitation', 'portal/login', 'portal/password/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $account = Auth::guard('web')->user();
        if (! $account instanceof UserAccount) {
            return $next($request);
        }
        $portal = $request->is('portal', 'portal/*', 'api/v1/portal/*');
        if ($account->isCustomer()) {
            if ($portal || $request->is(...self::SHARED)) {
                return $next($request);
            }

            return $this->deny($request, '/portal/dashboard', 'Customer accounts can only use the customer portal.');
        }
        $customerOnly = $request->is('api/v1/portal/*') ? ! $request->is('api/v1/portal/public/*') : ($portal && ! $request->is(...self::PUBLIC_PORTAL));
        if ($customerOnly) {
            return $this->deny($request, '/', 'The customer portal is for customer accounts. Staff use the main application.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $home, string $message): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, $message, ['audience' => true]);
        }

        return redirect($home);
    }
}
