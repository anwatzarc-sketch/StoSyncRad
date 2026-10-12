<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Domains\Inventory\Enums\LotQcStatus;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Quality decision on a lot. */
final class QcDecisionSchema extends TableSchema
{
    public static function table(): string
    {
        return 'inventory_lots';
    }

    protected static function define(): array
    {
        return [
            'decision' => Property::enum(LotQcStatus::class)->required()->virtual()->label('Decision'),
            'notes' => Property::text(2000)->nullable()->trim()->virtual()->label('Notes'),
        ];
    }
}
