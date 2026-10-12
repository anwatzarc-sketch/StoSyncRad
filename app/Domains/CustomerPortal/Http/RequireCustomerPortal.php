<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Http;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal pages: the portal must be switched on (otherwise it does not exist: 404), and `customer`
 * pages need a signed-in, active customer account (visitors go to the portal sign-in page).
 */
final class RequireCustomerPortal
{
    public function __construct(private readonly PlatformConfig $config) {}

    public function handle(Request $request, Closure $next, string $who = 'visitor'): Response
    {
        abort_unless($this->config->feature('feature.customer_portal'), 404);
        if ($who !== 'customer') {
            return $next($request);
        }
        $account = Auth::guard('web')->user();
        if (! $account instanceof UserAccount) {
            return redirect()->guest('/portal/login');
        }
        if (! $account->isCustomer() || ! $account->isActive()) {
            return redirect('/');
        }

        return $next($request);
    }
}
