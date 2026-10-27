<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
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
use ReflectionMethod;

/**
 * Inertia pages (Chapter 12). Pages carry only identifiers and descriptors; data comes from the
 * JSON API, which enforces every permission, scope and state rule. Resource pages are generic and
 * schema-driven; their action buttons are derived from the permission-bound API routes of that
 * resource, filtered to the permissions this person holds, and their follow-up links come from
 * WorkflowCatalog::NEXT.
 */
final class PagesController
{
    public function __construct(
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly Router $router,
        private readonly PermissionRegistry $permissions,
    ) {}

    public function resourceIndex(Request $request, string $resource): Response
    {
        return Inertia::render('Resources/Index', ['resource' => $this->describe($request, $resource)]);
    }

    /** `?via=` picks one of the resource's other ways to start a record (ResourceRegistry `create_variants`). */
    public function resourceCreate(Request $request, string $resource): Response
    {
        $via = $request->query('via');
        $meta = $this->describe($request, $resource, is_string($via) ? $via : null);
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
    private function describe(Request $request, string $key, ?string $via = null): array
    {
        $cfg = ResourceRegistry::all()[$key] ?? abort(404);
        $variant = $via === null ? null : ($cfg['create_variants'][$via] ?? abort(404));
        abort_if(isset($cfg['feature']) && ! $this->config->feature($cfg['feature']), 404);
        $account = $request->user();
        abort_unless($this->access->hasPermissionAnywhere($account, $cfg['read']) || (isset($cfg['own']) && $this->access->hasPermissionAnywhere($account, $cfg['own'][0])), 403);
        $writable = ($cfg['writer'] ?? null) !== false && $cfg['schema'] !== null;
        // People who may only see their own records (e.g. stock requesters) use the resource's `…/mine` endpoints.
        $ownOnly = isset($cfg['own']) && ! $this->access->hasPermissionAnywhere($account, $cfg['read']);
        $routes = $this->router->getRoutes();
        $createRoute = match (true) {
            $variant !== null => $routes->getByName($variant['route']),
            isset($cfg['create_route']) => $routes->getByName($cfg['create_route']),
            $ownOnly => $routes->getByName('api.v1.wf.'.str_replace('-', '_', $key).'.mine'),
            default => null,
        };
        $updateRoute = isset($cfg['update_route']) ? $routes->getByName($cfg['update_route']) : null;
        $doc = WorkflowCatalog::document($key);

        return [
            'key' => $key,
            'label' => Str::headline(str_replace('-', ' ', $key)),
            'noun' => $variant['noun'] ?? $doc['noun'] ?? Str::singular(Str::lower(Str::headline($key))),
            'hub' => ($doc['hub'] ?? null) === null ? null : ['key' => $doc['hub'], 'label' => WorkflowCatalog::HUBS[$doc['hub']]['label']],
            'schema' => $cfg['schema'] === null ? null : $cfg['schema']::name(),
            'api' => $ownOnly ? "/api/v1/$key/mine" : "/api/v1/$key",
            'create_schema' => $createRoute === null ? null : $createRoute->getAction('schema')[0]::name(),
            'api_create' => $createRoute === null ? null : '/'.$createRoute->uri(),
            // Postings need a stable Idempotency-Key; `land` = where to go when the result is not a record of this resource.
            'create_idempotent' => $createRoute !== null && $this->takesIdempotencyKey($createRoute),
            'create_land' => $variant['land'] ?? null,
            'can_create' => $createRoute !== null
                ? $this->access->hasPermissionAnywhere($account, $createRoute->getAction('permission'))
                : $writable && ! empty($cfg['create']) && $this->access->hasPermissionAnywhere($account, $cfg['create']),
            'update_schema' => $updateRoute === null ? null : $updateRoute->getAction('schema')[0]::name(),
            'api_update' => $updateRoute === null ? null : '/'.$updateRoute->uri(),
            'can_update' => $updateRoute !== null
                ? $this->access->hasPermissionAnywhere($account, $updateRoute->getAction('permission'))
                : $writable && ! empty($cfg['update']) && $this->access->hasPermissionAnywhere($account, $cfg['update']),
            'relations' => array_values((array) ($cfg['with'] ?? [])),
            'actions' => $this->actions($account, $key, $doc),
            'panels' => $this->panels($account, $cfg, $doc),
            'next' => $this->next($account, $key),
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
            'idempotent' => str_ends_with($name, 'post') || in_array($name, ['dispatch', 'receive', 'deliver', 'deliveries', 'handover', 'release'], true)
                || $this->takesIdempotencyKey($route),
        ];
    }

    /**
     * Read endpoints of one record shown on its page (ResourceRegistry `panels`), plus the approval
     * history of documents that go through approvals. `{…}` placeholders take the record's id.
     *
     * @param  array<string, mixed>  $cfg  ResourceRegistry entry
     * @param  array<string, mixed>|null  $doc  WorkflowCatalog entry
     * @return array<int, array{label: string, uri: string}>
     */
    private function panels(UserAccount $account, array $cfg, ?array $doc): array
    {
        $out = [];
        foreach ($cfg['panels'] ?? [] as $panel) {
            $route = $this->router->getRoutes()->getByName($panel['route']);
            if ($route !== null && $this->access->hasPermissionAnywhere($account, $route->getAction('permission'))) {
                $out[] = ['label' => $panel['label'], 'uri' => '/'.$route->uri()];
            }
        }
        if (($doc['document_type'] ?? null) !== null && $this->access->hasPermissionAnywhere($account, 'audit.approval.read')) {
            $out[] = ['label' => 'Approval history', 'uri' => '/api/v1/audit/approvals?document_type='.$doc['document_type']->value.'&document_id={id}'];
        }

        return $out;
    }

    /**
     * Follow-up links of a resource (WorkflowCatalog::NEXT) this person may use: permission held
     * somewhere and not switched off with the feature it belongs to.
     *
     * @return array<int, array{label: string, href: string, icon: string, when?: array<string, array<int, string>>}>
     */
    private function next(UserAccount $account, string $key): array
    {
        $usable = array_filter(WorkflowCatalog::NEXT[$key] ?? [], function (array $link) use ($account): bool {
            $flag = $this->permissions->get($link['permission'])?->featureFlag;

            return ($flag === null || $this->config->feature($flag)) && $this->access->hasPermissionAnywhere($account, $link['permission']);
        });

        return array_values(array_map(fn (array $link) => array_diff_key($link, ['permission' => true]), $usable));
    }

    /** Service methods that post to the ledger take the request's Idempotency-Key (WorkflowActionController binds it by name). */
    private function takesIdempotencyKey(Route $route): bool
    {
        $target = $route->getAction('_action');
        if (! is_array($target)) {
            return false;
        }
        foreach ((new ReflectionMethod($target[0], $target[1]))->getParameters() as $parameter) {
            if ($parameter->getName() === 'idempotencyKey') {
                return true;
            }
        }

        return false;
    }
}
