<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Models\LookupValue;
use App\Domains\Procurement\Enums\SupplierReturnStatus;
use App\Domains\Receiving\Models\GoodsReceipt;
use App\Support\Enums\Priority;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Return to supplier (Chapter 08 §8.2): stock leaves to the supplier location at the original receipt cost. */
final class SupplierReturnSchema extends TableSchema
{
    public static function table(): string
    {
        return 'supplier_returns';
    }

    public static function stateEnum(): ?string
    {
        return SupplierReturnStatus::class;
    }

    protected static function define(): array
    {
        $locked = array_values(array_diff(SupplierReturnStatus::values(), ['draft']));

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('Return number'),
            'warehouse_id' => Property::uuid()->requiredOnCreate()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse'),
            'supplier_id' => Property::uuid()->requiredOnCreate()->references(Partner::class)->immutableAfter(...$locked)->filterable()->label('Supplier'),
            'goods_receipt_id' => Property::uuid()->nullable()->references(GoodsReceipt::class)->immutableAfter(...$locked)->label('From receipt'),
            'reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Reason code'),
            'reason' => Property::text(2000)->requiredOnCreate()->trim()->label('Reason'),
            'document_date' => Property::date()->nullable()->sortable()->label('Date'),
            'priority' => Property::enum(Priority::class)->default(Priority::NORMAL->value)->label('Priority'),
            'reference' => Property::string(120)->nullable()->trim()->label('Supplier RMA / reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(SupplierReturnStatus::class)->serverControlled()->filterable()->label('Status'),
            'lines' => Property::arrayOf(SupplierReturnLineSchema::class)->requiredOnCreate()->min(1)->max(1000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
