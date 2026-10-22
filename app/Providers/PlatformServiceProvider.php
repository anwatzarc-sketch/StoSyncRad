<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\Platform\Config\ConfigRegistry;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Database\Ddl;
use App\Support\Database\MySqlConnection;
use App\Support\Database\RuntimeGrants;
use App\Support\Database\SqlError;
use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\EnumRegistry;
use App\Support\Schema\SchemaRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // Only a real deadlock is a "concurrency error": InnoDB has already rolled the whole transaction
        // back. Laravel's default also counts lock-wait timeouts, which roll back just the statement;
        // treating those as deadlocks would skip ROLLBACK TO SAVEPOINT and keep half-applied nested work.
        // The connection class also applies the session statement/lock timeouts whenever it opens a PDO.
        Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new MySqlConnection($pdo, $database, $prefix, $config));

        $this->app->singleton(ConcurrencyErrorDetector::class, fn () => new class implements ConcurrencyErrorDetector
        {
            public function causedByConcurrencyError(\Throwable $e): bool
            {
                return SqlError::isDeadlock($e);
            }
        });
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Named limiters keep separate counters: the wizard is chatty (step loads, metadata) while token
        // exchange and Super Admin sign-in are credential checks and stay tight. Unnamed throttles on one
        // route would share a single per-IP counter.
        RateLimiter::for('setup', fn (Request $request) => Limit::perMinute(240)->by('setup|'.$request->ip()));
        RateLimiter::for('setup-auth', fn (Request $request) => Limit::perMinute(10)->by('setup-auth|'.$request->ip()));
        // Customer portal: visitors per IP; sign-in/registration steps per IP and step; customer actions per account and action.
        RateLimiter::for('portal-public', fn (Request $request) => Limit::perMinute(120)->by('portal-public|'.$request->ip()));
        RateLimiter::for('portal-auth', fn (Request $request) => Limit::perMinute(10)->by('portal-auth|'.$request->route()?->getName().'|'.$request->ip()));
        RateLimiter::for('portal-action', fn (Request $request) => Limit::perMinute(30)
            ->by('portal-action|'.$request->route()?->getName().'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Settings read inside a transaction that is then rolled back must not survive it.
        Event::listen(TransactionRolledBack::class, fn () => $this->app->make(PlatformConfig::class)->forget());

        Model::shouldBeStrict(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(true);

        $this->registerRouteMacros();
        $this->syncRuntimeGrantsAfterMigrations();
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

        /* The record page has its own control for this endpoint (e.g. a photo uploader), so it is not offered as a generic action. */
        Route::macro('ownScreen', function (): Route {
            /** @var Route $this */
            $this->action['own_screen'] = true;

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
     * Add each migration's queued CHECK constraints when it ends, and re-grant the runtime account's
     * table privileges whenever the schema changes.
     */
    private function syncRuntimeGrantsAfterMigrations(): void
    {
        Event::listen(MigrationStarted::class, fn () => Ddl::discardPendingChecks());
        Event::listen(MigrationEnded::class, fn () => Ddl::flushChecks());

        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
            if (($event->options['pretend'] ?? false) === true) {
                return;
            }
            $name = $this->app->make('migrator')->getConnection();
            if ($this->app->make('db')->connection($name)->getDriverName() === 'mysql') {
                $this->app->make(RuntimeGrants::class)->sync($name);
            }
        });
    }
}
