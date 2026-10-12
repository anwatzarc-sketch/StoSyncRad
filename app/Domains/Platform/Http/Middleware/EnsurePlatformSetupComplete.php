<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Middleware;

use App\Domains\Platform\Services\SetupState;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global setup gate (Chapter 02 §2.2): until Platform Setup is locked, only setup, health and
 * brand routes are reachable. After lock, setup routes vanish unless setup is reopened.
 */
final class EnsurePlatformSetupComplete
{
    public function __construct(private readonly SetupState $setup) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) ($request->route()?->getName() ?? '');
        $isSetupRoute = Str::startsWith($routeName, 'setup.');

        if ($this->setup->isComplete()) {
            if ($isSetupRoute && ! $this->setup->isReopened()) {
                abort(404);
            }

            return $next($request);
        }

        if ($this->isAllowedBeforeSetup($routeName, $request)) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            throw DomainException::of(ErrorCode::SETUP_REQUIRED,
                'Platform Setup has not been completed. No operation is available until a Super Admin completes and locks setup.',
                ['setup_url' => $this->setupUrl()]);
        }

        return redirect($this->setupUrl());
    }

    private function isAllowedBeforeSetup(string $routeName, Request $request): bool
    {
        if ($routeName === '') {
            // Unnamed routes (asset fallbacks) are only allowed when they are static files.
            return $request->is('build/*') || $request->is('favicon*') || $request->is('brand/*');
        }

        foreach ((array) config('platform.pre_setup_route_names') as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        return false;
    }

    private function setupUrl(): string
    {
        return Route::has('setup.index') ? route('setup.index') : url('/setup');
    }
}
