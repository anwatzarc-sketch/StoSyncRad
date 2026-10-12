<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Correct lot dates and references (audited). */
final class LotUpdateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'inventory_lots';
    }

    protected static function define(): array
    {
        return [
            'manufacture_date' => Property::date()->nullable()->virtual()->label('Manufactured'),
            'expiry_date' => Property::date()->nullable()->after('manufacture_date')->virtual()->label('Expiry'),
            'supplier_lot_reference' => Property::string(80)->nullable()->trim()->virtual()->label('Supplier lot reference'),
            'reason' => Property::text(2000)->nullable()->trim()->virtual()->label('Reason'),
        ];
    }
}
