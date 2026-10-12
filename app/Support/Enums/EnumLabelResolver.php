<?php

declare(strict_types=1);

namespace App\Support\Enums;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves client-editable, localized labels for enum cases from the enum_labels table
 * (Chapter 03 §3.7). Falls back to the humanized case name. Cached per locale; the cache is
 * invalidated by EnumLabel writes.
 */
final class EnumLabelResolver
{
    public const CACHE_KEY = 'platform.enum_labels';

    /** @var array<string, array<string, array{label: string, description: ?string}>> locale → "code.case" → data */
    private array $memo = [];

    public function __construct(private readonly Cache $cache) {}

    public function label(SystemEnum $case): string
    {
        return $this->entry($case)['label'] ?? $case->defaultLabel();
    }

    public function description(SystemEnum $case): ?string
    {
        return $this->entry($case)['description'] ?? null;
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array{label: string, description: ?string}|null */
    private function entry(SystemEnum $case): ?array
    {
        $locale = app()->getLocale();
        $table = $this->table($locale);
        $key = $case::enumCode().'.'.$case->value;

        return $table[$key] ?? ($locale !== 'en' ? ($this->table('en')[$key] ?? null) : null);
    }

    /** @return array<string, array{label: string, description: ?string}> */
    private function table(string $locale): array
    {
        if (isset($this->memo[$locale])) {
            return $this->memo[$locale];
        }

        $all = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            try {
                if (! Schema::hasTable('enum_labels')) {
                    return [];
                }

                $rows = DB::table('enum_labels')->get(['enum_code', 'case_value', 'locale', 'label', 'description']);
            } catch (QueryException) {
                return [];
            }

            $out = [];
            foreach ($rows as $row) {
                $out[$row->locale][$row->enum_code.'.'.$row->case_value] = [
                    'label' => $row->label,
                    'description' => $row->description,
                ];
            }

            return $out;
        });

        return $this->memo[$locale] = $all[$locale] ?? [];
    }
}
