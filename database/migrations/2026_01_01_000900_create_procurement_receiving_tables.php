<?php

use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Procurement\Enums\AsnStatus;
use App\Domains\Procurement\Enums\PurchaseOrderStatus;
use App\Domains\Procurement\Enums\PurchaseRequestStatus;
use App\Domains\Procurement\Enums\SupplierReturnStatus;
use App\Domains\Receiving\Enums\GoodsReceiptStatus;
use App\Domains\Receiving\Enums\PutawayTaskStatus;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------------- Purchase requests ----------------
        Schema::create('purchase_requests', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: PurchaseRequestStatus::DRAFT->value);
            $t->uuid('requester_id')->nullable();
            $t->date('required_by')->nullable();
            $t->text('justification')->nullable();
            $t->decimal('estimated_total', 20, 4)->default(0);
            $t->string('currency', 3)->nullable();
            $t->uuid('rejected_by')->nullable();
            $t->dateTime('rejected_at', 6)->nullable();
            $t->text('rejection_reason')->nullable();
        });
        Ddl::enumCheck('purchase_requests', 'status', PurchaseRequestStatus::class);
        Ddl::documentChecks('purchase_requests');

        Schema::create('purchase_request_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('purchase_request_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->decimal('quantity', 20, 6);
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('ordered_base_quantity', 20, 6)->default(0);
            $t->decimal('estimated_unit_cost', 20, 8)->nullable();
            $t->uuid('suggested_supplier_id')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('purchase_request_id')->references('id')->on('purchase_requests')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->unique(['purchase_request_id', 'line_no']);
        });
        Ddl::check('purchase_request_lines', 'chk_prl_qty', 'quantity > 0 AND base_quantity > 0 AND ordered_base_quantity >= 0');

        // ---------------- Purchase orders ----------------
        Schema::create('purchase_orders', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: PurchaseOrderStatus::DRAFT->value);
            $t->uuid('supplier_id');
            $t->uuid('purchase_request_id')->nullable();
            $t->date('expected_delivery_date')->nullable();
            $t->string('currency', 3);
            $t->decimal('exchange_rate', 20, 10)->default(1);
            $t->uuid('incoterm_lookup_value_id')->nullable();
            $t->uuid('payment_terms_lookup_value_id')->nullable();
            $t->string('supplier_reference', 80)->nullable();
            $t->text('delivery_notes')->nullable();
            $t->decimal('subtotal', 20, 4)->default(0);
            $t->decimal('charges_total', 20, 4)->default(0);
            $t->decimal('total', 20, 4)->default(0);
            $t->integer('revision')->default(0);
            $t->uuid('sent_by')->nullable();
            $t->dateTime('sent_at', 6)->nullable();
            $t->uuid('closed_by')->nullable();
            $t->dateTime('closed_at', 6)->nullable();
            $t->uuid('rejected_by')->nullable();
            $t->dateTime('rejected_at', 6)->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreign('supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('purchase_request_id')->references('id')->on('purchase_requests')->restrictOnDelete();
            $t->index(['status', 'expected_delivery_date']);
            $t->index(['supplier_id', 'status']);
        });
        Ddl::enumCheck('purchase_orders', 'status', PurchaseOrderStatus::class);
        Ddl::documentChecks('purchase_orders');

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('purchase_order_id');
            $t->integer('line_no');
            $t->uuid('purchase_request_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->decimal('quantity', 20, 6);
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('unit_price', 20, 8);
            $t->decimal('discount_percent', 9, 6)->default(0);
            $t->decimal('tax_percent', 9, 6)->default(0);
            $t->decimal('line_total', 20, 4);
            $t->decimal('received_base_quantity', 20, 6)->default(0);
            $t->decimal('rejected_base_quantity', 20, 6)->default(0);
            $t->decimal('returned_base_quantity', 20, 6)->default(0);
            $t->decimal('cancelled_base_quantity', 20, 6)->default(0);
            $t->date('expected_date')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $t->foreign('purchase_request_line_id')->references('id')->on('purchase_request_lines')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->unique(['purchase_order_id', 'line_no']);
            $t->index('item_id');
        });
        Ddl::check('purchase_order_lines', 'chk_pol_qty', 'quantity > 0 AND base_quantity > 0 AND unit_price >= 0 AND received_base_quantity >= 0 AND cancelled_base_quantity >= 0');
        Ddl::check('purchase_order_lines', 'chk_pol_pct', 'discount_percent BETWEEN 0 AND 100 AND tax_percent BETWEEN 0 AND 100');

        Schema::create('purchase_order_revisions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('purchase_order_id');
            $t->integer('revision');
            $t->json('snapshot');
            $t->text('reason')->nullable();
            $t->uuid('created_by')->nullable();
            $t->dateTime('created_at', 6);
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $t->unique(['purchase_order_id', 'revision']);
        });
        Ddl::appendOnly('purchase_order_revisions');

        // ---------------- Advance shipment notices ----------------
        Schema::create('advance_shipment_notices', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('purchase_order_id');
            $t->string('supplier_reference', 80)->nullable();
            $t->date('expected_arrival')->nullable();
            Ddl::enumColumn($t, 'status', default: AsnStatus::OPEN->value);
            $t->json('lines');
            $t->uuid('created_by')->nullable();
            Ddl::timestamps($t);
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
        });
        Ddl::enumCheck('advance_shipment_notices', 'status', AsnStatus::class);

        // ---------------- Goods receipts ----------------
        Schema::create('goods_receipts', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: GoodsReceiptStatus::DRAFT->value);
            $t->uuid('purchase_order_id')->nullable();
            $t->uuid('supplier_id');
            $t->string('supplier_delivery_reference', 80)->nullable();
            $t->dateTime('received_at', 6)->nullable();
            $t->uuid('received_by')->nullable();
            $t->string('carrier_reference', 120)->nullable();
            $t->string('vehicle_reference', 60)->nullable();
            $t->uuid('advance_shipment_notice_id')->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->boolean('is_direct')->default(false);
            $t->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $t->foreign('supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['status', 'warehouse_id']);
        });
        Ddl::enumCheck('goods_receipts', 'status', GoodsReceiptStatus::class);
        Ddl::documentChecks('goods_receipts');
        Ddl::check('goods_receipts', 'chk_gr_po_or_direct', 'purchase_order_id IS NOT NULL OR is_direct');

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('goods_receipt_id');
            $t->integer('line_no');
            $t->uuid('purchase_order_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->decimal('expected_quantity', 20, 6)->nullable();
            $t->decimal('received_quantity', 20, 6);
            $t->decimal('accepted_quantity', 20, 6);
            $t->decimal('rejected_quantity', 20, 6)->default(0);
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('accepted_base_quantity', 20, 6)->default(0);
            $t->decimal('rejected_base_quantity', 20, 6)->default(0);
            $t->string('lot_number', 80)->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->date('manufacture_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->json('serial_numbers')->nullable();
            $t->uuid('destination_storage_location_id')->nullable();
            Ddl::enumColumn($t, 'receiving_condition', default: ReceivingCondition::GOOD->value);
            Ddl::enumColumn($t, 'resulting_condition', nullable: true);
            $t->uuid('rejection_reason_lookup_value_id')->nullable();
            $t->text('inspection_notes')->nullable();
            $t->decimal('unit_cost', 20, 8)->default(0);
            $t->uuid('quality_check_id')->nullable();
            Ddl::timestamps($t);
            $t->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->restrictOnDelete();
            $t->foreign('purchase_order_line_id')->references('id')->on('purchase_order_lines')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->foreign('destination_storage_location_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->unique(['goods_receipt_id', 'line_no']);
            $t->index('purchase_order_line_id');
        });
        Ddl::enumCheck('goods_receipt_lines', 'receiving_condition', ReceivingCondition::class);
        Ddl::enumCheck('goods_receipt_lines', 'resulting_condition', StockCondition::class);
        Ddl::check('goods_receipt_lines', 'chk_grl_qty', 'received_quantity > 0 AND accepted_quantity >= 0 AND rejected_quantity >= 0 AND accepted_quantity + rejected_quantity = received_quantity');

        // ---------------- Put-away ----------------
        Schema::create('putaway_tasks', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('warehouse_id');
            $t->uuid('goods_receipt_id')->nullable();
            $t->uuid('transfer_receipt_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('from_storage_location_id');
            $t->uuid('suggested_storage_location_id')->nullable();
            $t->uuid('to_storage_location_id')->nullable();
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
            $t->decimal('quantity', 20, 6);
            Ddl::enumColumn($t, 'status', default: PutawayTaskStatus::OPEN->value);
            $t->uuid('assigned_to')->nullable();
            $t->uuid('posted_by')->nullable();
            $t->dateTime('posted_at', 6)->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('putaway_tasks', 'status', PutawayTaskStatus::class);
        Ddl::enumCheck('putaway_tasks', 'stock_condition', StockCondition::class);
        Ddl::check('putaway_tasks', 'chk_putaway_qty', 'quantity > 0');

        // ---------------- Supplier returns ----------------
        Schema::create('supplier_returns', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: SupplierReturnStatus::DRAFT->value);
            $t->uuid('supplier_id');
            $t->uuid('goods_receipt_id')->nullable();
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->foreign('supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->foreign('goods_receipt_id')->references('id')->on('goods_receipts')->restrictOnDelete();
        });
        Ddl::enumCheck('supplier_returns', 'status', SupplierReturnStatus::class);
        Ddl::documentChecks('supplier_returns');

        Schema::create('supplier_return_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('supplier_return_id');
            $t->integer('line_no');
            $t->uuid('goods_receipt_line_id')->nullable();
            $t->uuid('purchase_order_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('storage_location_id');
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
            $t->decimal('quantity', 20, 6);
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8)->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('supplier_return_id')->references('id')->on('supplier_returns')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['supplier_return_id', 'line_no']);
        });
        Ddl::enumCheck('supplier_return_lines', 'stock_condition', StockCondition::class);
        Ddl::check('supplier_return_lines', 'chk_srl_qty', 'quantity > 0 AND base_quantity > 0');

        DB::statement('CREATE INDEX goods_receipt_lines_lot ON goods_receipt_lines (inventory_lot_id)');
    }

    public function down(): void
    {
        foreach (['supplier_return_lines', 'supplier_returns', 'putaway_tasks', 'goods_receipt_lines', 'goods_receipts', 'advance_shipment_notices',
            'purchase_order_revisions', 'purchase_order_lines', 'purchase_orders', 'purchase_request_lines', 'purchase_requests'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
