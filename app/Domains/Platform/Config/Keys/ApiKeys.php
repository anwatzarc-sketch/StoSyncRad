<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config\Keys;

use App\Domains\Platform\Config\DefinesConfigKeys;
use App\Domains\Platform\Config\ProvidesConfigKeys;

final class ApiKeys implements ProvidesConfigKeys
{
    use DefinesConfigKeys;

    public static function definitions(): array
    {
        return [
            self::int('api.max_body_kb', 2048, 'Maximum request body', 'Maximum JSON request body in KB.', 'G', 16, 65536),
            self::bool('api.allow_unknown_properties', false, 'Allow unknown properties', 'Ignore (instead of rejecting) properties not declared in the schema.'),
            self::int('api.rate_limit.default_per_minute', 300, 'Default rate limit', 'Requests per minute per account or token.', 'G', 10, 100000),
            self::int('api.rate_limit.posting_per_minute', 60, 'Posting rate limit', 'Posting requests per minute per account or token.', 'G', 1, 10000),
            self::int('api.idempotency.ttl_hours', 72, 'Idempotency retention', 'Hours idempotency keys are retained.', 'G', 1, 720),
            self::int('api.idempotency.stale_after_seconds', 120, 'Stale in-progress key', 'Seconds after which an in-progress key with no committed result may be re-executed.', 'G', 30, 3600),
            self::int('api.list.max_limit', 200, 'Maximum page size', 'Maximum items per list page.', 'G', 10, 1000),
        ];
    }
}
