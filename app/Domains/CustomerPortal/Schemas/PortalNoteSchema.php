<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** A customer note or reason. */
final class PortalNoteSchema extends TableSchema
{
    public static function table(): string
    {
        return 'payment_confirmations';
    }

    protected static function define(): array
    {
        return [
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
            'note' => Property::text(2000)->nullable()->trim()->virtual()->label('Note'),
            'evidence' => Property::any()->nullable()->virtual()->label('Evidence files (checked by the upload handler)'),
        ];
    }
}
