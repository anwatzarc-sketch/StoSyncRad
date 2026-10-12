<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Platform\Models\LookupValue;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class TransferReceiptLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_receipt_lines';
    }

    protected static function define(): array
    {
        return [
            'transfer_dispatch_line_id' => Property::uuid()->required()->label('Dispatch line'),
            'accepted_quantity' => Property::quantity()->default('0')->nonNegative()->label('Accepted quantity'),
            'damaged_quantity' => Property::quantity()->default('0')->nonNegative()->label('Damaged quantity'),
            'excess_quantity' => Property::quantity()->default('0')->nonNegative()->label('Excess quantity found'),
            'report_shortage' => Property::boolean()->default(false)->label('Report the rest as short', 'Leaves the remainder in transit as a shortage discrepancy.'),
            'receiving_condition' => Property::enum(ReceivingCondition::class)->default(ReceivingCondition::GOOD->value)->label('Condition'),
            'destination_storage_location_id' => Property::uuid()->nullable()->references(StorageLocation::class, 'status', ['active'])->label('Put into'),
            'scanned_lot_number' => Property::string(80)->nullable()->trim()->label('Scanned lot'),
            'scanned_serial_number' => Property::string(120)->nullable()->trim()->label('Scanned serial'),
            'discrepancy_reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Discrepancy reason'),
            'notes' => Property::text(1000)->nullable()->label('Notes'),
        ];
    }
}
