<?php

declare(strict_types=1);

namespace App\Support\Enums;

use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionEnum;
use Symfony\Component\Finder\Finder;

/**
 * Discovers every SystemEnum in the configured directories (Chapter 05 §5.2).
 * Used by meta endpoints, TS/Markdown exporters and the enum/CHECK parity test.
 */
final class EnumRegistry
{
    /** @var array<string, class-string<SystemEnum>>|null enumCode → class */
    private ?array $enums = null;

    /** @return Collection<string, class-string<SystemEnum>> */
    public function all(): Collection
    {
        if ($this->enums === null) {
            $this->enums = $this->discover();
        }

        return collect($this->enums);
    }

    /** @return class-string<SystemEnum>|null */
    public function byCode(string $code): ?string
    {
        return $this->all()->get($code);
    }

    /** @return array<int, array{code: string, class: string, is_state: bool, cases: array<int, array{value: string, label: string, description: ?string}>, transitions: array<string, array<int,string>>|null}> */
    public function export(): array
    {
        return $this->all()->map(function (string $class, string $code): array {
            $isState = is_subclass_of($class, StateEnum::class);

            return [
                'code' => $code,
                'class' => $class,
                'is_state' => $isState,
                'cases' => array_map(static fn (BackedEnum $case) => [
                    'value' => (string) $case->value,
                    'label' => $case->label(),
                    'description' => $case->description(),
                ], $class::cases()),
                'transitions' => $isState ? $class::transitions() : null,
            ];
        })->values()->all();
    }

    /** @return array<string, class-string<SystemEnum>> */
    private function discover(): array
    {
        $found = [];
        $dirs = array_filter((array) config('platform.enum_directories'), 'is_dir');

        if ($dirs === []) {
            return [];
        }

        $finder = (new Finder)->files()->in($dirs)->name('*.php')->path('/Enums/');

        foreach ($finder as $file) {
            $class = $this->classFromPath($file->getRealPath());
            if ($class === null || ! enum_exists($class)) {
                continue;
            }

            $ref = new ReflectionEnum($class);
            if (! $ref->isBacked() || ! $ref->implementsInterface(SystemEnum::class)) {
                continue;
            }

            /** @var class-string<SystemEnum> $class */
            $found[$class::enumCode()] = $class;
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

        $relative = Str::of($normalized)->after($appPath.'/')->beforeLast('.php')->replace('/', '\\')->toString();

        return 'App\\'.$relative;
    }
}
