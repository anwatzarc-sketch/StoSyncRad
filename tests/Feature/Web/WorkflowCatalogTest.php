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
 * and every link it offers opens a page.
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
