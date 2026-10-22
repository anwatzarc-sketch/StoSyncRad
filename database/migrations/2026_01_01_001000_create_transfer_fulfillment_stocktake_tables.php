<?php

use App\Domains\Fulfillment\Enums\CustomerReturnStatus;
use App\Domains\Fulfillment\Enums\InternalReturnStatus;
use App\Domains\Fulfillment\Enums\PickLineStatus;
use App\Domains\Fulfillment\Enums\PickListStatus;
use App\Domains\Fulfillment\Enums\PickSourceType;
use App\Domains\Fulfillment\Enums\SalesChannel;
use App\Domains\Fulfillment\Enums\SalesOrderStatus;
use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Enums\StockIssueStatus;
use App\Domains\Fulfillment\Enums\StockRequestStatus;
use App\Domains\Inventory\Enums\ReversalStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Planning\Enums\AllocationPlanStatus;
use App\Domains\Planning\Enums\AllocationPolicy;
use App\Domains\Planning\Enums\ForecastMethod;
use App\Domains\Receiving\Enums\ReceivingCondition;
use App\Domains\Stocktaking\Enums\AdjustmentType;
use App\Domains\Stocktaking\Enums\CountLineStatus;
use App\Domains\Stocktaking\Enums\StockAdjustmentStatus;
use App\Domains\Stocktaking\Enums\StocktakeFreezePolicy;
use App\Domains\Stocktaking\Enums\StocktakeStatus;
use App\Domains\Stocktaking\Enums\StocktakeType;
use App\Domains\Transfers\Enums\DiscrepancyResolution;
use App\Domains\Transfers\Enums\DiscrepancyStatus;
use App\Domains\Transfers\Enums\DiscrepancyType;
use App\Domains\Transfers\Enums\TransferEventStatus;
use App\Domains\Transfers\Enums\TransferStatus;
use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->planning();
        $this->transfers();
        $this->fulfillment();
        $this->stocktaking();
    }

    private function planning(): void
    {
        Schema::create('planning_forecast_inputs', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('warehouse_id')->nullable();
            Ddl::enumColumn($t, 'method', default: ForecastMethod::MOVING_AVERAGE->value);
            $t->integer('horizon_days')->default(30);
            $t->integer('window_days')->default(90);
            $t->smallInteger('seasonality_months')->nullable();
            $t->decimal('manual_daily_demand', 20, 6)->nullable();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->text('reason')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
        Ddl::enumCheck('planning_forecast_inputs', 'method', ForecastMethod::class);

        Schema::create('allocation_plans', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);   // warehouse_id = central source warehouse
            Ddl::enumColumn($t, 'status', default: AllocationPlanStatus::DRAFT->value);
            Ddl::enumColumn($t, 'policy', default: AllocationPolicy::MANUAL->value);
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->uuid('released_by')->nullable();
            $t->dateTime('released_at', 6)->nullable();
        });
        Ddl::enumCheck('allocation_plans', 'status', AllocationPlanStatus::class);
        Ddl::enumCheck('allocation_plans', 'policy', AllocationPolicy::class);
        Ddl::documentChecks('allocation_plans');

        Schema::create('allocation_plan_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('allocation_plan_id');
            $t->integer('line_no');
            $t->uuid('destination_warehouse_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->decimal('suggested_quantity', 20, 6)->default(0);
            $t->decimal('quantity', 20, 6);
            $t->decimal('cap_quantity', 20, 6)->nullable();
            $t->integer('priority')->default(100);
            $t->uuid('transfer_order_id')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('allocation_plan_id')->references('id')->on('allocation_plans')->restrictOnDelete();
            $t->foreign('destination_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['allocation_plan_id', 'line_no']);
        });
        Ddl::check('allocation_plan_lines', 'chk_apl_qty', 'quantity >= 0 AND suggested_quantity >= 0');
    }

    private function transfers(): void
    {
        Schema::create('transfer_orders', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t, warehouse: false);
            Ddl::enumColumn($t, 'status', default: TransferStatus::DRAFT->value);
            $t->uuid('source_warehouse_id');
            $t->uuid('destination_warehouse_id');
            $t->uuid('requested_by')->nullable();
            $t->date('required_by')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('allocation_plan_id')->nullable();
            $t->uuid('carrier_partner_id')->nullable();
            $t->uuid('closed_by')->nullable();
            $t->dateTime('closed_at', 6)->nullable();
            $t->foreign('source_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('destination_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('allocation_plan_id')->references('id')->on('allocation_plans')->restrictOnDelete();
            $t->index(['source_warehouse_id', 'status']);
            $t->index(['destination_warehouse_id', 'status']);
        });
        Ddl::enumCheck('transfer_orders', 'status', TransferStatus::class);
        Ddl::documentChecks('transfer_orders');

        Schema::create('transfer_order_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('transfer_order_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();   // optional lot pin
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('requested_quantity', 20, 6);
            $t->decimal('requested_base_quantity', 20, 6);
            $t->decimal('approved_base_quantity', 20, 6)->default(0);
            $t->decimal('dispatched_base_quantity', 20, 6)->default(0);
            $t->decimal('received_base_quantity', 20, 6)->default(0);
            $t->decimal('resolved_base_quantity', 20, 6)->default(0);
            $t->decimal('cancelled_base_quantity', 20, 6)->default(0);
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('transfer_order_id')->references('id')->on('transfer_orders')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['transfer_order_id', 'line_no']);
        });
        Ddl::check('transfer_order_lines', 'chk_tol_qty', 'requested_quantity > 0 AND approved_base_quantity >= 0 AND dispatched_base_quantity >= 0 AND received_base_quantity >= 0 AND resolved_base_quantity >= 0');
        // Conservation: what was received or resolved can never exceed what was dispatched.
        Ddl::check('transfer_order_lines', 'chk_tol_conservation', 'received_base_quantity + resolved_base_quantity <= dispatched_base_quantity');
        Ddl::check('transfer_order_lines', 'chk_tol_dispatch_le_approved', '(approved_base_quantity = 0 AND dispatched_base_quantity = 0) OR dispatched_base_quantity + cancelled_base_quantity <= approved_base_quantity');

        Schema::create('transfer_dispatches', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('transfer_order_id');
            Ddl::enumColumn($t, 'status', default: TransferEventStatus::POSTED->value);
            $t->uuid('dispatched_by')->nullable();
            $t->dateTime('dispatched_at', 6);
            $t->uuid('carrier_partner_id')->nullable();
            $t->string('tracking_reference', 120)->nullable();
            $t->string('seal_reference', 60)->nullable();
            $t->string('vehicle_reference', 60)->nullable();
            $t->date('expected_arrival')->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('transfer_order_id')->references('id')->on('transfer_orders')->restrictOnDelete();
            $t->index('transfer_order_id');
        });
        Ddl::enumCheck('transfer_dispatches', 'status', TransferEventStatus::class);

        Schema::create('transfer_dispatch_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('transfer_dispatch_id');
            $t->uuid('transfer_order_line_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('source_storage_location_id');
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('received_base_quantity', 20, 6)->default(0);
            $t->decimal('resolved_base_quantity', 20, 6)->default(0);
            $t->decimal('unit_cost_snapshot', 20, 8)->default(0);
            Ddl::timestamps($t);
            $t->foreign('transfer_dispatch_id')->references('id')->on('transfer_dispatches')->restrictOnDelete();
            $t->foreign('transfer_order_line_id')->references('id')->on('transfer_order_lines')->restrictOnDelete();
            $t->index('transfer_order_line_id');
        });
        Ddl::check('transfer_dispatch_lines', 'chk_tdl_qty', 'base_quantity > 0 AND received_base_quantity >= 0 AND resolved_base_quantity >= 0 AND received_base_quantity + resolved_base_quantity <= base_quantity');

        Schema::create('transfer_receipts', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('transfer_order_id');
            Ddl::enumColumn($t, 'status', default: TransferEventStatus::POSTED->value);
            $t->uuid('received_by')->nullable();
            $t->dateTime('received_at', 6);
            $t->uuid('stock_movement_id')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('transfer_order_id')->references('id')->on('transfer_orders')->restrictOnDelete();
        });
        Ddl::enumCheck('transfer_receipts', 'status', TransferEventStatus::class);

        Schema::create('transfer_receipt_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('transfer_receipt_id');
            $t->uuid('transfer_dispatch_line_id');
            $t->decimal('accepted_base_quantity', 20, 6)->default(0);
            $t->decimal('damaged_base_quantity', 20, 6)->default(0);
            $t->decimal('shortage_base_quantity', 20, 6)->default(0);
            $t->decimal('excess_base_quantity', 20, 6)->default(0);
            Ddl::enumColumn($t, 'receiving_condition', default: ReceivingCondition::GOOD->value);
            $t->uuid('destination_storage_location_id');
            $t->string('scanned_lot_number', 80)->nullable();
            $t->string('scanned_serial_number', 120)->nullable();
            $t->uuid('discrepancy_reason_lookup_value_id')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('transfer_receipt_id')->references('id')->on('transfer_receipts')->restrictOnDelete();
            $t->foreign('transfer_dispatch_line_id')->references('id')->on('transfer_dispatch_lines')->restrictOnDelete();
        });
        Ddl::enumCheck('transfer_receipt_lines', 'receiving_condition', ReceivingCondition::class);
        Ddl::check('transfer_receipt_lines', 'chk_trl_qty', 'accepted_base_quantity >= 0 AND damaged_base_quantity >= 0 AND shortage_base_quantity >= 0 AND excess_base_quantity >= 0');

        Schema::create('transfer_discrepancies', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('transfer_order_id');
            $t->uuid('transfer_dispatch_line_id')->nullable();
            $t->uuid('transfer_receipt_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            Ddl::enumColumn($t, 'discrepancy_type');
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('resolved_base_quantity', 20, 6)->default(0);
            Ddl::enumColumn($t, 'status', default: DiscrepancyStatus::OPEN->value);
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('reported_by')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('transfer_order_id')->references('id')->on('transfer_orders')->restrictOnDelete();
            $t->index(['transfer_order_id', 'status']);
        });
        Ddl::enumCheck('transfer_discrepancies', 'discrepancy_type', DiscrepancyType::class);
        Ddl::enumCheck('transfer_discrepancies', 'status', DiscrepancyStatus::class);
        Ddl::check('transfer_discrepancies', 'chk_td_qty', 'base_quantity >= 0 AND resolved_base_quantity >= 0 AND resolved_base_quantity <= base_quantity');

        Schema::create('transfer_discrepancy_resolutions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('transfer_discrepancy_id');
            Ddl::enumColumn($t, 'resolution');
            $t->decimal('base_quantity', 20, 6);
            $t->uuid('stock_movement_id')->nullable();
            $t->uuid('return_transfer_order_id')->nullable();
            $t->text('reason');
            $t->uuid('resolved_by');
            $t->dateTime('resolved_at', 6);
            Ddl::timestamps($t);
            $t->foreign('transfer_discrepancy_id')->references('id')->on('transfer_discrepancies')->restrictOnDelete();
        });
        Ddl::enumCheck('transfer_discrepancy_resolutions', 'resolution', DiscrepancyResolution::class);
        Ddl::appendOnly('transfer_discrepancy_resolutions');
    }

    private function fulfillment(): void
    {
        Schema::create('stock_requests', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: StockRequestStatus::DRAFT->value);
            $t->uuid('requester_id')->nullable();
            $t->uuid('recipient_partner_id')->nullable();
            $t->uuid('department_lookup_value_id')->nullable();
            $t->string('cost_centre', 60)->nullable();
            $t->date('required_by')->nullable();
            $t->text('purpose')->nullable();
            $t->uuid('rejected_by')->nullable();
            $t->dateTime('rejected_at', 6)->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreign('recipient_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['warehouse_id', 'status']);
            $t->index('requester_id');
        });
        Ddl::enumCheck('stock_requests', 'status', StockRequestStatus::class);
        Ddl::documentChecks('stock_requests');

        Schema::create('stock_request_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('stock_request_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('requested_quantity', 20, 6);
            $t->decimal('requested_base_quantity', 20, 6);
            $t->decimal('approved_base_quantity', 20, 6)->default(0);
            $t->decimal('fulfilled_base_quantity', 20, 6)->default(0);
            $t->decimal('returned_base_quantity', 20, 6)->default(0);
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('stock_request_id')->references('id')->on('stock_requests')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['stock_request_id', 'line_no']);
        });
        Ddl::check('stock_request_lines', 'chk_srql_qty', 'requested_quantity > 0 AND approved_base_quantity >= 0 AND fulfilled_base_quantity >= 0 AND (approved_base_quantity = 0 OR fulfilled_base_quantity <= approved_base_quantity)');

        Schema::create('sales_orders', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: SalesOrderStatus::DRAFT->value);
            Ddl::enumColumn($t, 'channel', default: SalesChannel::RETAIL_COUNTER->value);
            $t->uuid('customer_partner_id');
            $t->string('external_source', 60)->nullable();
            $t->string('external_order_id', 120)->nullable();
            $t->json('shipping_address')->nullable();
            $t->string('currency', 3)->nullable();
            $t->decimal('total', 20, 4)->default(0);
            $t->string('payment_reference', 120)->nullable();
            $t->uuid('confirmed_by')->nullable();
            $t->dateTime('confirmed_at', 6)->nullable();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->unique(['external_source', 'external_order_id']);
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('sales_orders', 'status', SalesOrderStatus::class);
        Ddl::enumCheck('sales_orders', 'channel', SalesChannel::class);
        Ddl::documentChecks('sales_orders');

        Schema::create('sales_order_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('sales_order_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('quantity', 20, 6);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('delivered_base_quantity', 20, 6)->default(0);
            $t->decimal('returned_base_quantity', 20, 6)->default(0);
            $t->decimal('unit_price', 20, 4)->nullable();
            $t->decimal('line_total', 20, 4)->default(0);
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['sales_order_id', 'line_no']);
        });
        Ddl::check('sales_order_lines', 'chk_sol_qty', 'quantity > 0 AND delivered_base_quantity >= 0 AND delivered_base_quantity <= base_quantity');

        Schema::create('pick_waves', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('warehouse_id');
            Ddl::enumColumn($t, 'status', default: PickListStatus::OPEN->value);
            $t->uuid('assigned_to')->nullable();
            $t->uuid('created_by')->nullable();
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
        Ddl::enumCheck('pick_waves', 'status', PickListStatus::class);

        Schema::create('pick_lists', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('warehouse_id');
            $t->uuid('pick_wave_id')->nullable();
            Ddl::enumColumn($t, 'source_type');
            $t->uuid('source_id');
            Ddl::enumColumn($t, 'status', default: PickListStatus::OPEN->value);
            $t->uuid('assigned_to')->nullable();
            $t->uuid('completed_by')->nullable();
            $t->dateTime('completed_at', 6)->nullable();
            $t->uuid('created_by')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('pick_wave_id')->references('id')->on('pick_waves')->restrictOnDelete();
            $t->index(['source_type', 'source_id']);
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('pick_lists', 'source_type', PickSourceType::class);
        Ddl::enumCheck('pick_lists', 'status', PickListStatus::class);

        Schema::create('pick_list_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('pick_list_id');
            $t->integer('line_no');
            $t->uuid('source_line_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('stock_reservation_id')->nullable();
            $t->decimal('suggested_base_quantity', 20, 6);
            $t->decimal('picked_base_quantity', 20, 6)->default(0);
            Ddl::enumColumn($t, 'status', default: PickLineStatus::PENDING->value);
            $t->text('short_pick_reason')->nullable();
            $t->integer('sort_order')->default(0);
            Ddl::timestamps($t);
            $t->foreign('pick_list_id')->references('id')->on('pick_lists')->restrictOnDelete();
            $t->unique(['pick_list_id', 'line_no']);
        });
        Ddl::enumCheck('pick_list_lines', 'status', PickLineStatus::class);
        Ddl::check('pick_list_lines', 'chk_pll_qty', 'suggested_base_quantity >= 0 AND picked_base_quantity >= 0');

        Schema::create('stock_issues', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: StockIssueStatus::DRAFT->value);
            $t->uuid('stock_request_id')->nullable();
            $t->uuid('sales_order_id')->nullable();
            $t->uuid('pick_list_id')->nullable();
            $t->uuid('recipient_partner_id')->nullable();
            $t->string('recipient_name', 160)->nullable();
            $t->boolean('is_direct')->default(false);
            $t->boolean('is_production_issue')->default(false);
            $t->uuid('stock_movement_id')->nullable();
            $t->foreign('stock_request_id')->references('id')->on('stock_requests')->restrictOnDelete();
            $t->foreign('recipient_partner_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('stock_issues', 'status', StockIssueStatus::class);
        Ddl::documentChecks('stock_issues');

        Schema::create('stock_issue_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('stock_issue_id');
            $t->integer('line_no');
            $t->uuid('stock_request_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('uom_id');
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('quantity', 20, 6);
            $t->decimal('base_quantity', 20, 6);
            $t->decimal('returned_base_quantity', 20, 6)->default(0);
            $t->decimal('unit_cost_snapshot', 20, 8)->nullable();
            $t->text('short_pick_reason')->nullable();
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('stock_issue_id')->references('id')->on('stock_issues')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['stock_issue_id', 'line_no']);
        });
        Ddl::check('stock_issue_lines', 'chk_sil_qty', 'quantity > 0 AND base_quantity > 0 AND returned_base_quantity >= 0 AND returned_base_quantity <= base_quantity');

        Schema::create('internal_returns', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: InternalReturnStatus::DRAFT->value);
            $t->uuid('stock_issue_id');
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->foreign('stock_issue_id')->references('id')->on('stock_issues')->restrictOnDelete();
        });
        Ddl::enumCheck('internal_returns', 'status', InternalReturnStatus::class);
        Ddl::documentChecks('internal_returns');

        Schema::create('internal_return_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('internal_return_id');
            $t->integer('line_no');
            $t->uuid('stock_issue_line_id');
            $t->uuid('item_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->decimal('base_quantity', 20, 6);
            Ddl::enumColumn($t, 'resulting_condition', default: StockCondition::RETURNED_PENDING_INSPECTION->value);
            $t->uuid('destination_storage_location_id')->nullable();
            $t->text('inspection_notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('internal_return_id')->references('id')->on('internal_returns')->restrictOnDelete();
            $t->foreign('stock_issue_line_id')->references('id')->on('stock_issue_lines')->restrictOnDelete();
            $t->unique(['internal_return_id', 'line_no']);
        });
        Ddl::enumCheck('internal_return_lines', 'resulting_condition', StockCondition::class);
        Ddl::check('internal_return_lines', 'chk_irl_qty', 'base_quantity > 0');

        Schema::create('shipments', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('warehouse_id');
            $t->uuid('sales_order_id');
            Ddl::enumColumn($t, 'status', default: ShipmentStatus::PACKED->value);
            $t->uuid('carrier_partner_id')->nullable();
            $t->uuid('carrier_service_lookup_value_id')->nullable();
            $t->string('tracking_number', 120)->nullable();
            $t->uuid('packed_by')->nullable();
            $t->dateTime('packed_at', 6)->nullable();
            $t->uuid('handed_over_by')->nullable();
            $t->dateTime('handed_over_at', 6)->nullable();
            $t->dateTime('delivered_at', 6)->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('shipments', 'status', ShipmentStatus::class);

        Schema::create('shipment_packages', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('shipment_id');
            $t->integer('package_no');
            $t->uuid('packaging_type_lookup_value_id')->nullable();
            $t->decimal('weight', 12, 4)->nullable();
            $t->json('dimensions')->nullable();
            $t->string('tracking_number', 120)->nullable();
            Ddl::timestamps($t);
            $t->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
            $t->unique(['shipment_id', 'package_no']);
        });

        Schema::create('shipment_package_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('shipment_package_id');
            $t->uuid('sales_order_line_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('stock_reservation_id')->nullable();
            $t->decimal('base_quantity', 20, 6);
            Ddl::timestamps($t);
            $t->foreign('shipment_package_id')->references('id')->on('shipment_packages')->restrictOnDelete();
            $t->foreign('sales_order_line_id')->references('id')->on('sales_order_lines')->restrictOnDelete();
        });
        Ddl::check('shipment_package_lines', 'chk_spl_qty', 'base_quantity > 0');

        Schema::create('shipment_events', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('shipment_id');
            Ddl::enumColumn($t, 'status');
            $t->string('source', 60);
            $t->json('payload')->nullable();
            $t->dateTime('occurred_at', 6);
            $t->dateTime('created_at', 6);
            $t->foreign('shipment_id')->references('id')->on('shipments')->restrictOnDelete();
        });
        Ddl::enumCheck('shipment_events', 'status', ShipmentStatus::class);
        Ddl::appendOnly('shipment_events');

        Schema::create('customer_returns', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: CustomerReturnStatus::REQUESTED->value);
            $t->uuid('sales_order_id');
            $t->uuid('customer_partner_id');
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason')->nullable();
            $t->string('refund_reference', 120)->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->foreign('sales_order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $t->foreign('customer_partner_id')->references('id')->on('partners')->restrictOnDelete();
        });
        Ddl::enumCheck('customer_returns', 'status', CustomerReturnStatus::class);
        Ddl::documentChecks('customer_returns');

        Schema::create('customer_return_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('customer_return_id');
            $t->integer('line_no');
            $t->uuid('sales_order_line_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->decimal('base_quantity', 20, 6);
            Ddl::enumColumn($t, 'resulting_condition', default: StockCondition::RETURNED_PENDING_INSPECTION->value);
            $t->uuid('destination_storage_location_id')->nullable();
            $t->text('inspection_notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('customer_return_id')->references('id')->on('customer_returns')->restrictOnDelete();
            $t->foreign('sales_order_line_id')->references('id')->on('sales_order_lines')->restrictOnDelete();
            $t->unique(['customer_return_id', 'line_no']);
        });
        Ddl::enumCheck('customer_return_lines', 'resulting_condition', StockCondition::class);
        Ddl::check('customer_return_lines', 'chk_crl_qty', 'base_quantity > 0');
    }

    private function stocktaking(): void
    {
        Schema::create('stocktakes', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: StocktakeStatus::DRAFT->value);
            Ddl::enumColumn($t, 'stocktake_type', default: StocktakeType::CYCLE->value);
            Ddl::enumColumn($t, 'freeze_policy', default: StocktakeFreezePolicy::SNAPSHOT_AND_RECONCILE->value);
            $t->boolean('is_blind')->default(true);
            $t->json('scope');                         // storage_location_ids, category_ids, item_ids, abc_classes
            $t->dateTime('snapshot_at', 6)->nullable();
            $t->date('scheduled_for')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->dateTime('reviewed_at', 6)->nullable();
            $t->uuid('stock_adjustment_id')->nullable();
            $t->text('instructions')->nullable();
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('stocktakes', 'status', StocktakeStatus::class);
        Ddl::enumCheck('stocktakes', 'stocktake_type', StocktakeType::class);
        Ddl::enumCheck('stocktakes', 'freeze_policy', StocktakeFreezePolicy::class);
        Ddl::documentChecks('stocktakes');

        Schema::create('stocktake_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('stocktake_id');
            $t->integer('line_no');
            $t->uuid('inventory_balance_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
            $t->decimal('snapshot_quantity', 20, 6)->default(0);
            $t->decimal('movement_since_snapshot', 20, 6)->default(0);
            $t->decimal('counted_quantity', 20, 6)->nullable();
            $t->decimal('recount_quantity', 20, 6)->nullable();
            $t->decimal('final_quantity', 20, 6)->nullable();
            $t->decimal('variance_quantity', 20, 6)->nullable();
            $t->decimal('variance_value', 20, 4)->nullable();
            $t->decimal('unit_cost_snapshot', 20, 8)->default(0);
            Ddl::enumColumn($t, 'count_status', default: CountLineStatus::PENDING->value);
            $t->uuid('counted_by')->nullable();
            $t->dateTime('counted_at', 6)->nullable();
            $t->uuid('recounted_by')->nullable();
            $t->dateTime('recounted_at', 6)->nullable();
            $t->boolean('is_unexpected')->default(false);
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('stocktake_id')->references('id')->on('stocktakes')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['stocktake_id', 'line_no']);
            $t->index(['stocktake_id', 'count_status']);
        });
        Ddl::enumCheck('stocktake_lines', 'stock_condition', StockCondition::class);
        Ddl::enumCheck('stocktake_lines', 'count_status', CountLineStatus::class);
        Ddl::check('stocktake_lines', 'chk_stl_counts', 'counted_quantity IS NULL OR counted_quantity >= 0');

        Schema::create('stock_adjustments', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: StockAdjustmentStatus::DRAFT->value);
            Ddl::enumColumn($t, 'adjustment_type');
            $t->uuid('source_stocktake_id')->nullable();
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason');
            $t->decimal('total_value', 20, 4)->default(0);
            $t->uuid('rejected_by')->nullable();
            $t->dateTime('rejected_at', 6)->nullable();
            $t->text('rejection_reason')->nullable();
            $t->uuid('stock_movement_id')->nullable();
            $t->foreign('source_stocktake_id')->references('id')->on('stocktakes')->restrictOnDelete();
            $t->index(['warehouse_id', 'status']);
        });
        Ddl::enumCheck('stock_adjustments', 'status', StockAdjustmentStatus::class);
        Ddl::enumCheck('stock_adjustments', 'adjustment_type', AdjustmentType::class);
        Ddl::documentChecks('stock_adjustments');

        Schema::create('stock_adjustment_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('stock_adjustment_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->string('lot_number', 80)->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('serial_number', 120)->nullable();
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
            $t->decimal('quantity', 20, 6);              // signed: + found, − loss
            $t->decimal('unit_cost', 20, 8)->nullable();
            $t->decimal('value', 20, 4)->default(0);
            $t->uuid('stocktake_line_id')->nullable();
            $t->text('reason')->nullable();
            Ddl::timestamps($t);
            $t->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['stock_adjustment_id', 'line_no']);
        });
        Ddl::enumCheck('stock_adjustment_lines', 'stock_condition', StockCondition::class);
        Ddl::check('stock_adjustment_lines', 'chk_sal_qty', 'quantity <> 0');

        Schema::create('stock_movement_reversals', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->uuid('original_movement_id');
            $t->uuid('reversal_movement_id')->nullable();
            $t->uuid('requested_by');
            $t->dateTime('requested_at', 6);
            $t->uuid('approved_by')->nullable();
            $t->dateTime('approved_at', 6)->nullable();
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason');
            $t->json('lines');                         // [{line_id, quantity}]
            Ddl::enumColumn($t, 'status', default: ReversalStatus::POSTED->value);
            Ddl::timestamps($t);
            $t->foreign('original_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
            $t->foreign('reversal_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
        });
        Ddl::enumCheck('stock_movement_reversals', 'status', ReversalStatus::class);
    }

    public function down(): void
    {
        foreach (['stock_movement_reversals', 'stock_adjustment_lines', 'stock_adjustments', 'stocktake_lines', 'stocktakes',
            'customer_return_lines', 'customer_returns', 'shipment_events', 'shipment_package_lines', 'shipment_packages', 'shipments',
            'internal_return_lines', 'internal_returns', 'stock_issue_lines', 'stock_issues', 'pick_list_lines', 'pick_lists', 'pick_waves',
            'sales_order_lines', 'sales_orders', 'stock_request_lines', 'stock_requests', 'transfer_discrepancy_resolutions',
            'transfer_discrepancies', 'transfer_receipt_lines', 'transfer_receipts', 'transfer_dispatch_lines', 'transfer_dispatches',
            'transfer_order_lines', 'transfer_orders', 'allocation_plan_lines', 'allocation_plans', 'planning_forecast_inputs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
