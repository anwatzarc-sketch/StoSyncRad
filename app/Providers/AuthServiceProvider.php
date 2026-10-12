<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\IdentityAccess\Models\PersonalAccessToken;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Authorization wiring (Chapter 04). Gate::before delegates every ability that looks like a
 * permission code to the Access resolver so policies and controllers share one path.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::before(function (UserAccount $account, string $ability, array $arguments = []) {
            if (! str_contains($ability, '.')) {
                return null;
            }

            $scope = $arguments[0] ?? null;

            return app(Access::class)->can($account, $ability, $scope) ? true : null;
        });
    }
}
