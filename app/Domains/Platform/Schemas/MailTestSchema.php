<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Send a test e-mail with the current mail settings. */
final class MailTestSchema extends TableSchema
{
    public static function table(): string
    {
        return 'configuration_entries';
    }

    protected static function define(): array
    {
        return [
            'to' => Property::email()->required()->trim()->virtual()->label('Send the test to'),
        ];
    }
}
