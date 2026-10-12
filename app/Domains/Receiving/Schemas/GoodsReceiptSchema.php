<?php

declare(strict_types=1);

namespace App\Domains\Receiving\Schemas;

use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Models\Partner;
use App\Domains\Procurement\Models\AdvanceShipmentNotice;
use App\Domains\Procurement\Models\PurchaseOrder;
use App\Domains\Receiving\Enums\GoodsReceiptStatus;
use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/**
 * Goods receipt note payload (Chapter 08 §8.3). Warehouse and supplier are taken from the
 * purchase order; they are only accepted for direct (PO-less) receipts.
 */
final class GoodsReceiptSchema extends TableSchema
{
    public static function table(): string
    {
        return 'goods_receipts';
    }

    public static function stateEnum(): ?string
    {
        return GoodsReceiptStatus::class;
    }

    protected static function define(): array
    {
        $locked = ['posted', 'cancelled'];

        return [
            'document_number' => Property::string(40)->serverControlled()->searchable()->sortable()->label('GRN number'),
            'purchase_order_id' => Property::uuid()->nullable()->references(PurchaseOrder::class)->immutableAfter(...$locked)->filterable()->label('Purchase order'),
            'warehouse_id' => Property::uuid()->nullable()->references(Warehouse::class, 'status', ['active', 'closing'])->immutableAfter(...$locked)->filterable()->label('Warehouse', 'Direct receipts only.'),
            'supplier_id' => Property::uuid()->nullable()->references(Partner::class)->immutableAfter(...$locked)->filterable()->label('Supplier', 'Direct receipts only.'),
            'advance_shipment_notice_id' => Property::uuid()->nullable()->references(AdvanceShipmentNotice::class)->label('ASN'),
            'document_date' => Property::date()->nullable()->sortable()->label('Receipt date'),
            'received_at' => Property::datetime()->nullable()->label('Received at'),
            'supplier_delivery_reference' => Property::string(80)->nullable()->trim()->searchable()->label('Delivery note'),
            'carrier_reference' => Property::string(120)->nullable()->trim()->label('Carrier'),
            'vehicle_reference' => Property::string(60)->nullable()->trim()->label('Vehicle'),
            'reference' => Property::string(120)->nullable()->trim()->searchable()->label('Reference'),
            'notes' => Property::text(4000)->nullable()->label('Notes'),
            'status' => Property::enum(GoodsReceiptStatus::class)->serverControlled()->filterable()->label('Status'),
            'is_direct' => Property::boolean()->serverControlled()->label('Direct receipt'),
            'lines' => Property::arrayOf(GoodsReceiptLineSchema::class)->requiredOnCreate()->min(1)->max(1000)->immutableAfter(...$locked)->virtual()->label('Lines'),
            'version' => Property::integer()->nullable()->min(1)->virtual()->label('Expected version'),
        ];
    }
}
