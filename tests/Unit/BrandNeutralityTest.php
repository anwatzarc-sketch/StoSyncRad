<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CI gate (Chapter 03 §3.5 "No hard-coded brand strings"): user-facing sources — Vue pages and
 * components, Blade views, document/e-mail templates, translations — never name the product; they
 * read the active brand kit. Technical identifiers (DB roles, bundle format, `platform.codename`) live
 * in PHP/config and are out of scope.
 */
final class BrandNeutralityTest extends TestCase
{
    private const SCANNED = ['resources/js', 'resources/views', 'resources/templates', 'lang'];

    private const IGNORED = ['resources/js/types/generated.ts'];

    public function test_user_facing_sources_do_not_hard_code_the_product_name(): void
    {
        $root = dirname(__DIR__, 2);
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
                if (stripos((string) file_get_contents($file->getPathname()), 'stosyncrad') !== false) {
                    $offenders[] = $relative;
                }
            }
        }

        $this->assertSame([], $offenders, 'Use the brand kit (brand.app_name …) instead of the product name in: '.implode(', ', $offenders));
    }
}
