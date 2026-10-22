<?php

use App\Domains\Inventory\Enums\ConditionChangeStatus;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\LotStatus;
use App\Domains\Inventory\Enums\MovementStatus;
use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\OpeningBalanceStatus;
use App\Domains\Inventory\Enums\QcOutcome;
use App\Domains\Inventory\Enums\RecallStatus;
use App\Domains\Inventory\Enums\ReconciliationFindingStatus;
use App\Domains\Inventory\Enums\ReconciliationRunStatus;
use App\Domains\Inventory\Enums\RelocationStatus;
use App\Domains\Inventory\Enums\ReservationReferenceType;
use App\Domains\Inventory\Enums\ReservationStatus;
use App\Domains\Inventory\Enums\ScrapStatus;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Enums\ValuationLayerStatus;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SENTINEL = "'00000000-0000-7000-8000-000000000000'";

    public function up(): void
    {
        // ---------------- Lots & serials ----------------
        Schema::create('inventory_lots', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->string('lot_number', 80);
            $t->uuid('supplier_id')->nullable();
            $t->string('supplier_lot_reference', 80)->nullable();
            $t->date('manufacture_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->date('best_before_date')->nullable();
            $t->date('removal_date')->nullable();
            $t->date('alert_date')->nullable();
            $t->string('origin_document_type', 64)->nullable();
            $t->uuid('origin_document_id')->nullable();
            Ddl::enumColumn($t, 'qc_status', default: LotQcStatus::PENDING->value);
            $t->uuid('qc_released_by')->nullable();
            $t->dateTime('qc_released_at', 6)->nullable();
            Ddl::enumColumn($t, 'lock_status', default: LotLockStatus::UNLOCKED->value);
            $t->string('lock_reference_type', 64)->nullable();
            $t->uuid('lock_reference_id')->nullable();
            Ddl::enumColumn($t, 'status', default: LotStatus::ACTIVE->value);
            $t->json('attributes')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_variant_id')->references('id')->on('item_variants')->restrictOnDelete();
            $t->foreign('supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['item_id', 'expiry_date']);
            $t->index('lock_status');
            $t->index('lot_number');
        });
        Ddl::enumCheck('inventory_lots', 'qc_status', LotQcStatus::class);
        Ddl::enumCheck('inventory_lots', 'lock_status', LotLockStatus::class);
        Ddl::enumCheck('inventory_lots', 'status', LotStatus::class);
        Ddl::check('inventory_lots', 'chk_inventory_lots_dates', 'manufacture_date IS NULL OR expiry_date IS NULL OR manufacture_date <= expiry_date');
        // Default uniqueness: (item, lot_number). The item_supplier scope is enforced by the action
        // (the narrower DB index would reject legitimate same-number lots from different suppliers).
        Ddl::uniqueIndex('inventory_lots', 'inventory_lots_item_supplier_number_uq', 'item_id, (COALESCE(supplier_id, '.self::SENTINEL.')), lot_number');

        Schema::create('item_serials', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->string('serial_number', 120);
            $t->uuid('inventory_lot_id')->nullable();
            Ddl::enumColumn($t, 'lifecycle_status', default: SerialStatus::REGISTERED->value);
            $t->uuid('current_inventory_location_id')->nullable();
            Ddl::enumColumn($t, 'current_condition', nullable: true);
            $t->uuid('current_warehouse_id')->nullable();
            $t->date('warranty_until')->nullable();
            $t->json('attributes')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('inventory_lot_id')->references('id')->on('inventory_lots')->restrictOnDelete();
            $t->foreign('current_inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->unique(['item_id', 'serial_number']);
            $t->index('serial_number');
            $t->index('current_inventory_location_id');
        });
        Ddl::enumCheck('item_serials', 'lifecycle_status', SerialStatus::class);
        Ddl::enumCheck('item_serials', 'current_condition', StockCondition::class);

        // ---------------- Ledger (append-only) ----------------
        Schema::create('stock_movements', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('movement_number', 40)->unique();
            Ddl::enumColumn($t, 'movement_type');
            Ddl::enumColumn($t, 'source_document_type');
            $t->uuid('source_document_id');
            $t->string('source_document_number', 40);
            $t->uuid('posting_event_id')->unique();
            $t->uuid('idempotency_key_id')->nullable();
            Ddl::enumColumn($t, 'status', default: MovementStatus::POSTED->value);
            $t->uuid('posting_actor_id')->nullable();
            $t->dateTime('posted_at', 6);
            $t->date('business_date');
            $t->uuid('reversal_of_movement_id')->nullable();
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason_text')->nullable();
            $t->uuid('correlation_id');
            $t->uuid('warehouse_id')->nullable();
            $t->uuid('counterparty_warehouse_id')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->dateTime('created_at', 6);
            $t->dateTime('updated_at', 6)->nullable();
            $t->foreign('reversal_of_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
            $t->index(['source_document_type', 'source_document_id']);
            $t->index('posted_at');
            $t->index(['movement_type', 'posted_at']);
            $t->index(['warehouse_id', 'posted_at']);
        });
        Ddl::enumCheck('stock_movements', 'movement_type', MovementType::class);
        Ddl::enumCheck('stock_movements', 'source_document_type', DocumentType::class);
        Ddl::enumCheck('stock_movements', 'status', MovementStatus::class);
        Ddl::appendOnly('stock_movements');

        Schema::create('stock_movement_lines', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('stock_movement_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('source_inventory_location_id');
            $t->uuid('destination_inventory_location_id');
            Ddl::enumColumn($t, 'source_condition');
            Ddl::enumColumn($t, 'destination_condition');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            $t->decimal('quantity', 20, 6);
            $t->uuid('base_uom_id');
            $t->uuid('document_uom_id')->nullable();
            $t->decimal('document_quantity', 20, 6)->nullable();
            $t->decimal('conversion_factor_snapshot', 20, 10)->default(1);
            $t->decimal('unit_cost_snapshot', 20, 8)->default(0);
            $t->decimal('total_cost_snapshot', 20, 4)->default(0);
            $t->string('currency', 3);
            $t->string('source_line_type', 64)->nullable();
            $t->uuid('source_line_id')->nullable();
            $t->uuid('source_warehouse_id')->nullable();
            $t->uuid('destination_warehouse_id')->nullable();
            $t->uuid('reversed_by_line_id')->nullable();
            $t->decimal('reversed_quantity', 20, 6)->default(0);
            $t->dateTime('created_at', 6);
            $t->dateTime('updated_at', 6)->nullable();
            $t->foreign('stock_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_variant_id')->references('id')->on('item_variants')->restrictOnDelete();
            $t->foreign('source_inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->foreign('destination_inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->foreign('inventory_lot_id')->references('id')->on('inventory_lots')->restrictOnDelete();
            $t->foreign('item_serial_id')->references('id')->on('item_serials')->restrictOnDelete();
            $t->foreign('base_uom_id')->references('id')->on('units_of_measure')->restrictOnDelete();
            $t->unique(['stock_movement_id', 'line_no']);
            $t->index(['item_id', 'created_at']);
            $t->index(['source_inventory_location_id', 'created_at']);
            $t->index(['destination_inventory_location_id', 'created_at']);
            $t->index('inventory_lot_id');
            $t->index('item_serial_id');
            $t->index(['source_line_type', 'source_line_id']);
        });
        Ddl::enumCheck('stock_movement_lines', 'source_condition', StockCondition::class);
        Ddl::enumCheck('stock_movement_lines', 'destination_condition', StockCondition::class);
        Ddl::check('stock_movement_lines', 'chk_sml_positive_qty', 'quantity > 0');
        Ddl::check('stock_movement_lines', 'chk_sml_serial_unit', 'item_serial_id IS NULL OR quantity = 1');
        Ddl::check('stock_movement_lines', 'chk_sml_distinct_ends',
            'source_inventory_location_id <> destination_inventory_location_id OR source_condition <> destination_condition');
        Ddl::check('stock_movement_lines', 'chk_sml_reversed_qty', 'reversed_quantity >= 0 AND reversed_quantity <= quantity');
        Ddl::check('stock_movement_lines', 'chk_sml_cost_non_negative', 'unit_cost_snapshot >= 0');
        Ddl::appendOnly('stock_movement_lines');

        // ---------------- Balance projection ----------------
        Schema::create('inventory_balances', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id');            // sentinel when the item has no variants
            $t->uuid('inventory_location_id');
            $t->uuid('inventory_lot_id');           // sentinel when not lot-tracked
            Ddl::enumColumn($t, 'stock_condition');
            $t->uuid('owner_partner_id');           // sentinel unless consignment is enabled
            $t->uuid('package_id');                 // sentinel unless packages are enabled
            $t->uuid('warehouse_id')->nullable();   // denormalized for scoping
            $t->decimal('quantity_on_hand', 20, 6)->default(0);
            $t->decimal('quantity_reserved', 20, 6)->default(0);
            $t->decimal('unit_cost_projection', 20, 8)->default(0);
            $t->decimal('total_value_projection', 20, 4)->default(0);
            $t->boolean('allow_negative')->default(false);
            $t->dateTime('last_movement_at', 6)->nullable();
            $t->dateTime('last_counted_at', 6)->nullable();
            $t->boolean('is_frozen')->default(false);
            $t->uuid('frozen_by_stocktake_id')->nullable();
            $t->bigInteger('version')->default(0);
            Ddl::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->unique(['item_id', 'item_variant_id', 'inventory_location_id', 'inventory_lot_id', 'stock_condition', 'owner_partner_id', 'package_id'], 'inventory_balances_position_uq');
            $t->index(['inventory_location_id', 'item_id']);
            $t->index(['item_id', 'stock_condition']);
            $t->index(['warehouse_id', 'item_id']);
        });
        Ddl::enumCheck('inventory_balances', 'stock_condition', StockCondition::class);
        Ddl::check('inventory_balances', 'chk_balances_non_negative', 'allow_negative OR quantity_on_hand >= 0');
        Ddl::check('inventory_balances', 'chk_balances_reserved', 'quantity_reserved >= 0 AND (allow_negative OR quantity_reserved <= quantity_on_hand)');
        // MySQL has no partial indexes; these cover the same lookups (non-zero positions, real lots).
        DB::statement('CREATE INDEX inventory_balances_positive ON inventory_balances (item_id, warehouse_id, quantity_on_hand)');
        DB::statement('CREATE INDEX inventory_balances_lot ON inventory_balances (inventory_lot_id)');

        // ---------------- Reservations ----------------
        Schema::create('stock_reservations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'reference_type');
            $t->uuid('reference_id');
            $t->uuid('reference_line_id');
            $t->uuid('inventory_balance_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id');
            $t->uuid('inventory_location_id');
            $t->uuid('inventory_lot_id');
            $t->uuid('item_serial_id')->nullable();
            $t->uuid('warehouse_id')->nullable();
            $t->decimal('quantity_reserved', 20, 6);
            $t->decimal('quantity_consumed', 20, 6)->default(0);
            $t->decimal('quantity_released', 20, 6)->default(0);
            Ddl::enumColumn($t, 'status', default: ReservationStatus::ACTIVE->value);
            $t->dateTime('expires_at', 6)->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('released_by')->nullable();
            $t->dateTime('released_at', 6)->nullable();
            $t->text('release_reason')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
            $t->foreign('inventory_balance_id')->references('id')->on('inventory_balances')->restrictOnDelete();
            $t->foreign('item_serial_id')->references('id')->on('item_serials')->restrictOnDelete();
            $t->index(['reference_type', 'reference_id']);
            $t->index('inventory_balance_id');
        });
        Ddl::enumCheck('stock_reservations', 'reference_type', ReservationReferenceType::class);
        Ddl::enumCheck('stock_reservations', 'status', ReservationStatus::class);
        Ddl::check('stock_reservations', 'chk_reservations_quantities', 'quantity_reserved > 0 AND quantity_consumed >= 0 AND quantity_released >= 0 AND quantity_consumed + quantity_released <= quantity_reserved');
        // Live reservations only: rows in any other status index NULLs, which never collide.
        $live = "status IN ('active','partially_consumed')";
        Ddl::uniqueIndex('stock_reservations', 'stock_reservations_active_uq',
            "(CASE WHEN $live THEN reference_line_id END), (CASE WHEN $live THEN inventory_balance_id END), (CASE WHEN $live THEN COALESCE(item_serial_id, ".self::SENTINEL.') END)');
        Ddl::uniqueExpression('stock_reservations', 'stock_reservations_serial_active_uq', "CASE WHEN item_serial_id IS NOT NULL AND $live THEN item_serial_id END");

        // ---------------- Quality checks ----------------
        Schema::create('quality_checks', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'document_type');
            $t->uuid('document_id');
            $t->uuid('document_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('warehouse_id');
            $t->uuid('qc_checklist_template_id')->nullable();
            $t->json('results');
            Ddl::enumColumn($t, 'outcome');
            $t->decimal('inspected_quantity', 20, 6)->default(0);
            $t->decimal('passed_quantity', 20, 6)->default(0);
            $t->decimal('failed_quantity', 20, 6)->default(0);
            $t->text('notes')->nullable();
            $t->uuid('inspector_id');
            $t->dateTime('inspected_at', 6);
            Ddl::timestamps($t);
            $t->index(['document_type', 'document_id']);
            $t->index('inventory_lot_id');
        });
        Ddl::enumCheck('quality_checks', 'document_type', DocumentType::class);
        Ddl::enumCheck('quality_checks', 'outcome', QcOutcome::class);

        // ---------------- Valuation ----------------
        Schema::create('item_cost_projections', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id');     // sentinel when no variant
            $t->uuid('warehouse_id');        // sentinel for company-level costing
            $t->decimal('quantity', 20, 6)->default(0);
            $t->decimal('total_value', 20, 4)->default(0);
            $t->decimal('average_cost', 20, 8)->default(0);
            $t->bigInteger('version')->default(0);
            Ddl::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['item_id', 'item_variant_id', 'warehouse_id'], 'item_cost_projections_identity_uq');
        });

        Schema::create('valuation_layers', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id');
            $t->uuid('warehouse_id');             // sentinel for company-level costing
            $t->uuid('stock_movement_line_id');
            $t->decimal('original_quantity', 20, 6);
            $t->decimal('remaining_quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8);
            Ddl::enumColumn($t, 'status', default: ValuationLayerStatus::OPEN->value);
            $t->dateTime('layer_date', 6);
            Ddl::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('stock_movement_line_id')->references('id')->on('stock_movement_lines')->restrictOnDelete();
            $t->index(['item_id', 'item_variant_id', 'warehouse_id', 'layer_date']);
        });
        Ddl::enumCheck('valuation_layers', 'status', ValuationLayerStatus::class);
        Ddl::check('valuation_layers', 'chk_valuation_layers_qty', 'remaining_quantity >= 0 AND remaining_quantity <= original_quantity');
        // Equality on the first four columns makes index order the FIFO order, so FOR UPDATE locks
        // open layers oldest-first without a filesort.
        DB::statement('CREATE INDEX valuation_layers_open ON valuation_layers (item_id, item_variant_id, warehouse_id, status, layer_date, id)');

        Schema::create('valuation_layer_consumptions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('valuation_layer_id');
            $t->uuid('stock_movement_line_id');
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8);
            $t->dateTime('created_at', 6);
            $t->foreign('valuation_layer_id')->references('id')->on('valuation_layers')->restrictOnDelete();
            $t->foreign('stock_movement_line_id')->references('id')->on('stock_movement_lines')->restrictOnDelete();
            $t->index('stock_movement_line_id');
        });
        Ddl::appendOnly('valuation_layer_consumptions');

        Schema::create('valuation_variances', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('stock_movement_line_id');
            $t->decimal('standard_unit_cost', 20, 8);
            $t->decimal('actual_unit_cost', 20, 8);
            $t->decimal('quantity', 20, 6);
            $t->decimal('variance_value', 20, 4);
            $t->dateTime('created_at', 6);
            $t->foreign('stock_movement_line_id')->references('id')->on('stock_movement_lines')->restrictOnDelete();
        });
        Ddl::appendOnly('valuation_variances');

        // ---------------- Reconciliation ----------------
        Schema::create('reconciliation_runs', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'status', default: ReconciliationRunStatus::RUNNING->value);
            $t->json('scope')->nullable();
            $t->bigInteger('positions_checked')->default(0);
            $t->bigInteger('findings_count')->default(0);
            $t->uuid('started_by')->nullable();
            $t->dateTime('started_at', 6);
            $t->dateTime('finished_at', 6)->nullable();
            $t->text('error')->nullable();
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('reconciliation_runs', 'status', ReconciliationRunStatus::class);

        Schema::create('reconciliation_findings', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('reconciliation_run_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id');
            $t->uuid('inventory_location_id');
            $t->uuid('inventory_lot_id');
            $t->string('stock_condition', 64);
            $t->decimal('ledger_quantity', 20, 6);
            $t->decimal('projection_quantity', 20, 6);
            $t->decimal('difference', 20, 6);
            Ddl::enumColumn($t, 'status', default: ReconciliationFindingStatus::OPEN->value);
            $t->uuid('resolved_by')->nullable();
            $t->dateTime('resolved_at', 6)->nullable();
            $t->text('resolution_note')->nullable();
            Ddl::timestamps($t);
            $t->foreign('reconciliation_run_id')->references('id')->on('reconciliation_runs')->restrictOnDelete();
            $t->index('status');
        });
        Ddl::enumCheck('reconciliation_findings', 'status', ReconciliationFindingStatus::class);

        // ---------------- Recalls ----------------
        Schema::create('recalls', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->text('reason');
            Ddl::enumColumn($t, 'status', default: RecallStatus::INITIATED->value);
            $t->uuid('initiated_by');
            $t->dateTime('initiated_at', 6);
            $t->uuid('closed_by')->nullable();
            $t->dateTime('closed_at', 6)->nullable();
            $t->text('closure_note')->nullable();
            $t->integer('version')->default(1);
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('recalls', 'status', RecallStatus::class);

        Schema::create('recall_lots', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('recall_id');
            $t->uuid('inventory_lot_id');
            $t->string('previous_lock_status', 64);
            Ddl::timestamps($t);
            $t->foreign('recall_id')->references('id')->on('recalls')->restrictOnDelete();
            $t->foreign('inventory_lot_id')->references('id')->on('inventory_lots')->restrictOnDelete();
            $t->unique(['recall_id', 'inventory_lot_id']);
        });

        // ---------------- Inventory documents ----------------
        $this->inventoryDocument('opening_balance_documents', OpeningBalanceStatus::class, OpeningBalanceStatus::DRAFT->value, function (Blueprint $t) {
            $t->date('cutoff_date');
        }, 'opening_balance_lines', function (Blueprint $t) {
            $t->uuid('storage_location_id');
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8)->default(0);
            $t->string('lot_number', 80)->nullable();
            $t->date('expiry_date')->nullable();
            $t->date('manufacture_date')->nullable();
            $t->json('serial_numbers')->nullable();
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
        }, ['stock_condition' => StockCondition::class]);

        $this->inventoryDocument('relocations', RelocationStatus::class, RelocationStatus::DRAFT->value, null, 'relocation_lines', function (Blueprint $t) {
            $t->uuid('from_storage_location_id');
            $t->uuid('to_storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
            $t->decimal('quantity', 20, 6);
        }, ['stock_condition' => StockCondition::class]);

        $this->inventoryDocument('condition_changes', ConditionChangeStatus::class, ConditionChangeStatus::DRAFT->value, function (Blueprint $t) {
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason')->nullable();
        }, 'condition_change_lines', function (Blueprint $t) {
            $t->uuid('storage_location_id');
            $t->uuid('to_storage_location_id')->nullable();
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            Ddl::enumColumn($t, 'from_condition');
            Ddl::enumColumn($t, 'to_condition');
            $t->decimal('quantity', 20, 6);
        }, ['from_condition' => StockCondition::class, 'to_condition' => StockCondition::class]);

        $this->inventoryDocument('scrap_documents', ScrapStatus::class, ScrapStatus::DRAFT->value, function (Blueprint $t) {
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason')->nullable();
        }, 'scrap_lines', function (Blueprint $t) {
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            Ddl::enumColumn($t, 'stock_condition', default: StockCondition::DAMAGED->value);
            $t->decimal('quantity', 20, 6);
        }, ['stock_condition' => StockCondition::class]);
    }

    /**
     * @param  class-string  $statusEnum
     * @param  array<string, class-string>  $lineEnums
     */
    private function inventoryDocument(string $table, string $statusEnum, string $defaultStatus, ?Closure $extra, string $linesTable, Closure $lineColumns, array $lineEnums): void
    {
        Schema::create($table, function (Blueprint $t) use ($extra, $defaultStatus) {
            Ddl::uuidPrimary($t);
            Ddl::documentColumns($t);
            Ddl::enumColumn($t, 'status', default: $defaultStatus);
            if ($extra) {
                $extra($t);
            }
        });
        Ddl::enumCheck($table, 'status', $statusEnum);
        Ddl::documentChecks($table);

        Schema::create($linesTable, function (Blueprint $t) use ($table, $lineColumns) {
            Ddl::uuidPrimary($t);
            $t->uuid('document_id');
            $t->integer('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $lineColumns($t);
            $t->text('notes')->nullable();
            Ddl::timestamps($t);
            $t->foreign('document_id')->references('id')->on($table)->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['document_id', 'line_no']);
        });
        foreach ($lineEnums as $col => $enum) {
            Ddl::enumCheck($linesTable, $col, $enum);
        }
        if (Schema::hasColumn($linesTable, 'quantity')) {
            Ddl::check($linesTable, "chk_{$linesTable}_qty", 'quantity > 0');
        }
    }

    public function down(): void
    {
        foreach (['scrap_lines', 'scrap_documents', 'condition_change_lines', 'condition_changes', 'relocation_lines', 'relocations',
            'opening_balance_lines', 'opening_balance_documents', 'recall_lots', 'recalls', 'reconciliation_findings', 'reconciliation_runs',
            'valuation_variances', 'valuation_layer_consumptions', 'valuation_layers', 'item_cost_projections', 'quality_checks',
            'stock_reservations', 'inventory_balances', 'stock_movement_lines', 'stock_movements', 'item_serials', 'inventory_lots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
