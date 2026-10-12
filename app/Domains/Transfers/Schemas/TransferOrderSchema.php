<?php

declare(strict_types=1);

namespace App\Domains\Transfers\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class TransferOrderSchema extends TableSchema
{
    public static function table(): string
    {
        return 'transfer_orders';
    }

    public static function stateEnum(): ?string
    {
        return TransferStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(TransferStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Transfer number'),
            'source_warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('From warehouse'),
            'destination_warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active'])->immutableAfter(...$locked)->filterable()->label('To warehouse'),
            'document_date' => Property::date()->nullable()->sortable()->label('Requested on'),
            'required_by' => Property::date()->nullable()->after('document_date')->label('Required by'),
            'reason' => Property::text(2000)->nullable()->label('Reason'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->filterable()->label('Priority'),
            'carrier_partner_id' => Property::uuid()->nullable()->references(Partner::class)->label('Carrier'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(TransferStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(TransferOrderLineSchema::class)->requiredOnCreate()->min(1)->max(500)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
