<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Output;

use App\Domains\Platform\Config\PlatformConfig;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Streaming XLSX (OpenSpout): typed cells, frozen header, auto filter, number/date formats,
 * optional raw enum code columns, and a metadata sheet (Chapter 10 §10.6).
 */
final class XlsxReportWriter implements ReportWriter
{
    private ?Writer $writer = null;

    private array $columns = [];

    private array $meta = [];

    private array $styles = [];

    private bool $rawCodes = false;

    public function __construct(private readonly CellFormatter $cells, private readonly PlatformConfig $config) {}

    public function open(string $path, array $columns, array $meta): void
    {
        $this->columns = $columns;
        $this->meta = $meta;
        $this->rawCodes = $this->config->bool('reporting.xlsx.include_raw_enum_codes');
        $this->writer = new Writer;
        $this->writer->openToFile($path);
        $sheet = $this->writer->getCurrentSheet();
        $sheet->setName(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string) ($meta['report_name'] ?? 'Report')), 0, 31) ?: 'Report');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $header = [];
        foreach ($columns as $c) {
            $header[] = $c['label'];
            if ($this->rawCodes && $c['enum'] !== null) {
                $header[] = $c['label'].' (code)';
            }
        }
        $this->writer->addRow(Row::fromValuesWithStyle($header, (new Style)->withFontBold(true)->withBackgroundColor('E8ECF4')));
        foreach (array_keys($header) as $i) {
            $sheet->setColumnWidth(min(60, max(10, mb_strlen($header[$i]) + 4)), $i + 1);
        }
        $this->styles = [
            'integer' => (new Style)->withFormat('#,##0'),
            'decimal' => (new Style)->withFormat('#,##0.######'),
            'quantity' => (new Style)->withFormat('#,##0.######'),
            'money' => (new Style)->withFormat('#,##0.00'),
            'date' => (new Style)->withFormat('yyyy-mm-dd'),
            'datetime' => (new Style)->withFormat('yyyy-mm-dd hh:mm'),
        ];
    }

    public function write(array $row): void
    {
        $cells = [];
        foreach ($this->columns as $c) {
            $raw = $row[$c['key']] ?? null;
            $cells[] = Cell::fromValue($this->cells->typed($c, $raw), $this->styles[$c['type']] ?? null);
            if ($this->rawCodes && $c['enum'] !== null) {
                $cells[] = Cell::fromValue($raw === null ? null : (string) $raw);
            }
        }
        $this->writer->addRow(new Row($cells));
    }

    public function close(int $rowCount, bool $truncated): void
    {
        $width = count($this->columns) + ($this->rawCodes ? count(array_filter($this->columns, fn ($c) => $c['enum'] !== null)) : 0);
        $this->writer->getCurrentSheet()->setAutoFilter(new AutoFilter(0, 1, max(0, $width - 1), $rowCount + 1));
        $meta = $this->writer->addNewSheetAndMakeItCurrent();
        $meta->setName('Report info');
        $bold = (new Style)->withFontBold(true);
        foreach (array_merge($this->meta, ['row_count' => $rowCount, 'truncated' => $truncated ? 'Yes — export row limit reached' : 'No']) as $k => $v) {
            $this->writer->addRow(new Row([Cell::fromValue(ucfirst(str_replace('_', ' ', (string) $k)), $bold),
                Cell::fromValue(is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v))]));
        }
        $meta->setColumnWidth(24, 1);
        $meta->setColumnWidth(90, 2);
        $this->writer->close();
        $this->writer = null;
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
}
