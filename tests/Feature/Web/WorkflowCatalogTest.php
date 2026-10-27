<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Http\Support\ResourceRegistry;
use App\Http\Support\WorkflowCatalog;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * The workflow catalog describes the real system: every record action it lists is a real endpoint and
 * every endpoint has a place in it, statuses exist in the document's state enum, permissions exist,
 * and every link it offers opens a page — including the follow-up links of record pages, whose
 * pre-filled fields exist on the form they open. The resource registry's other entry points
 * (dedicated create/edit endpoints, create variants, read panels) are permission-bound API routes.
 */
final class WorkflowCatalogTest extends TestCase
{
    public function test_actions_match_the_record_endpoints_of_each_document(): void
    {
        foreach (WorkflowCatalog::documents() as $resource => $doc) {
            $this->assertArrayHasKey($resource, ResourceRegistry::all(), "$resource is not a registered resource");
            $this->assertEqualsCanonicalizing($this->endpointActions($resource), array_keys($doc['actions']), "$resource: catalog actions differ from its endpoints");
            foreach ($doc['extra'] ?? [] as $name => $extra) {
                $this->assertNotNull(app('router')->getRoutes()->getByName($extra['route']), "$resource.$name: unknown route {$extra['route']}");
            }
        }
    }

    public function test_statuses_belong_to_each_documents_state_enum(): void
    {
        foreach (WorkflowCatalog::documents() as $resource => $doc) {
            $statuses = array_map(fn ($case) => $case->value, $doc['status_enum']::cases());
            $used = array_merge($doc['steps'], $doc['editable'], ...array_column($doc['actions'] + ($doc['extra'] ?? []), 'from'), ...array_column($doc['queues'], 'statuses'));
            $this->assertSame([], array_values(array_diff($used, $statuses)), "$resource uses statuses outside {$doc['status_enum']}");
        }
    }

    public function test_queue_and_start_permissions_exist(): void
    {
        $registry = app(PermissionRegistry::class);
        foreach (WorkflowCatalog::queues() as $key => $queue) {
            $this->assertNotNull($registry->get($queue['permission']), "$key: unknown permission {$queue['permission']}");
            foreach ($queue['hubs'] as $hub) {
                $this->assertArrayHasKey($hub, WorkflowCatalog::HUBS, "$key: unknown hub $hub");
            }
        }
        foreach (WorkflowCatalog::HUBS as $key => $hub) {
            foreach ($hub['start'] as $start) {
                foreach ($start['permission'] as $permission) {
                    $this->assertNotNull($registry->get($permission), "hub $key: unknown permission $permission");
                }
            }
        }
    }

    public function test_every_hub_lists_its_documents_and_every_link_opens_a_page(): void
    {
        foreach (WorkflowCatalog::documents() as $resource => $doc) {
            if ($doc['hub'] !== null) {
                $this->assertContains($resource, WorkflowCatalog::HUBS[$doc['hub']]['resources'], "$resource is missing from hub {$doc['hub']}");
            }
            foreach ($doc['actions'] as $name => $action) {
                if (isset($action['task'])) {
                    $this->assertWebPage(str_replace('{id}', '01900000-0000-7000-8000-000000000000', $action['task']), "$resource.$name");
                }
            }
        }
        foreach (WorkflowCatalog::HUBS as $key => $hub) {
            $this->assertWebPage("/work/$key", "hub $key");
            foreach ($hub['resources'] as $resource) {
                $this->assertArrayHasKey($resource, ResourceRegistry::all(), "hub $key: unknown resource $resource");
            }
            foreach ($hub['start'] as $start) {
                $this->assertWebPage($start['href'], "hub $key start");
            }
        }
    }

    public function test_follow_up_links_open_pages_with_known_permissions_states_and_form_fields(): void
    {
        $registry = app(PermissionRegistry::class);
        foreach (WorkflowCatalog::NEXT as $resource => $links) {
            $this->assertArrayHasKey($resource, ResourceRegistry::all(), "next: $resource is not a registered resource");
            $statusEnum = WorkflowCatalog::document($resource)['status_enum'] ?? ((new (ResourceRegistry::get($resource)['model']))->getCasts()['status'] ?? null);
            foreach ($links as $link) {
                $context = "$resource next “{$link['label']}”";
                $this->assertNotNull($registry->get($link['permission']), "$context: unknown permission {$link['permission']}");
                $this->assertWebPage(preg_replace('/\{[a-z_]+\}/', '01900000-0000-7000-8000-000000000000', $link['href']), $context);
                if (isset($link['when']['status'])) {
                    $this->assertNotNull($statusEnum, "$context: $resource has no status");
                    $this->assertSame([], array_values(array_diff($link['when']['status'], array_map(fn ($c) => $c->value, $statusEnum::cases()))), "$context: unknown status");
                }
                foreach ($this->prefilledFields($link['href']) as $field => $schema) {
                    $this->assertArrayHasKey($field, $schema::properties(), "$context: the form has no field $field");
                }
            }
        }
    }

    public function test_dedicated_endpoints_create_variants_and_panels_are_permission_bound_api_routes(): void
    {
        $routes = app('router')->getRoutes();
        foreach (ResourceRegistry::all() as $key => $cfg) {
            $writes = array_merge(array_filter([$cfg['create_route'] ?? null, $cfg['update_route'] ?? null]), array_column($cfg['create_variants'] ?? [], 'route'));
            foreach (array_merge($writes, array_column($cfg['panels'] ?? [], 'route')) as $name) {
                $route = $routes->getByName($name);
                $this->assertNotNull($route, "$key: unknown route $name");
                $this->assertStringStartsWith('api/v1/', $route->uri(), "$key: $name is not an API route");
                $this->assertNotNull($route->getAction('permission'), "$key: $name is bound to no permission");
                $this->assertSame(in_array($name, $writes, true), is_array($route->getAction('schema')), "$key: $name — forms need a payload schema, panels none");
            }
            if (isset($cfg['update_route'])) {
                $this->assertStringContainsString('{id}', $routes->getByName($cfg['update_route'])->uri(), "$key: the edit endpoint names the record");
            }
            foreach ($cfg['create_variants'] ?? [] as $via => $variant) {
                if (isset($variant['land'])) {
                    $this->assertWebPage(preg_replace('/\{[a-z_]+\}/', '01900000-0000-7000-8000-000000000000', $variant['land']), "$key via $via lands");
                }
            }
        }
        foreach (WorkflowCatalog::HUBS as $hub => $definition) {
            foreach ($definition['start'] as $start) {
                if (preg_match('#^/r/([a-z-]+)/new\?via=([a-z-]+)$#', $start['href'], $m)) {
                    $this->assertArrayHasKey($m[2], ResourceRegistry::get($m[1])['create_variants'] ?? [], "hub $hub: {$start['href']} names no create variant");
                }
            }
        }
    }

    /**
     * `prefill[field]` keys of a link to a create form, with the schema of the form it opens.
     *
     * @return array<string, class-string>
     */
    private function prefilledFields(string $href): array
    {
        if (! preg_match('#^/r/([a-z-]+)/new(?:\?(.*))?$#', $href, $m) || ! isset($m[2])) {
            return [];
        }
        parse_str($m[2], $query);
        $cfg = ResourceRegistry::get($m[1]);
        $route = isset($cfg['create_route']) ? app('router')->getRoutes()->getByName($cfg['create_route']) : null;
        $schema = $route?->getAction('schema')[0] ?? $cfg['schema'];

        return array_fill_keys(array_keys((array) ($query['prefill'] ?? [])), $schema);
    }

    /** Record actions the API offers for a resource, by the same rule the record page uses (PagesController). */
    private function endpointActions(string $resource): array
    {
        $names = [];
        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getAction('permission') !== null && $route->getAction('own_screen') !== true
                && preg_match('#^api/v1/(?:admin/)?'.preg_quote($resource, '#').'/(?:mine/)?\{(?:id|[a-zA-Z]+Id)\}/([a-z0-9/_-]+)$#', $route->uri(), $m)
                && array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                $names[$m[1]] = true;
            }
        }

        return array_keys($names);
    }

    private function assertWebPage(string $href, string $context): void
    {
        $route = app('router')->getRoutes()->match(Request::create(explode('?', $href)[0]));
        $this->assertNotNull($route->getName(), "$context: $href opens no page");
        $this->assertFalse(str_starts_with($route->uri(), 'api/'), "$context: $href is not a page");
    }
}
