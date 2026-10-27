<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\NotificationRouter;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Services\SetupState;
use App\Http\Support\ResourceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsPortal;
use Tests\TestCase;

/**
 * Chapter 12 — app shell: pages, sign-in redirect, security headers and caching, server-filtered
 * navigation, actions, follow-up links and read panels.
 */
final class WebShellTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;
    use BuildsPortal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        DB::table('platform_setup')->delete();
        DB::table('platform_setup')->insert(['id' => (string) Str::uuid7(), 'deployment_id' => (string) Str::uuid7(), 'state' => PlatformSetupState::COMPLETED->value,
            'completed_steps' => '[]', 'completed_at' => now(), 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(SetupState::class)->refresh();
    }

    public function test_sign_in_page_renders_with_brand_and_hardened_headers(): void
    {
        $response = $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login', false)->has('brand.app_name'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'/", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/stock')->assertRedirect('/login');
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonStructure(['name', 'short_name', 'start_url', 'icons']);
    }

    public function test_signed_in_pages_and_api_answers_are_never_stored_while_public_ones_keep_their_caching(): void
    {
        $person = $this->actorWith(['inventory.stock.read']);

        $this->assertStringNotContainsString('no-store', (string) $this->get('/login')->assertOk()->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $this->actingAs($person, 'web')->get('/stock')->assertOk()->headers->get('Cache-Control'));
        $this->app['auth']->forgetGuards();
        $api = $this->withToken($person->createToken('t')->plainTextToken)->getJson('/api/v1/me')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $api->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', (string) $this->getJson('/api/v1/meta/brand')->assertOk()->headers->get('Cache-Control'), 'the public brand profile stays cacheable');
    }

    public function test_the_product_identity_is_fixed_whatever_the_customer_brand_kit_says(): void
    {
        DB::table('company_profile')->delete();
        DB::table('company_profile')->insert(['id' => (string) Str::uuid7(), 'legal_name' => 'Acme Trading plc', 'trading_name' => 'Acme Pharma',
            'base_currency' => 'ETB', 'created_at' => now(), 'updated_at' => now()]);
        app(BrandKitService::class)->createAndActivate(['app_name' => 'Acme Stock', 'app_short_name' => 'Acme',
            'legal_footer' => 'Acme Pharma, Addis Ababa', 'theme' => ['colors' => ['brand' => '#7a1f1f', 'nav_background' => '#7a1f1f']]], null);
        app(BrandResolver::class)->forget();
        $product = config('product');

        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('brand.app_name', $product['name'])
            ->where('brand.product.name', $product['name'])
            ->where('brand.product.vendor', $product['vendor'])
            ->where('brand.company_name', 'Acme Pharma')
            ->where('brand.theme.colors.brand', '#7a1f1f'))
            ->assertSee('rel="icon"', false)
            ->assertSee($product['assets']['favicon_32'], false);

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
        $this->assertSame([$product['name'], $product['short_name']], [$manifest['name'], $manifest['short_name']]);
        $this->assertStringContainsString('Acme Pharma', $manifest['description']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith('/product/', $icon['src']);
            $this->assertFileExists(public_path(strtok($icon['src'], '?')));
        }
        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));

        $css = (string) $this->get('/brand/theme.css')->assertOk()->getContent();
        $this->assertStringContainsString('--ssr-brand: #7a1f1f;', $css, 'the customer keeps its colours');
        $this->assertStringContainsString("--product-ink: {$product['colors']['navy']};", $css, 'the wordmark keeps the product colours');
    }

    public function test_navigation_and_resource_actions_follow_the_persons_permissions(): void
    {
        $clerk = $this->actorWith(['transfers.transfer.read', 'transfers.transfer.submit']);
        $this->actingAs($clerk, 'web')->get('/r/transfers')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resources/Index', false)
            ->where('resource.key', 'transfers')
            ->where('resource.can_create', false)
            ->where('nav', fn ($nav) => collect($nav)->firstWhere('area', 'work')['items'] !== null
                && collect(collect($nav)->firstWhere('area', 'work')['items'])->pluck('href')->contains('/work/transfers')
                && ! collect($nav)->contains('area', 'admin')));

        $actions = collect($this->actingAs($clerk, 'web')->get('/r/transfers/'.Str::uuid7())->viewData('page')['props']['resource']['actions'])->pluck('name');
        $this->assertContains('submit', $actions->all());
        $this->assertNotContains('approve', $actions->all(), 'actions the person cannot run are not offered');

        $this->actingAs($clerk, 'web')->get('/r/no-such-resource')->assertNotFound();
        $this->actingAs($clerk, 'web')->get('/r/roles')->assertForbidden();
    }

    public function test_daily_work_and_administration_are_separate_areas_that_together_reach_every_resource(): void
    {
        $nav = collect($this->actingAs($this->superAdmin()->refresh(), 'web')->get('/')->assertOk()->viewData('page')['props']['nav']);
        $work = collect($nav->firstWhere('area', 'work')['items']);
        $admin = $nav->where('area', 'admin')->flatMap(fn ($s) => $s['items']);

        $this->assertSame(['/', '/stock'], $work->pluck('href')->take(2)->all(), 'Home and Stock lead the daily navigation');
        $this->assertSame('/reports', $work->last()['href']);
        $this->assertTrue($admin->pluck('href')->contains('/admin/config'));
        $this->assertFalse($work->pluck('href')->contains(fn ($h) => str_starts_with($h, '/admin')), 'administration stays out of the daily navigation');

        $reachable = $nav->flatMap(fn ($s) => $s['items'])->flatMap(fn ($i) => array_merge([$i['href']], $i['match'] ?? []));
        $missing = collect(array_keys(ResourceRegistry::all()))->reject(fn ($key) => $reachable->contains("/r/$key"))->values()->all();
        $this->assertSame([], $missing, 'every resource has a place in one of the two areas');
    }

    public function test_role_assignments_are_created_and_revoked_through_the_step_up_administration_routes(): void
    {
        $administrator = $this->actorWith(['identity.assignment.read', 'identity.assignment.create', 'identity.assignment.revoke']);
        $this->actingAs($administrator, 'web')->get('/r/role-assignments')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.can_create', true)
            ->where('resource.can_update', false)
            ->where('resource.create_schema', 'role_assignment')
            ->where('resource.api_create', '/api/v1/admin/role-assignments'));
        $this->actingAs($administrator, 'web')->get('/r/role-assignments/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Resources/Form', false));

        $revoke = collect($this->actingAs($administrator, 'web')->get('/r/role-assignments/'.Str::uuid7())->viewData('page')['props']['resource']['actions'])->firstWhere('name', 'revoke');
        $this->assertSame(['POST', '/api/v1/admin/role-assignments/{id}/revoke', 'id'], [$revoke['method'], $revoke['uri'], $revoke['parameter']]);
    }

    public function test_create_variants_post_to_their_own_endpoint_and_unknown_variants_are_not_found(): void
    {
        $this->setConfig('feature.production_store', true);
        $receiver = $this->actorWith(['inventory.movement.read', 'fulfillment.production.receive']);
        $this->browse($receiver)->get('/r/movements/new?via=production-receipt')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resources/Form', false)
            ->where('resource.noun', 'receipt from production')
            ->where('resource.api_create', '/api/v1/production-receipts')
            ->where('resource.create_schema', 'production_receipt')
            ->where('resource.create_idempotent', true)
            ->where('resource.create_land', '/r/movements/{stock_movement_id}'));

        $this->browse($receiver)->get('/r/movements/new?via=teleport')->assertNotFound();
        $this->browse($receiver)->get('/r/movements/new')->assertForbidden();
        $this->browse($this->actorWith(['inventory.movement.read']))->get('/r/movements/new?via=production-receipt')->assertForbidden();
    }

    public function test_record_pages_offer_dedicated_edits_and_follow_up_links_only_with_their_permissions(): void
    {
        $lot = (string) Str::uuid7();
        $keeper = $this->actorWith(['inventory.lot.read', 'inventory.lot.update', 'inventory.trace.read']);
        $this->browse($keeper)->get("/r/lots/$lot")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.can_update', true)
            ->where('resource.update_schema', 'lot_update')
            ->where('resource.api_update', '/api/v1/lots/{id}')
            ->where('resource.next', [['label' => 'Trace this lot', 'href' => '/trace?lot={id}', 'icon' => 'route']]));
        $this->browse($keeper)->get("/r/lots/$lot/edit")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Resources/Form', false));

        $reader = $this->actorWith(['inventory.lot.read']);
        $this->browse($reader)->get("/r/lots/$lot")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.can_update', false)
            ->where('resource.next', []));
        $this->browse($reader)->get("/r/lots/$lot/edit")->assertForbidden();
    }

    public function test_actions_whose_service_posts_to_the_ledger_send_an_idempotency_key_and_others_do_not(): void
    {
        $approver = $this->actorWith(['inventory.movement.read', 'inventory.movement.reverse']);
        $actions = collect($this->browse($approver)->get('/r/movement-reversals/'.Str::uuid7())->assertOk()->viewData('page')['props']['resource']['actions'])->keyBy('name');
        $this->assertSame(['/api/v1/reversals/{reversalId}/approve', true, ['reversalId' => 'id']],
            [$actions['approve']['uri'], $actions['approve']['idempotent'], $actions['approve']['params']]);
        $this->assertFalse($actions['reject']['idempotent'], 'rejecting posts nothing');

        $courier = $this->actorWith(['fulfillment.shipment.read', 'fulfillment.shipment.update_status']);
        $events = collect($this->browse($courier)->get('/r/shipments/'.Str::uuid7())->assertOk()->viewData('page')['props']['resource']['actions'])->firstWhere('name', 'events');
        $this->assertTrue($events['idempotent'], 'a delivery event can post the sale, so it goes through the posting pipeline');
    }

    public function test_read_panels_and_approval_history_are_shown_only_with_their_read_permission(): void
    {
        $this->setConfig('feature.recalls', true);
        $this->browse($this->actorWith(['inventory.recall.read']))->get('/r/recalls/'.Str::uuid7())->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.panels', [['label' => 'Affected stock', 'uri' => '/api/v1/recalls/{recallId}/affected']]));

        $order = '/r/purchase-orders/'.Str::uuid7();
        $this->browse($this->actorWith(['procurement.purchase_order.read', 'audit.approval.read']))->get($order)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.panels', [['label' => 'Approval history', 'uri' => '/api/v1/audit/approvals?document_type=purchase_order&document_id={id}']]));
        $this->browse($this->actorWith(['procurement.purchase_order.read']))->get($order)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('resource.panels', []));
    }

    public function test_role_assignment_readers_without_create_or_revoke_permissions_get_a_read_only_list(): void
    {
        $viewer = $this->actorWith(['identity.assignment.read']);
        $this->actingAs($viewer, 'web')->get('/r/role-assignments')->assertOk()->assertInertia(fn (Assert $page) => $page->where('resource.can_create', false));
        $this->actingAs($viewer, 'web')->get('/r/role-assignments/new')->assertForbidden();
        $this->assertSame([], $this->actingAs($viewer, 'web')->get('/r/role-assignments/'.Str::uuid7())->viewData('page')['props']['resource']['actions']);
    }

    public function test_every_notification_link_opens_an_existing_page(): void
    {
        $this->enablePortal($this->warehouse('NL'.Str::upper(Str::random(4))));
        $admin = $this->superAdmin()->refresh();   // fully loaded, as after a real sign-in
        $customer = $this->customerMember($this->customerPartner());
        foreach (NotificationRouter::ROUTES as $event => $route) {
            $link = preg_replace('/\{[a-z_]+_id\}/', (string) Str::uuid7(), $route[5]);
            $this->assertStringNotContainsString('{', $link, "$event: unresolved placeholder in $link");
            // Customer notifications open customer pages; their records are fetched (and checked) by the page.
            $this->app['auth']->forgetGuards();
            $this->flushSession();
            $response = $this->actingAs(str_starts_with($link, '/portal/') ? $customer : $admin, 'web')->get($link);
            $this->assertSame(200, $response->getStatusCode(), "$event links to $link, which answers {$response->getStatusCode()} ".($response->exception?->getMessage() ?? ''));
        }
    }

    /** A browser session of this person (a new session: AuthenticateSession ends one that changes hands). */
    private function browse(UserAccount $account): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return $this->actingAs($account, 'web');
    }
}
