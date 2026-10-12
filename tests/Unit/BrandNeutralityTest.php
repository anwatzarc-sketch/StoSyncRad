<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CI gate (Chapter 03 §3.5 "No hard-coded brand strings"): user-facing sources — Vue pages and
 * components, Blade views, document/e-mail templates, translations — never write the product name,
 * the vendor or the codename; they read `brand.product` (config/product.php, the single source) and
 * the customer's brand kit. Technical identifiers (DB roles, bundle format) live in PHP/config.
 */
final class BrandNeutralityTest extends TestCase
{
    private const SCANNED = ['resources/js', 'resources/views', 'resources/templates', 'lang'];

    private const IGNORED = ['resources/js/types/generated.ts'];

    public function test_user_facing_sources_do_not_hard_code_the_product_name(): void
    {
        $root = dirname(__DIR__, 2);
        $product = require "$root/config/product.php";
        $needles = array_filter(['stosyncrad', $product['name'], $product['vendor']]);
        $offenders = [];
        foreach (self::SCANNED as $dir) {
            if (! is_dir("$root/$dir")) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (in_array($relative, self::IGNORED, true) || ! preg_match('/\.(vue|ts|php|html|json|css)$/', $relative)) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($needles as $needle) {
                    if (stripos($contents, (string) $needle) !== false) {
                        $offenders[] = "$relative ($needle)";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Use brand.product / the brand kit instead of literal names in: '.implode(', ', $offenders));
    }
}
