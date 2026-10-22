<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Platform\Config\PlatformConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public product website (`feature.marketing_site`): the product pages visitors see before signing
 * in. The root URL is shared with the application: signed-in staff get the dashboard there. Search
 * metadata (title, description, canonical URL, Open Graph) is rendered on the server from APP_URL,
 * because the pages themselves are rendered in the browser.
 */
final class MarketingController
{
    /**
     * Pages by path. `:product` and `:vendor` are replaced with the product identity (config/product.php).
     *
     * @var array<string, array{component: string, title: string, description: string}>
     */
    public const PAGES = [
        '/' => ['component' => 'Marketing/Home', 'title' => 'Inventory control for every branch, lot and warehouse',
            'description' => ':product runs inventory across all your branches and warehouses from one ledger: receiving with QC and lot capture, verified transfers, FEFO picking, stocktaking, valuation and custom reports.'],
        '/features' => ['component' => 'Marketing/Features', 'title' => 'Features',
            'description' => 'Everything :product does: receiving and QC, lots, serials and expiry, inter-branch transfers, allocation and replenishment, fulfillment, stocktaking, valuation, reporting, scanning and administration.'],
        '/solutions' => ['component' => 'Marketing/Solutions', 'title' => 'Solutions',
            'description' => ':product for pharmaceutical distributors and pharmacies, retail chains, wholesale distribution, healthcare supply stores and e-commerce fulfillment.'],
        '/security' => ['component' => 'Marketing/Security', 'title' => 'Security and control',
            'description' => 'How :product protects your stock records: an append-only ledger, server-side permissions, approvals and separation of duties, MFA, a complete audit trail and least-privilege database access.'],
        '/onboarding' => ['component' => 'Marketing/Onboarding', 'title' => 'Onboarding',
            'description' => 'How a company goes live on :product: configuration and branding without code, data import from spreadsheets, opening balances, training and support.'],
        '/contact' => ['component' => 'Marketing/Contact', 'title' => 'Contact',
            'description' => 'Talk to :vendor about :product: book a demo, plan your rollout or ask a question.'],
    ];

    public function __construct(private readonly PlatformConfig $config) {}

    /** `/`: the dashboard for signed-in accounts; for visitors the product home page, or sign-in while the website is off. */
    public function home(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return Inertia::render('Dashboard');
        }
        if (! $this->config->feature('feature.marketing_site')) {
            return redirect()->guest(route('login'));
        }

        return $this->render('/');
    }

    public function show(Request $request): Response
    {
        abort_unless($this->config->feature('feature.marketing_site'), 404);

        return $this->render('/'.$request->path());
    }

    public function sitemap(): HttpResponse
    {
        abort_unless($this->config->feature('feature.marketing_site'), 404);
        $urls = array_map(fn (string $path) => '<url><loc>'.e($this->absolute($path)).'</loc></url>', array_keys(self::PAGES));

        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>',
            200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** Crawlers may read the product pages; the API and the setup wizard are never indexed. */
    public function robots(): HttpResponse
    {
        $lines = ['User-agent: *', 'Disallow: /api/', 'Disallow: /setup'];
        if ($this->config->feature('feature.marketing_site')) {
            $lines[] = 'Sitemap: '.$this->absolute('/sitemap.xml');
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function render(string $path): Response
    {
        $page = self::PAGES[$path] ?? abort(404);
        $identity = [':product' => (string) config('product.name'), ':vendor' => (string) config('product.vendor')];

        return Inertia::render($page['component'])->withViewData(['seo' => [
            'title' => strtr($page['title'], $identity).' · '.config('product.name'),
            'description' => strtr($page['description'], $identity),
            'url' => $this->absolute($path),
            'image' => $this->absolute((string) config('product.assets.icon_512')),
        ]]);
    }

    /** Canonical URLs come from APP_URL, never from the request's Host header. */
    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').($path === '/' ? '/' : $path);
    }
}
