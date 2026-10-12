<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Services\SetupState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Chapter 12 — app shell: pages, sign-in redirect, security headers, server-filtered navigation and actions. */
final class WebShellTest extends TestCase
{
    use BuildsActors;
    use BuildsInventory;

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
}
