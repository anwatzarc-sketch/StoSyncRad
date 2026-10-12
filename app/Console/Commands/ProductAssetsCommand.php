<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;

/**
 * `product:assets` — regenerate the product logo and icons in public/product (and public/favicon.ico)
 * from the source artwork in resources/product. Run after replacing the artwork, then bump
 * `product.assets_version`.
 */
final class ProductAssetsCommand extends Command
{
    protected $signature = 'product:assets';

    protected $description = 'Generate the product logo, favicons and app icons from resources/product.';

    public function handle(): int
    {
        $source = resource_path('product');
        $out = public_path('product');
        if (! is_dir($out)) {
            mkdir($out, 0755, true);
        }

        $mark = $this->trim($this->load("$source/stocksync-mark-source.png"));
        $logo = $this->trim($this->load("$source/stocksync-logo-source.png"));

        $this->save($this->fit($mark, 512, 512, 0.0, null), "$out/stocksync-mark.png");
        $this->save($this->fit($logo, 1200, (int) round(1200 * imagesy($logo) / imagesx($logo)), 0.0, null), "$out/stocksync-logo.png");

        $favicons = [];
        foreach ([16, 32, 48] as $size) {
            // Small sizes keep a thin margin so the mark stays legible.
            $icon = $this->fit($mark, $size, $size, 0.04, null);
            $this->save($icon, "$out/favicon-$size.png");
            $favicons[$size] = (string) file_get_contents("$out/favicon-$size.png");
        }
        file_put_contents(public_path('favicon.ico'), $this->ico($favicons));

        // Launcher icons sit on an opaque white tile; maskable keeps the mark inside the 80% safe zone.
        $this->save($this->fit($mark, 180, 180, 0.14, [255, 255, 255]), "$out/apple-touch-icon.png");
        $this->save($this->fit($mark, 192, 192, 0.12, [255, 255, 255]), "$out/icon-192.png");
        $this->save($this->fit($mark, 512, 512, 0.12, [255, 255, 255]), "$out/icon-512.png");
        $this->save($this->fit($mark, 512, 512, 0.24, [255, 255, 255]), "$out/icon-maskable-512.png");

        $this->info("Product assets written to $out and public/favicon.ico. Bump product.assets_version to refresh caches.");

        return self::SUCCESS;
    }

    /** Load a PNG and turn an opaque near-white background into transparency (colour-to-alpha). */
    private function load(string $path): GdImage
    {
        $src = imagecreatefrompng($path) ?: throw new \RuntimeException("Cannot read $path");
        $w = imagesx($src);
        $h = imagesy($src);
        $img = $this->canvas($w, $h, null);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($src, $x, $y);
                $alpha = 1 - (($c >> 24) & 0x7F) / 127;          // 1 = opaque
                [$r, $g, $b] = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
                // Distance from white decides the remaining opacity; colours are un-blended from white.
                $a = max(255 - $r, 255 - $g, 255 - $b) / 255;
                $a = $a < 0.02 ? 0.0 : min(1.0, ($a - 0.02) / 0.98);
                if ($a > 0) {
                    [$r, $g, $b] = array_map(fn ($v) => (int) max(0, min(255, round(255 - (255 - $v) / $a))), [$r, $g, $b]);
                }
                $a *= $alpha;
                imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, (int) round(127 - $a * 127)));
            }
        }

        return $img;
    }

    /** Crop to the visible content. */
    private function trim(GdImage $img): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        [$minX, $minY, $maxX, $maxY] = [$w, $h, 0, 0];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 120) {
                    [$minX, $minY, $maxX, $maxY] = [min($minX, $x), min($minY, $y), max($maxX, $x), max($maxY, $y)];
                }
            }
        }
        $crop = $this->canvas($maxX - $minX + 1, $maxY - $minY + 1, null);
        imagecopy($crop, $img, 0, 0, $minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1);

        return $crop;
    }

    /** Scale into a w×h canvas, centred, with a margin (fraction of the shorter side) and optional background. */
    private function fit(GdImage $img, int $w, int $h, float $margin, ?array $background): GdImage
    {
        $canvas = $this->canvas($w, $h, $background);
        $pad = (int) round(min($w, $h) * $margin);
        $scale = min(($w - 2 * $pad) / imagesx($img), ($h - 2 * $pad) / imagesy($img));
        $dw = max(1, (int) round(imagesx($img) * $scale));
        $dh = max(1, (int) round(imagesy($img) * $scale));
        imagecopyresampled($canvas, $img, intdiv($w - $dw, 2), intdiv($h - $dh, 2), 0, 0, $dw, $dh, imagesx($img), imagesy($img));

        return $canvas;
    }

    private function canvas(int $w, int $h, ?array $background): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, $background === null
            ? imagecolorallocatealpha($img, 0, 0, 0, 127)
            : imagecolorallocate($img, $background[0], $background[1], $background[2]));
        // Transparent canvases copy alpha as is; opaque tiles blend the artwork onto the background.
        imagealphablending($img, $background !== null);

        return $img;
    }

    private function save(GdImage $img, string $path): void
    {
        imagesavealpha($img, true);
        imagepng($img, $path, 9);
    }

    /** Windows icon container holding PNG images (supported since Windows Vista and by all browsers). */
    private function ico(array $pngs): string
    {
        $header = pack('vvv', 0, 1, count($pngs));
        $directory = '';
        $data = '';
        $offset = 6 + 16 * count($pngs);
        foreach ($pngs as $size => $png) {
            $directory .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
            $data .= $png;
            $offset += strlen($png);
        }

        return $header.$directory.$data;
    }
}
