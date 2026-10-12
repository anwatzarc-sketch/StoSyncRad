<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class ExchangeKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::int('exchange.import.max_rows', 100000, 'Import row limit', 'Maximum rows per import file.', 'G', 10, 1000000),
            self::int('exchange.import.chunk_size', 500, 'Import chunk size', 'Rows processed per import chunk.', 'G', 10, 10000),
            self::int('exchange.import.retention_days', 30, 'Import file retention', 'Days uploaded import files are kept.', 'G', 1, 365),
            self::int('exchange.attachments.max_mb', 25, 'Attachment size limit', 'Maximum attachment size in MB.', 'G', 1, 500),
            self::list('exchange.attachments.allowed_mime', ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'text/csv',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], 'Allowed attachment types', 'MIME types accepted for attachments.'),
        ];
    }
}
