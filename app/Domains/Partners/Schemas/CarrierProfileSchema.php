<?php

declare(strict_types=1);

namespace App\Domains\Partners\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Carrier tracking link. */
final class CarrierProfileSchema extends TableSchema
{
    public static function table(): string
    {
        return 'partners';
    }

    protected static function define(): array
    {
        return [
            'tracking_url_template' => Property::url()->nullable()->virtual()->label('Tracking link', 'https link containing {tracking}'),
        ];
    }
}
