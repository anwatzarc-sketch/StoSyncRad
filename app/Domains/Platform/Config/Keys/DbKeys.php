<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class DbKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::int('db.transaction.max_attempts', 3, 'Transaction attempts', 'Attempts for a posting that hits a deadlock or serialization failure.', 'G', 1, 5),
            self::int('db.transaction.backoff_ms', 50, 'Retry backoff', 'Base backoff in milliseconds between retries (jittered, exponential).', 'G', 0, 2000),
            self::int('db.lock_timeout_ms', 5000, 'Lock timeout', 'Maximum wait for a row lock during postings.', 'G', 100, 60000),
            self::int('db.statement_timeout_ms', 15000, 'Statement timeout', 'Maximum duration of one operational SQL statement.', 'G', 1000, 120000),
        ];
    }
}
