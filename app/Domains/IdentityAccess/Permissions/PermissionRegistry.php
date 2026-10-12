<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Permissions;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

/**
 * Collects every PermissionDefinition from the providers in config/platform.php and the
 * route bindings declared with ->permission() / permission:<code> middleware (Chapter 04 §4.4).
 */
final class PermissionRegistry
{
    /** @var array<string, PermissionDefinition>|null */
    private ?array $definitions = null;

    /** @var array<string, class-string<ProvidesPermissions>> */
    private array $moduleProviders = [];

    /** @return Collection<string, PermissionDefinition> */
    public function all(): Collection
    {
        if ($this->definitions === null) {
            $this->definitions = $this->collect();
        }

        return collect($this->definitions);
    }

    public function has(string $code): bool
    {
        return $this->all()->has($code);
    }

    public function get(string $code): ?PermissionDefinition
    {
        return $this->all()->get($code);
    }

    /** @return array<int, array{code: string, label: string}> */
    public function modules(): array
    {
        $this->all();

        return collect($this->moduleProviders)
            ->map(static fn (string $provider, string $code) => ['code' => $code, 'label' => $provider::moduleLabel()])
            ->values()->all();
    }

    /**
     * Resolve the transitive closure of implied permissions for a set of codes.
     *
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function expand(array $codes): array
    {
        $seen = [];
        $queue = array_values(array_unique($codes));
        while ($queue !== []) {
            $code = array_pop($queue);
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            foreach ($this->get($code)?->implies ?? [] as $implied) {
                if (! isset($seen[$implied])) {
                    $queue[] = $implied;
                }
            }
        }

        return array_keys($seen);
    }

    /**
     * Endpoint bindings declared on routes: code → [[method, name, uri], ...].
     *
     * @return array<string, array<int, array{method: string, name: ?string, uri: string}>>
     */
    public function routeBindings(Router $router): array
    {
        $bindings = [];
        /** @var Route $route */
        foreach ($router->getRoutes() as $route) {
            $code = $this->permissionFromRoute($route);
            if ($code === null) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $bindings[$code][] = ['method' => $method, 'name' => $route->getName(), 'uri' => $route->uri()];
            }
        }
        ksort($bindings);

        return $bindings;
    }

    /**
     * Routes without a permission binding that are not in the public allow-list.
     *
     * @return array<int, array{method: string, name: ?string, uri: string}>
     */
    public function unboundRoutes(Router $router): array
    {
        $public = (array) config('platform.public_route_names');
        $out = [];
        /** @var Route $route */
        foreach ($router->getRoutes() as $route) {
            if ($this->permissionFromRoute($route) !== null) {
                continue;
            }
            $name = (string) ($route->getName() ?? '');
            $isPublic = $name !== '' && collect($public)->contains(static fn (string $p) => Str::is($p, $name));
            $isFramework = Str::startsWith($route->uri(), ['_debugbar', 'sanctum/', 'up', 'storage/', '_ignition', 'telescope', 'horizon', '_boost/', '_inertia/']);
            if ($isPublic || $isFramework) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $out[] = ['method' => $method, 'name' => $route->getName(), 'uri' => $route->uri()];
                }
            }
        }

        return $out;
    }

    public function permissionFromRoute(Route $route): ?string
    {
        $declared = $route->getAction('permission');
        if (is_string($declared)) {
            return $declared;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && Str::startsWith($middleware, 'permission:')) {
                return Str::after($middleware, 'permission:');
            }
        }

        return null;
    }

    /** @return array<string, PermissionDefinition> */
    private function collect(): array
    {
        $out = [];
        foreach ((array) config('platform.permission_providers') as $provider) {
            if (! is_subclass_of($provider, ProvidesPermissions::class)) {
                throw new LogicException("$provider must implement ProvidesPermissions");
            }
            $this->moduleProviders[$provider::module()] = $provider;
            foreach ($provider::definitions() as $definition) {
                if ($definition->module !== $provider::module()) {
                    throw new LogicException("Permission {$definition->code} declared in module {$provider::module()}");
                }
                if (isset($out[$definition->code])) {
                    throw new LogicException("Permission {$definition->code} declared twice");
                }
                $out[$definition->code] = $definition;
            }
        }
        foreach ($out as $definition) {
            foreach ($definition->implies as $implied) {
                if (! isset($out[$implied])) {
                    throw new LogicException("Permission {$definition->code} implies undeclared {$implied}");
                }
            }
        }
        ksort($out);

        return $out;
    }
}
