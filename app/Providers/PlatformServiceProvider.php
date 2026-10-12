<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\Platform\Config\ConfigRegistry;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\EnumRegistry;
use App\Support\Schema\SchemaRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Platform wiring: registries, route macros, model strictness and connection safety settings.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnumRegistry::class);
        $this->app->singleton(SchemaRegistry::class);
        $this->app->singleton(ConfigRegistry::class);
        $this->app->singleton(PlatformConfig::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(EnumLabelResolver::class, fn ($app) => new EnumLabelResolver($app['cache']->store()));
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Named limiters keep separate counters: the wizard is chatty (step loads, metadata) while token
        // exchange and Super Admin sign-in are credential checks and stay tight. Unnamed throttles on one
        // route would share a single per-IP counter.
        RateLimiter::for('setup', fn (Request $request) => Limit::perMinute(240)->by('setup|'.$request->ip()));
        RateLimiter::for('setup-auth', fn (Request $request) => Limit::perMinute(10)->by('setup-auth|'.$request->ip()));

        // Settings read inside a transaction that is then rolled back must not survive it.
        Event::listen(TransactionRolledBack::class, fn () => $this->app->make(PlatformConfig::class)->forget());

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(true);

        $this->registerRouteMacros();
        $this->applyConnectionTimeouts();
    }

    private function registerRouteMacros(): void
    {
        /*
         * Route::post(...)->schema(ItemSchema::class, 'create')
         * Route::patch(...)->schema(ItemSchema::class, 'update', stateFrom: 'item')
         */
        Route::macro('schema', function (string $schemaClass, string $operation = 'create', ?string $stateFrom = null): Route {
            /** @var Route $this */
            $this->action['schema'] = [$schemaClass, $operation, $stateFrom];

            return $this;
        });

        /*
         * Route::post(...)->permission('catalog.item.create')
         * Sugar for ->middleware('permission:catalog.item.create') that also records the binding
         * for permissions:sync / audit.
         */
        Route::macro('permission', function (string $code): Route {
            /** @var Route $this */
            $this->action['permission'] = $code;

            return $this->middleware('permission:'.$code);
        });

        /*
         * Route::get('/items', [ResourceController::class, 'index'])->resource('items')
         * Stored in the route action (not defaults) so controller arguments are never shifted.
         */
        Route::macro('resource', function (string $key): Route {
            /** @var Route $this */
            $this->action['resource_key'] = $key;

            return $this;
        });

        /*
         * Route::post('/stock-requests/{id}/submit', WorkflowActionController::class)->workflow(StockRequestWorkflow::class, 'submit')
         */
        /*
         * One controller method serving several endpoints, each bound to its own permission:
         * Route::post('/items/{id}/archive', ...)->variant('archive')->permission('catalog.item.archive')
         * (read with $request->route()->getAction('variant'); kept out of the positional parameters).
         */
        /** Write action that takes no body (cancel, retry…); a non-empty payload is rejected. */
        Route::macro('noBody', function (): Route {
            /** @var Route $this */
            $this->action['no_body'] = true;

            return $this;
        });

        Route::macro('variant', function (string $value): Route {
            /** @var Route $this */
            $this->action['variant'] = $value;

            return $this;
        });

        Route::macro('workflow', function (string $class, string $method): Route {
            /** @var Route $this */
            $this->action['_action'] = [$class, $method];

            return $this;
        });

        /*
         * Route::get(...)->feature('feature.retail_sales')
         */
        Route::macro('feature', function (string $flag): Route {
            /** @var Route $this */
            $this->action['feature'] = $flag;

            return $this->middleware('feature:'.$flag);
        });
    }

    /**
     * Apply per-connection statement/lock/idle timeouts on connect (Chapter 09 §9.7).
     * Role-level settings are the floor; these let environments tighten further.
     */
    private function applyConnectionTimeouts(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;
            if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql') {
                return;
            }
            $settings = config('database.timeouts.'.$connection->getName());
            if (! is_array($settings)) {
                return;
            }
            foreach ($settings as $guc => $ms) {
                if (is_int($ms) && $ms > 0 && preg_match('/^[a-z_]+$/', $guc) === 1) {
                    $connection->unprepared("SET {$guc} = {$ms}");
                }
            }
            if ($connection->getName() === 'pgsql_reporting') {
                $connection->unprepared('SET default_transaction_read_only = on');
            }
        });
    }
}
