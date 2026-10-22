<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Services\SetupState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsActors;
use Tests\Support\BuildsInventory;
use Tests\TestCase;

/** Public product website (feature.marketing_site): pages, shared root URL, search metadata and the off switch. */
final class MarketingSiteTest extends TestCase
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
        config(['app.url' => 'https://stock.radiants.net.et']);
    }

    #[TestWith(['/', 'Marketing/Home'])]
    #[TestWith(['/features', 'Marketing/Features'])]
    #[TestWith(['/solutions', 'Marketing/Solutions'])]
    #[TestWith(['/security', 'Marketing/Security'])]
    #[TestWith(['/onboarding', 'Marketing/Onboarding'])]
    #[TestWith(['/contact', 'Marketing/Contact'])]
    public function test_visitors_get_each_product_page_with_its_canonical_url_from_app_url(string $path, string $component): void
    {
        $response = $this->get('http://evil.example'.$path)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component, false));

        $canonical = 'https://stock.radiants.net.et'.$path;
        $response->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
            ->assertSee('<meta property="og:url" content="'.$canonical.'">', false)
            ->assertDontSee('evil.example');
    }

    public function test_signed_in_staff_get_the_dashboard_at_the_root_url(): void
    {
        $this->actingAs($this->actorWith(['inventory.stock.read']), 'web')->get('/')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dashboard', false));
    }

    public function test_the_sitemap_and_robots_point_crawlers_at_the_product_pages_only(): void
    {
        $sitemap = (string) $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $locations = array_map('strval', simplexml_load_string($sitemap)->xpath('//*[local-name()="loc"]'));
        $this->assertSame(['https://stock.radiants.net.et/', 'https://stock.radiants.net.et/features', 'https://stock.radiants.net.et/solutions',
            'https://stock.radiants.net.et/security', 'https://stock.radiants.net.et/onboarding', 'https://stock.radiants.net.et/contact'], $locations);

        $this->assertSame("User-agent: *\nDisallow: /api/\nDisallow: /setup\nSitemap: https://stock.radiants.net.et/sitemap.xml\n", $this->get('/robots.txt')->assertOk()->getContent());
    }

    public function test_switching_the_website_off_sends_visitors_to_sign_in_and_hides_the_pages(): void
    {
        $this->setConfig('feature.marketing_site', false);
        app(PlatformConfig::class)->forget();

        $this->get('/')->assertRedirect('/login');
        $this->get('/features')->assertNotFound();
        $this->get('/sitemap.xml')->assertNotFound();
        $this->assertSame("User-agent: *\nDisallow: /api/\nDisallow: /setup\n", $this->get('/robots.txt')->assertOk()->getContent());
    }
}
