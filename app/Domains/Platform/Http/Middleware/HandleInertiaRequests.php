<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Services\SetupState;
use App\Http\Support\Navigation;
use App\Support\Http\Correlation;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Shared Inertia props (Chapter 12 §12.1): auth (account + effective permissions + scopes),
 * brand, locale, features, setup state, flash and correlation id. Nothing here is authoritative
 * for authorization — the server re-checks every request.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $account = $request->user();
        $config = app(PlatformConfig::class);

        return array_merge(parent::share($request), [
            'auth' => fn () => $account === null ? null : [
                'account' => [
                    'id' => $account->id,
                    'username' => $account->username,
                    'display_name' => $account->person?->full_name ?? $account->username,
                    'email' => $account->email,
                    'is_super_admin' => (bool) $account->is_super_admin,
                    'mfa_status' => $account->mfa_status?->value,
                ],
                'permissions' => app(Access::class)->permissionCodes($account),
                'scopes' => app(Access::class)->scopeSummary($account),
            ],
            'nav' => fn () => $account === null ? [] : app(Navigation::class)->for($account),
            'brand' => fn () => app(BrandResolver::class)->publicProfile(),
            'locale' => fn () => [
                'current' => app()->getLocale(),
                'enabled' => $config->get('locale.enabled'),
                'timezone' => $config->get('locale.timezone'),
                'date_format' => $config->get('locale.date_format'),
                'time_format' => $config->get('locale.time_format'),
                'datetime_format' => $config->get('locale.datetime_format'),
                'decimal_separator' => $config->get('locale.number.decimal_separator'),
                'grouping_separator' => $config->get('locale.number.grouping_separator'),
                'quantity_precision' => $config->get('locale.quantity_display_precision'),
                'currency' => $config->get('locale.currency.base'),
                'currency_precision' => $config->get('locale.currency.display_precision'),
            ],
            'features' => fn () => $config->features(),
            'setup' => fn () => ['state' => app(SetupState::class)->state()->value],
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'correlation_id' => fn () => Correlation::id($request),
        ]);
    }
}
