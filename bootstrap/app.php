<?php

use App\Domains\CustomerPortal\Http\EnsureAccountAudience;
use App\Domains\CustomerPortal\Http\RequireCustomerPortal;
use App\Domains\Platform\Http\Middleware\EnsurePlatformSetupComplete;
use App\Domains\Platform\Http\Middleware\HandleInertiaRequests;
use App\Support\Exceptions\DomainException;
use App\Support\Http\ApiErrorResponder;
use App\Support\Http\Correlation;
use App\Support\Http\RequireFeature;
use App\Support\Http\RequirePermission;
use App\Support\Http\RequireStepUp;
use App\Support\Http\SecurityHeaders;
use App\Support\Schema\ValidateAgainstSchema;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: correlation id first so every log/audit/error carries it.
        $middleware->prepend(Correlation::class);

        // The setup gate needs the matched route (allow-list by route name), so it opens each group:
        // global middleware runs before routing.
        // Customer Portal boundary (CP-1.1 §2): runs on every web/API request once the session is known.
        $middleware->web(prepend: [EnsurePlatformSetupComplete::class, SecurityHeaders::class], append: [
            AuthenticateSession::class,
            EnsureAccountAudience::class,
            HandleInertiaRequests::class,
            ValidateAgainstSchema::class,
        ]);

        $middleware->api(prepend: [
            HandleCors::class,
            EnsurePlatformSetupComplete::class,
            SecurityHeaders::class,
        ]);
        $middleware->api(append: [
            EnsureAccountAudience::class,
            ValidateAgainstSchema::class,
        ]);

        // Route middleware order: authenticate → throttle → bindings → permission → feature → step-up →
        // payload validation, so unauthenticated or unauthorized callers never reach validation and
        // `writableBy` rules see the authenticated actor.
        $middleware->appendToPriorityList(Authorize::class, RequirePermission::class);
        $middleware->appendToPriorityList(RequirePermission::class, RequireFeature::class);
        $middleware->appendToPriorityList(RequireFeature::class, RequireStepUp::class);
        $middleware->appendToPriorityList(RequireStepUp::class, ValidateAgainstSchema::class);

        $middleware->statefulApi();

        $middleware->alias([
            'permission' => RequirePermission::class,
            'feature' => RequireFeature::class,
            'step_up' => RequireStepUp::class,
            'portal' => RequireCustomerPortal::class,
        ]);

        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', (string) env('TRUSTED_PROXIES')) : null);

        $middleware->validateCsrfTokens(except: [
            'api/v1/integrations/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return app(ApiErrorResponder::class)->render($e, $request);
            }

            return null;
        });

        $exceptions->dontReport([
            DomainException::class,
        ]);
    })->create();
