<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Schemas;

use App\Domains\Catalog\Enums\TrackingMode;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Change how an item is tracked (only with no stock anywhere). */
final class TrackingConversionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'items';
    }

    protected static function define(): array
    {
        return [
            'tracking_mode' => Property::enum(TrackingMode::class)->required()->virtual()->label('Tracking'),
            'reason' => Property::text(2000)->required()->trim()->virtual()->label('Reason'),
        ];
    }
}
