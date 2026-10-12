<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Output;

/** Streaming export writer: rows arrive one at a time from a database cursor (Chapter 10 §10.6). */
interface ReportWriter
{
    /**
     * @param  array<int, array{key: string, label: string, type: string, enum: ?string, format: ?array}>  $columns
     * @param  array<string, mixed>  $meta  report name, definition version, filters, run timestamp, runner…
     */
    public function open(string $path, array $columns, array $meta): void;

    /** @param  array<string, mixed>  $row */
    public function write(array $row): void;

    /** Finish the file; $truncated adds the truncation notice. */
    public function close(int $rowCount, bool $truncated): void;

    public function extension(): string;

    public function mimeType(): string;
}
