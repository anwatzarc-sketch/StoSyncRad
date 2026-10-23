<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Http\Support\ResourceRegistry;
use App\Http\Support\WorkflowCatalog;
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
        // People who may only see their own records (e.g. stock requesters) use the resource's `…/mine` endpoints.
        $ownOnly = isset($cfg['own']) && ! $this->access->hasPermissionAnywhere($account, $cfg['read']);
        $createRoute = isset($cfg['create_route']) ? $this->router->getRoutes()->getByName($cfg['create_route'])
            : ($ownOnly ? $this->router->getRoutes()->getByName('api.v1.wf.'.str_replace('-', '_', $key).'.mine') : null);
        $doc = WorkflowCatalog::document($key);

        return [
            'key' => $key,
            'label' => Str::headline(str_replace('-', ' ', $key)),
            'noun' => $doc['noun'] ?? Str::singular(Str::lower(Str::headline($key))),
            'schema' => $cfg['schema'] === null ? null : $cfg['schema']::name(),
            'api' => $ownOnly ? "/api/v1/$key/mine" : "/api/v1/$key",
            'create_schema' => $createRoute === null ? null : $createRoute->getAction('schema')[0]::name(),
            'api_create' => $createRoute === null ? null : '/'.$createRoute->uri(),
            'can_create' => $createRoute !== null
                ? $this->access->hasPermissionAnywhere($account, $createRoute->getAction('permission'))
                : $writable && ! empty($cfg['create']) && $this->access->hasPermissionAnywhere($account, $cfg['create']),
            'can_update' => $writable && ! empty($cfg['update']) && $this->access->hasPermissionAnywhere($account, $cfg['update']),
            'relations' => array_values((array) ($cfg['with'] ?? [])),
            'actions' => $this->actions($account, $key, $doc),
            'workflow' => $doc === null ? null : [
                'status_enum' => $doc['status_enum']::enumCode(),
                'steps' => $doc['steps'],
                'editable' => $doc['editable'],
                'actions' => array_map(fn (array $a) => ['from' => $a['from'], 'primary' => $a['primary'] ?? false, 'task' => $a['task'] ?? null],
                    $doc['actions'] + ($doc['extra'] ?? [])),
            ],
        ];
    }

    /**
     * Record-level API actions of a resource (`/api/v1/{key}/{id}/…`, own-record `/api/v1/{key}/mine/{id}/…`
     * and the administration routes `/api/v1/admin/{key}/{id}/…`), e.g. submit, approve, post, archive,
     * revoke, plus the workflow's nested actions (WorkflowCatalog `extra`, whose other URL parameters
     * come from record fields). Labels come from the workflow where it names them.
     *
     * @param  array<string, mixed>|null  $doc  WorkflowCatalog entry
     * @return array<int, array<string, mixed>>
     */
    private function actions($account, string $key, ?array $doc): array
    {
        $out = [];
        /** @var Route $route */
        foreach ($this->router->getRoutes() as $route) {
            $uri = $route->uri();
            $permission = $route->getAction('permission');
            if ($permission === null || $route->getAction('own_screen') === true
                || ! preg_match('#^api/v1/(?:admin/)?'.preg_quote($key, '#').'/(mine/)?\{(id|[a-zA-Z]+Id)\}/([a-z0-9/_-]+)$#', $uri, $m)
                || ! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                continue;
            }
            if (! $this->access->hasPermissionAnywhere($account, $permission)) {
                continue;
            }
            [, $mine, $parameter, $name] = $m;
            if (isset($out[$name]) && ($mine !== '' || ! $out[$name]['mine'])) {
                continue;   // the full endpoint wins over the own-record one
            }
            $out[$name] = $this->action($route, $name, $parameter, $doc['actions'][$name]['label'] ?? null) + ['mine' => $mine !== ''];
        }
        foreach ($doc['extra'] ?? [] as $name => $extra) {
            $route = $this->router->getRoutes()->getByName($extra['route']);
            if ($route !== null && $this->access->hasPermissionAnywhere($account, $route->getAction('permission'))) {
                $out[$name] = $this->action($route, $name, 'id', $extra['label'] ?? null) + ['params' => $extra['params'], 'mine' => false];
            }
        }

        return array_values(array_map(fn (array $a) => array_diff_key($a, ['mine' => true]), $out));
    }

    /** @return array<string, mixed> */
    private function action(Route $route, string $name, string $parameter, ?string $label): array
    {
        $schema = $route->getAction('schema');

        return [
            'name' => $name,
            'label' => $label ?? Str::headline(str_replace(['/', '-'], ' ', $name)),
            'method' => array_values(array_diff($route->methods(), ['HEAD']))[0],
            'uri' => '/'.$route->uri(),
            'parameter' => $parameter,
            'permission' => $route->getAction('permission'),
            'schema' => is_array($schema) ? $schema[0]::name() : null,
            'no_body' => $route->getAction('no_body') === true,
            'idempotent' => str_ends_with($name, 'post') || in_array($name, ['dispatch', 'receive', 'deliver', 'deliveries', 'handover', 'release'], true),
        ];
    }
}
