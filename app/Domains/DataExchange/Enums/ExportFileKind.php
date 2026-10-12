<?php

declare(strict_types=1);

namespace App\Domains\DataExchange\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** What a private, expiring export file contains (Chapter 11 §11.10). */
enum ExportFileKind: string implements SystemEnum
{
    use HasSystemEnum;

    case REPORT = 'report';
    case IMPORT_TEMPLATE = 'import_template';
    case IMPORT_ERRORS = 'import_errors';
    case DATA_EXPORT = 'data_export';
    case CONFIG_BUNDLE = 'config_bundle';
}
