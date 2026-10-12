<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Output;

use App\Support\Enums\EnumLabelResolver;
use App\Support\Enums\EnumRegistry;
use Carbon\CarbonImmutable;

/**
 * Typed cell values for exports and the web grid: enums as (configurable) labels, numbers as
 * numbers, dates as dates, booleans as Yes/No in text formats.
 */
final class CellFormatter
{
    /** @var array<string, class-string|null> */
    private array $enumClasses = [];

    public function __construct(private readonly EnumLabelResolver $labels, private readonly EnumRegistry $enums) {}

    /** @param  array{type: string, enum: ?string}  $column */
    public function label(array $column, mixed $value): ?string
    {
        if ($value === null || $column['enum'] === null) {
            return $value === null ? null : (string) $value;
        }
        $class = $this->enumClasses[$column['enum']] ??= $this->enums->byCode($column['enum']);
        $case = $class !== null ? $class::tryFrom((string) $value) : null;

        return $case !== null ? $this->labels->label($case) : (string) $value;
    }

    /**
     * Native value for spreadsheets (int/float/DateTimeImmutable/bool/string).
     *
     * @param  array{type: string, enum: ?string}  $column
     */
    public function typed(array $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column['type']) {
            'integer' => (int) $value,
            'decimal', 'money', 'quantity' => (float) $value,
            'date' => CarbonImmutable::parse((string) $value)->startOfDay()->toDateTimeImmutable(),
            'datetime' => CarbonImmutable::parse((string) $value)->toDateTimeImmutable(),
            'boolean' => (bool) $value,
            'enum' => $this->label($column, $value),
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };
    }

    /** Plain text (CSV, PDF). @param  array{type: string, enum: ?string}  $column */
    public function text(array $column, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($column['type']) {
            'boolean' => $value ? 'Yes' : 'No',
            'enum' => (string) $this->label($column, $value),
            'decimal', 'money', 'quantity' => \App\Support\Math\Decimal::trim((string) $value),
            'datetime' => CarbonImmutable::parse((string) $value)->format('Y-m-d H:i'),
            default => (string) $value,
        };
    }
}
