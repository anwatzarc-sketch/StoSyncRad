<?php

declare(strict_types=1);

namespace App\Domains\Platform\Templates;

use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;

/**
 * Sandboxed renderer for client-editable templates (Chapter 03 §3.6). Templates are data, never
 * code: no PHP, no Blade compilation, no object access, no includes. Syntax:
 *
 *   {{ path.to.value | filter:arg }}          escaped output
 *   {{#each lines as line}} … {{/each}}      loop over a list ({{ @index }}, {{ @number }} available)
 *   {{#if path}} … {{else}} … {{/if}}         truthiness; {{#unless path}} … {{/unless}}
 *
 * Filters: upper, lower, title, date, datetime, qty, money, number:n, default:text, truncate:n,
 * barcode (Code 128 SVG), qrcode (SVG), nl2br. Only barcode/qrcode/nl2br emit markup, built here.
 */
final class SafeTemplateRenderer
{
    private const MAX_ITERATIONS = 20000;

    private const MAX_OUTPUT = 5_000_000;

    private int $iterations = 0;

    public function __construct(private readonly string $dateFormat = 'Y-m-d', private readonly string $timezone = 'UTC') {}

    /** @param  array<string, mixed>  $data */
    public function render(string $template, array $data): string
    {
        $this->iterations = 0;
        $tokens = $this->tokenize($template);
        $pos = 0;
        $tree = $this->parse($tokens, $pos, null);
        $out = $this->renderNodes($tree, [$data]);
        if (strlen($out) > self::MAX_OUTPUT) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The rendered document is too large.');
        }

        return $out;
    }

    /** Syntax check used when saving a template (throws with the problem). */
    public function validate(string $template): void
    {
        $pos = 0;
        $this->parse($this->tokenize($template), $pos, null);
    }

    /** @return array<int, array{0: string, 1: string}> [type, value]: text | tag */
    private function tokenize(string $template): array
    {
        $parts = preg_split('/(\{\{.*?\}\})/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(fn ($p) => str_starts_with($p, '{{') && str_ends_with($p, '}}') ? ['tag', trim(substr($p, 2, -2))] : ['text', $p], $parts);
    }

    /** @return array<int, array<string, mixed>> */
    private function parse(array $tokens, int &$pos, ?string $closing): array
    {
        $nodes = [];
        while ($pos < count($tokens)) {
            [$type, $value] = $tokens[$pos++];
            if ($type === 'text') {
                $nodes[] = ['t' => 'text', 'v' => $value];

                continue;
            }
            if (preg_match('/^#each\s+([a-z0-9_.@]+)\s+as\s+([a-z_][a-z0-9_]*)$/i', $value, $m)) {
                $nodes[] = ['t' => 'each', 'path' => $m[1], 'as' => $m[2], 'body' => $this->parse($tokens, $pos, 'each')];
            } elseif (preg_match('/^#(if|unless)\s+([a-z0-9_.@]+)$/i', $value, $m)) {
                $body = $this->parse($tokens, $pos, $m[1]);
                $else = [];
                $split = array_search('__else__', array_map(fn ($n) => $n['t'] === 'else' ? '__else__' : null, $body), true);
                if ($split !== false) {
                    $else = array_slice($body, $split + 1);
                    $body = array_slice($body, 0, $split);
                }
                $nodes[] = ['t' => $m[1], 'path' => $m[2], 'body' => $body, 'else' => $else];
            } elseif ($value === 'else') {
                $nodes[] = ['t' => 'else'];
            } elseif (preg_match('/^\/(each|if|unless)$/', $value, $m)) {
                if ($closing !== $m[1]) {
                    throw $this->error("Unexpected {{/{$m[1]}}}.");
                }

                return $nodes;
            } elseif (preg_match('/^([a-z0-9_.@]+)((\s*\|\s*[a-z0-9_]+(:[^|}]*)?)*)$/i', $value, $m)) {
                $filters = array_values(array_filter(array_map('trim', explode('|', $m[2]))));
                foreach ($filters as $f) {
                    $name = explode(':', $f, 2)[0];
                    if (! in_array($name, ['upper', 'lower', 'title', 'date', 'datetime', 'qty', 'money', 'number', 'default', 'truncate', 'barcode', 'qrcode', 'nl2br'], true)) {
                        throw $this->error("Unknown filter `$name`.");
                    }
                }
                $nodes[] = ['t' => 'var', 'path' => $m[1], 'filters' => $filters];
            } else {
                throw $this->error('Invalid tag {{'.mb_substr($value, 0, 60).'}}.');
            }
        }
        if ($closing !== null) {
            throw $this->error("Missing {{/$closing}}.");
        }

        return $nodes;
    }

    /** @param  array<int, array<string, mixed>>  $scopes  innermost last */
    private function renderNodes(array $nodes, array $scopes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (++$this->iterations > self::MAX_ITERATIONS * 10) {
                throw $this->error('The template is too complex.');
            }
            switch ($n['t']) {
                case 'text':
                    $out .= $n['v'];
                    break;
                case 'var':
                    $out .= $this->output($this->lookup($n['path'], $scopes), $n['filters']);
                    break;
                case 'each':
                    $list = $this->lookup($n['path'], $scopes);
                    foreach (is_array($list) ? array_values($list) : [] as $i => $item) {
                        if (++$this->iterations > self::MAX_ITERATIONS * 10) {
                            throw $this->error('Too many rows.');
                        }
                        $out .= $this->renderNodes($n['body'], array_merge($scopes, [[$n['as'] => $item, '@index' => $i, '@number' => $i + 1]]));
                    }
                    break;
                case 'if':
                case 'unless':
                    $truthy = $this->truthy($this->lookup($n['path'], $scopes));
                    $out .= $this->renderNodes(($n['t'] === 'if') === $truthy ? $n['body'] : $n['else'], $scopes);
                    break;
            }
        }

        return $out;
    }

    private function lookup(string $path, array $scopes): mixed
    {
        $parts = explode('.', $path);
        for ($s = count($scopes) - 1; $s >= 0; $s--) {
            if (is_array($scopes[$s]) && array_key_exists($parts[0], $scopes[$s])) {
                $value = $scopes[$s][$parts[0]];
                foreach (array_slice($parts, 1) as $p) {
                    $value = is_array($value) && array_key_exists($p, $value) ? $value[$p] : null;
                }

                return $value;
            }
        }

        return null;
    }

    private function truthy(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === '' || $v === [] || $v === '0' || (is_numeric($v) && (float) $v === 0.0));
    }

    /** @param  array<int, string>  $filters */
    private function output(mixed $value, array $filters): string
    {
        $safe = false;
        foreach ($filters as $f) {
            [$name, $arg] = array_pad(explode(':', $f, 2), 2, null);
            $arg = $arg !== null ? trim($arg, " '\"") : null;
            switch ($name) {
                case 'upper': $value = mb_strtoupper((string) $value); break;
                case 'lower': $value = mb_strtolower((string) $value); break;
                case 'title': $value = mb_convert_case((string) $value, MB_CASE_TITLE); break;
                case 'date': $value = $value ? CarbonImmutable::parse((string) $value)->setTimezone($this->timezone)->format($this->dateFormat) : ''; break;
                case 'datetime': $value = $value ? CarbonImmutable::parse((string) $value)->setTimezone($this->timezone)->format($this->dateFormat.' H:i') : ''; break;
                case 'qty': $value = $value === null || $value === '' ? '' : \App\Support\Math\Decimal::trim((string) $value); break;
                case 'money': $value = is_numeric($value) ? number_format((float) $value, 2) : ''; break;
                case 'number': $value = is_numeric($value) ? number_format((float) $value, max(0, min(8, (int) ($arg ?? 0)))) : ''; break;
                case 'default': $value = $this->truthy($value) ? $value : ($arg ?? ''); break;
                case 'truncate': $value = mb_strimwidth((string) $value, 0, max(1, (int) ($arg ?? 50)), '…'); break;
                case 'nl2br': $value = nl2br(e((string) $value)); $safe = true; break;
                case 'barcode': $value = $value ? Code128::svg((string) $value, (int) ($arg ?? 40)) : ''; $safe = true; break;
                case 'qrcode': $value = $value ? $this->qr((string) $value, (int) ($arg ?? 96)) : ''; $safe = true; break;
            }
        }
        if (is_array($value)) {
            $value = implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        return $safe ? (string) $value : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function qr(string $text, int $size): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(max(48, min(400, $size)), 1), new SvgImageBackEnd)))->writeString($text);

        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? '';
    }

    private function error(string $message): DomainException
    {
        return DomainException::of(ErrorCode::VALIDATION_FAILED, "Template error: $message", ['field' => 'body']);
    }
}
