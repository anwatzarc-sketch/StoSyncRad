<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers every TableSchema (Chapter 05 §5.3) for meta endpoints, exporters and parity tests.
 */
final class SchemaRegistry
{
    /** @var array<string, class-string<TableSchema>>|null name → class */
    private ?array $schemas = null;

    /** @return Collection<string, class-string<TableSchema>> */
    public function all(): Collection
    {
        if ($this->schemas === null) {
            $this->schemas = $this->discover();
        }

        return collect($this->schemas);
    }

    /** @return class-string<TableSchema>|null */
    public function byName(string $name): ?string
    {
        return $this->all()->get($name);
    }

    /** @return class-string<TableSchema>|null */
    public function byTable(string $table): ?string
    {
        return $this->all()->first(static fn (string $class) => $class::table() === $table);
    }

    /** @return array<int, array<string, mixed>> */
    public function export(): array
    {
        return $this->all()->map(static fn (string $class) => $class::toArray())->values()->all();
    }

    /** @return array<string, class-string<TableSchema>> */
    private function discover(): array
    {
        $found = [];
        $dirs = array_filter((array) config('platform.schema_directories'), 'is_dir');
        if ($dirs === []) {
            return [];
        }

        $finder = (new Finder)->files()->in($dirs)->name('*Schema.php')->path('/Schemas/');
        foreach ($finder as $file) {
            $class = $this->classFromPath($file->getRealPath());
            if ($class === null || ! class_exists($class)) {
                continue;
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(TableSchema::class)) {
                continue;
            }
            /** @var class-string<TableSchema> $class */
            $found[$class::name()] = $class;
        }
        ksort($found);

        return $found;
    }

    private function classFromPath(string $path): ?string
    {
        $appPath = str_replace('\\', '/', app_path());
        $normalized = str_replace('\\', '/', $path);
        if (! Str::startsWith($normalized, $appPath)) {
            return null;
        }

        return 'App\\'.Str::of($normalized)->after($appPath.'/')->beforeLast('.php')->replace('/', '\\')->toString();
    }
}
