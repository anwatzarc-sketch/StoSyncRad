<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Output;

use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF via DomPDF (shared-hosting friendly default): brand letterhead, repeating headers, page
 * numbers, run metadata footer, configurable size/orientation, row cap with a visible notice.
 * Rows are rendered into an HTML table buffer on disk, never kept as a PHP array.
 */
final class PdfReportWriter implements ReportWriter
{
    private string $path = '';

    private string $bodyPath = '';

    /** @var resource|null */
    private $body = null;

    private array $columns = [];

    private array $meta = [];

    private int $written = 0;

    private int $cap = 0;

    public function __construct(private readonly CellFormatter $cells, private readonly PlatformConfig $config, private readonly BrandResolver $brand) {}

    public function open(string $path, array $columns, array $meta): void
    {
        $this->path = $path;
        $this->columns = $columns;
        $this->meta = $meta;
        $this->cap = $this->config->int('reporting.max_pdf_rows');
        $this->bodyPath = $path.'.rows.html';
        $this->body = fopen($this->bodyPath, 'wb') ?: throw new \RuntimeException("Cannot open {$this->bodyPath}");
    }

    public function write(array $row): void
    {
        if (++$this->written > $this->cap) {
            return;
        }
        $cells = '';
        foreach ($this->columns as $c) {
            $numeric = in_array($c['type'], ['integer', 'decimal', 'money', 'quantity'], true);
            $cells .= '<td'.($numeric ? ' class="n"' : '').'>'.e($this->cells->text($c, $row[$c['key']] ?? null)).'</td>';
        }
        fwrite($this->body, "<tr>$cells</tr>\n");
    }

    public function close(int $rowCount, bool $truncated): void
    {
        fclose($this->body);
        $layout = (array) ($this->meta['layout'] ?? []);
        $pdfTruncated = $truncated || $rowCount > $this->cap;
        $html = view('reports.pdf', [
            'brand' => $this->brand->publicProfile(), 'meta' => $this->meta, 'columns' => $this->columns,
            'rows' => file_get_contents($this->bodyPath), 'rowCount' => min($rowCount, $this->cap), 'truncated' => $pdfTruncated,
            'totalRows' => $rowCount, 'title' => $layout['title'] ?? ($this->meta['report_name'] ?? 'Report'),
        ])->render();
        @unlink($this->bodyPath);
        Pdf::loadHTML($html)
            ->setPaper((string) ($layout['page_size'] ?? $this->config->get('reporting.pdf.default_page_size')),
                (string) ($layout['orientation'] ?? $this->config->get('reporting.pdf.default_orientation')))
            ->setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->save($this->path);
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }
}
