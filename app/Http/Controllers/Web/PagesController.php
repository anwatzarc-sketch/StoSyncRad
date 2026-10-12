<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Http\Support\ResourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia pages (Chapter 12). Pages carry only identifiers and descriptors; data comes from the
 * JSON API, which enforces every permission, scope and state rule. Resource pages are generic and
 * schema-driven; their action buttons are derived from the permission-bound API routes of that
 * resource, filtered to the permissions this person holds.
 */
final class PagesController
{
    public function __construct(private readonly Access $access, private readonly PlatformConfig $config, private readonly Router $router) {}

    public function resourceIndex(Request $request, string $resource): Response
    {
        return Inertia::render('Resources/Index', ['resource' => $this->describe($request, $resource)]);
    }

    public function resourceCreate(Request $request, string $resource): Response
    {
        $meta = $this->describe($request, $resource);
        abort_unless($meta['can_create'], 403);

        return Inertia::render('Resources/Form', ['resource' => $meta, 'id' => null]);
    }

    public function resourceShow(Request $request, string $resource, string $id): Response
    {
        return Inertia::render('Resources/Show', ['resource' => $this->describe($request, $resource), 'id' => $id]);
    }

    public function resourceEdit(Request $request, string $resource, string $id): Response
    {
        $meta = $this->describe($request, $resource);
        abort_unless($meta['can_update'], 403);

        return Inertia::render('Resources/Form', ['resource' => $meta, 'id' => $id]);
    }

    public function stockItem(string $id): Response
    {
        return Inertia::render('Stock/Item', ['itemId' => $id]);
    }

    public function report(?string $id = null): Response
    {
        return Inertia::render('Reports/Builder', ['definitionId' => $id]);
    }

    /** @return array<string, mixed> */
    private function describe(Request $request, string $key): array
    {
        $cfg = ResourceRegistry::all()[$key] ?? abort(404);
        abort_if(isset($cfg['feature']) && ! $this->config->feature($cfg['feature']), 404);
        $account = $request->user();
        abort_unless($this->access->hasPermissionAnywhere($account, $cfg['read']) || (isset($cfg['own']) && $this->access->hasPermissionAnywhere($account, $cfg['own'][0])), 403);
        $writable = ($cfg['writer'] ?? null) !== false && $cfg['schema'] !== null;

        return [
            'key' => $key,
            'label' => Str::headline(str_replace('-', ' ', $key)),
            'schema' => $cfg['schema'] === null ? null : $cfg['schema']::name(),
            'api' => "/api/v1/$key",
            'can_create' => $writable && ! empty($cfg['create']) && $this->access->hasPermissionAnywhere($account, $cfg['create']),
            'can_update' => $writable && ! empty($cfg['update']) && $this->access->hasPermissionAnywhere($account, $cfg['update']),
            'relations' => array_values((array) ($cfg['with'] ?? [])),
            'actions' => $this->actions($account, $key),
        ];
    }

    /**
     * Record-level API actions of a resource (`/api/v1/{key}/{id}/…`), e.g. submit, approve, post, archive.
     *
     * @return array<int, array<string, mixed>>
     */
    private function actions($account, string $key): array
    {
        $out = [];
        /** @var Route $route */
        foreach ($this->router->getRoutes() as $route) {
            $uri = $route->uri();
            $permission = $route->getAction('permission');
            if ($permission === null || ! preg_match('#^api/v1/'.preg_quote($key, '#').'/\{(id|[a-zA-Z]+Id)\}/([a-z0-9/_-]+)$#', $uri, $m)
                || ! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                continue;
            }
            if (! $this->access->hasPermissionAnywhere($account, $permission)) {
                continue;
            }
            $schema = $route->getAction('schema');
            $out[] = [
                'name' => $m[2],
                'label' => Str::headline(str_replace(['/', '-'], ' ', $m[2])),
                'method' => array_values(array_diff($route->methods(), ['HEAD']))[0],
                'uri' => '/'.$uri,
                'parameter' => $m[1],
                'permission' => $permission,
                'schema' => is_array($schema) ? $schema[0]::name() : null,
                'no_body' => $route->getAction('no_body') === true,
                'idempotent' => str_ends_with($m[2], 'post') || in_array($m[2], ['dispatch', 'receive', 'deliver', 'handover', 'release'], true),
            ];
        }

        return $out;
    }
}
