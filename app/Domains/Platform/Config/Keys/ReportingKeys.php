<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;
use App\Domains\ReportingEngine\Enums\PageOrientation;
use App\Domains\ReportingEngine\Enums\PageSize;
use App\Domains\ReportingEngine\Enums\PdfRenderer;

final class ReportingKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::int('reporting.interactive_timeout_seconds', 15, 'Interactive timeout', 'Statement timeout for web report runs.', 'G', 1, 120),
            self::int('reporting.export_timeout_seconds', 120, 'Export timeout', 'Statement timeout per export attempt.', 'G', 10, 600),
            self::int('reporting.max_sync_rows', 10000, 'Web row cap', 'Maximum detail rows returned to a web report run.', 'G', 100, 100000),
            self::int('reporting.max_export_rows', 1000000, 'Export row cap', 'Maximum rows written to an export file.', 'G', 1000, 10000000),
            self::int('reporting.max_pdf_rows', 5000, 'PDF row cap', 'Maximum rows rendered into a PDF.', 'G', 100, 100000),
            self::int('reporting.max_joins', 6, 'Maximum joins', 'Maximum relationships a report may join.', 'G', 0, 12),
            self::int('reporting.max_filter_nodes', 50, 'Maximum filter nodes', 'Maximum conditions and groups in a report filter.', 'G', 1, 500),
            self::int('reporting.max_definition_kb', 256, 'Maximum definition size', 'Maximum size of a report definition in KB.', 'G', 8, 4096),
            self::int('reporting.max_concurrent_interactive', 10, 'Concurrent web runs', 'Maximum simultaneous web report executions.', 'G', 1, 200),
            self::int('reporting.max_export_workers', 2, 'Concurrent exports', 'Maximum simultaneous export executions.', 'G', 1, 50),
            self::int('reporting.explain_cost_threshold', 5000000, 'Cost gate', 'Planner cost above which a run is rejected as too expensive (0 = disabled).', 'G', 0, 2000000000),
            self::int('reporting.export_retention_hours', 24, 'Export retention', 'Hours an export file remains downloadable.', 'G', 1, 720),
            self::int('reporting.max_file_mb', 200, 'Maximum export size', 'Maximum export file size in MB.', 'G', 1, 2048),
            self::enum('reporting.pdf.renderer', PdfRenderer::class, 'dompdf', 'PDF renderer', 'Engine used to render PDFs.'),
            self::enum('reporting.pdf.default_page_size', PageSize::class, 'a4', 'PDF page size', 'Default page size for PDF reports.'),
            self::enum('reporting.pdf.default_orientation', PageOrientation::class, 'portrait', 'PDF orientation', 'Default page orientation for PDF reports.'),
            self::bool('reporting.xlsx.include_raw_enum_codes', false, 'Raw enum codes in Excel', 'Add a raw-code column next to each enum label in Excel exports.'),
            self::str('reporting.csv.delimiter', ',', 'CSV delimiter', 'Field delimiter for CSV exports.', 'G', ['choices' => [',', ';', "\t", '|']]),
            self::bool('reporting.csv.bom', false, 'CSV byte-order mark', 'Prefix CSV exports with a UTF-8 BOM (helps Excel).'),
            self::bool('reporting.sharing.allow_company_wide', true, 'Company-wide sharing', 'Allow report definitions to be shared with the whole company.'),
            self::int('reporting.runs_per_account_per_hour', 120, 'Runs per hour', 'Maximum report runs per account per hour.', 'G', 1, 10000),
        ];
    }
}
