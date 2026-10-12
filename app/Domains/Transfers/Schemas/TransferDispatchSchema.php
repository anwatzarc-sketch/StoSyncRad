<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Domains\Partners\Models\Partner;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** POST /transfers/{id}/dispatches — one dispatch posting event (Chapter 08 §8.4 step 4). */
final class TransferDispatchSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_dispatches';
    }

    protected static function define(): array
    {
        return [
            'lines' => Property::arrayOf(TransferDispatchLineSchema::class)->required()->min(1)->max(2000)->virtual()->label('Dispatched lines'),
            'carrier_partner_id' => Property::uuid()->nullable()->references(Partner::class)->label('Carrier'),
            'tracking_reference' => Property::string(120)->nullable()->trim()->label('Tracking reference'),
            'seal_reference' => Property::string(60)->nullable()->trim()->label('Seal'),
            'vehicle_reference' => Property::string(60)->nullable()->trim()->label('Vehicle'),
            'expected_arrival' => Property::date()->nullable()->label('Expected arrival'),
            'notes' => Property::text(2000)->nullable()->label('Notes'),
            'override_reason' => Property::text(2000)->nullable()->virtual()->label('SoD override reason'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
