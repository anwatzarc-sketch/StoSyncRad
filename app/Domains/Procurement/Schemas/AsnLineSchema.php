<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class AsnLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'advance_shipment_notices';
    }

    protected static function define(): array
    {
        return [
            'purchase_order_line_id' => Property::uuid()->required()->virtual()->label('Order line'),
            'quantity' => Property::quantity()->required()->positive()->virtual()->label('Announced quantity'),
            'lot_number' => Property::string(80)->nullable()->trim()->pattern('/^[A-Za-z0-9._\/-]{1,80}$/')->virtual()->label('Lot / batch'),
            'manufacture_date' => Property::date()->nullable()->virtual()->label('Manufactured'),
            'expiry_date' => Property::date()->nullable()->after('manufacture_date')->virtual()->label('Expiry'),
            'serial_numbers' => Property::arrayOf(Property::string(120)->trim()->pattern('/^[A-Za-z0-9._\/-]{1,120}$/'))->nullable()->max(5000)->virtual()->label('Serial numbers'),
        ];
    }
}
