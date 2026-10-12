<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Output;

use App\Domains\Platform\Config\PlatformConfig;

/** Streaming CSV (UTF-8, optional BOM, configurable delimiter). */
final class CsvReportWriter implements ReportWriter
{
    /** @var resource|null */
    private $handle = null;

    private array $columns = [];

    public function __construct(private readonly CellFormatter $cells, private readonly PlatformConfig $config) {}

    public function open(string $path, array $columns, array $meta): void
    {
        $this->columns = $columns;
        $this->handle = fopen($path, 'wb') ?: throw new \RuntimeException("Cannot open $path");
        if ($this->config->bool('reporting.csv.bom')) {
            fwrite($this->handle, "\xEF\xBB\xBF");
        }
        $this->put(array_column($columns, 'label'));
    }

    public function write(array $row): void
    {
        $this->put(array_map(fn ($c) => $this->cells->text($c, $row[$c['key']] ?? null), $this->columns));
    }

    public function close(int $rowCount, bool $truncated): void
    {
        if ($truncated) {
            $this->put(["# Truncated after $rowCount rows (export row limit)."]);
        }
        fclose($this->handle);
        $this->handle = null;
    }

    /** @param  array<int, string>  $fields */
    private function put(array $fields): void
    {
        // Neutralize spreadsheet formula injection (=, +, -, @ at the start of text cells).
        $fields = array_map(fn ($f) => is_string($f) && $f !== '' && in_array($f[0], ['=', '+', '@'], true) ? "'".$f : $f, $fields);
        fputcsv($this->handle, $fields, (string) $this->config->get('reporting.csv.delimiter') ?: ',', '"', '');
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }
}
