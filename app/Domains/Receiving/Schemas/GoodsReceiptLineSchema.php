<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemVariant;
use App\Domains\Catalog\Models\UnitOfMeasure;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Platform\Models\LookupValue;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Support\Enums\DataClassification;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

final class GoodsReceiptLineSchema extends TableSchema
{
    public static function table(): string
    {
        return 'goods_receipt_lines';
    }

    protected static function define(): array
    {
        return [
            'purchase_order_line_id' => Property::uuid()->nullable()->label('Order line'),
            'item_id' => Property::uuid()->required()->references(Item::class)->label('Item'),
            'item_variant_id' => Property::uuid()->nullable()->references(ItemVariant::class)->label('Variant'),
            'received_quantity' => Property::quantity()->required()->positive()->label('Received quantity'),
            'rejected_quantity' => Property::quantity()->default('0')->nonNegative()->label('Rejected quantity'),
            'uom_id' => Property::uuid()->nullable()->references(UnitOfMeasure::class)->label('Unit'),
            'lot_number' => Property::string(80)->nullable()->trim()->pattern('/^[A-Za-z0-9._\/-]{1,80}$/')->label('Lot / batch'),
            'manufacture_date' => Property::date()->nullable()->label('Manufactured'),
            'expiry_date' => Property::date()->nullable()->after('manufacture_date')->label('Expiry'),
            'serial_numbers' => Property::arrayOf(Property::string(120)->trim()->pattern('/^[A-Za-z0-9._\/-]{1,120}$/'))->nullable()->max(5000)->label('Serial numbers'),
            'destination_storage_location_id' => Property::uuid()->nullable()->references(StorageLocation::class, 'status', ['active'])->label('Put into'),
            'receiving_condition' => Property::enum(ReceivingCondition::class)->default(ReceivingCondition::GOOD->value)->label('Condition'),
            'rejection_reason_lookup_value_id' => Property::uuid()->nullable()->references(LookupValue::class)->label('Rejection reason'),
            'inspection_notes' => Property::text(2000)->nullable()->label('Inspection notes'),
            'unit_cost' => Property::unitCost()->nullable()->nonNegative()->classification(DataClassification::COST_SENSITIVE->value)
                ->label('Unit cost', 'Direct receipts only; order lines take the purchase order price.'),
        ];
    }
}
