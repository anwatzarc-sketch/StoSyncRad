<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Messaging\Services\NotificationRouter;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Services\SetupState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\Support\BuildsPortal;
use Tests\TestCase;

/** Chapter 12 — app shell: pages, sign-in redirect, security headers, server-filtered navigation and actions. */
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

        $this->get('/')->assertRedirect('/login');
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonStructure(['name', 'short_name', 'start_url', 'icons']);
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
            ->where('nav', fn ($nav) => collect($nav)->flatMap(fn ($s) => $s['items'])->pluck('href')->contains('/r/transfers')
                && ! collect($nav)->flatMap(fn ($s) => $s['items'])->pluck('href')->contains('/admin/config')));

        $actions = collect($this->actingAs($clerk, 'web')->get('/r/transfers/'.Str::uuid7())->viewData('page')['props']['resource']['actions'])->pluck('name');
        $this->assertContains('submit', $actions->all());
        $this->assertNotContains('approve', $actions->all(), 'actions the person cannot run are not offered');

        $this->actingAs($clerk, 'web')->get('/r/no-such-resource')->assertNotFound();
        $this->actingAs($clerk, 'web')->get('/r/roles')->assertForbidden();
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
}
