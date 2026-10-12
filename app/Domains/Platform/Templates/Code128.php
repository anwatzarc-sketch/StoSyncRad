<?php

declare(strict_types=1);

namespace App\Domains\Platform\Templates;

/**
 * Minimal Code 128 (subset B) encoder producing inline SVG for documents and labels; works in
 * DomPDF without external services. Characters outside ASCII 32–126 are replaced with '?'.
 */
final class Code128
{
    /** Bar/space widths for symbol values 0–106 (106 = stop, 7 elements). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132',
        '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212',
        '322112', '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311',
        '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212',
        '124112', '124211', '411212', '421112', '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113',
        '411311', '113141', '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public static function svg(string $text, int $height = 40, float $module = 1.4): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        $codes = [104];   // start B
        foreach (str_split($text) as $ch) {
            $codes[] = ord($ch) - 32;
        }
        $sum = 104;
        foreach (array_slice($codes, 1) as $i => $c) {
            $sum += $c * ($i + 1);
        }
        $codes[] = $sum % 103;
        $codes[] = 106;   // stop

        $x = 10 * $module;   // quiet zone
        $bars = '';
        foreach ($codes as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $j => $w) {
                $width = (int) $w * $module;
                if ($j % 2 === 0) {
                    $bars .= sprintf('<rect x="%.2F" y="0" width="%.2F" height="%d"/>', $x, $width, $height);
                }
                $x += $width;
            }
        }
        $total = $x + 10 * $module;
        $label = htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%.0F" height="%d" viewBox="0 0 %.2F %d" role="img" aria-label="%s">'
            .'<g fill="#000">%s</g><text x="%.2F" y="%d" font-size="10" text-anchor="middle" font-family="monospace">%s</text></svg>',
            $total, $height + 13, $total, $height + 13, $label, $bars, $total / 2, $height + 11, $label);
    }
}
