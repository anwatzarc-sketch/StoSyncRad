<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Query;

/** SQL + bindings for one report run, plus the output column metadata. */
final class CompiledQuery
{
    /**
     * @param  array<int, mixed>  $bindings
     * @param  array<int, array{key: string, label: string, type: string, enum: ?string, format: ?array}>  $columns
     * @param  array<string, mixed>  $scope  authorization snapshot recorded on the run
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly array $columns,
        public readonly int $limit,
        public readonly int $offset,
        public readonly array $scope,
        public readonly string $datasetCode,
    ) {}

    /** The same query for a different page/limit (exports stream the unpaged statement). */
    public function unpaged(): string
    {
        return preg_replace('/\s+LIMIT \d+ OFFSET \d+$/', '', $this->sql) ?? $this->sql;
    }
}
