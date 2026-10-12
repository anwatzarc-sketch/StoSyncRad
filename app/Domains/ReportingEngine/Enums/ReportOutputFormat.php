<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Report output format. */
enum ReportOutputFormat: string implements SystemEnum
{
    use HasSystemEnum;

    case WEB = 'web';
    case PDF = 'pdf';
    case XLSX = 'xlsx';
    case CSV = 'csv';

    public function permission(): string
    {
        return match ($this) {
            self::WEB => 'reporting.report.run',
            self::PDF => 'reporting.report.export_pdf',
            self::XLSX => 'reporting.report.export_xlsx',
            self::CSV => 'reporting.report.export_csv',
        };
    }

    public function isExport(): bool
    {
        return $this !== self::WEB;
    }

    public function mime(): string
    {
        return match ($this) {
            self::WEB => 'application/json',
            self::PDF => 'application/pdf',
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::CSV => 'text/csv',
        };
    }
}
