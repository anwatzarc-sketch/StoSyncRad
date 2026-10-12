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
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SENTINEL = "'00000000-0000-7000-8000-000000000000'::uuid";

    public function up(): void
    {
        // ---------------- Lots & serials ----------------
        Schema::create('inventory_lots', function (Blueprint $t) {
            Pg::uuidPrimary($t);
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
            Pg::enumColumn($t, 'qc_status', default: LotQcStatus::PENDING->value);
            $t->uuid('qc_released_by')->nullable();
            $t->timestampTz('qc_released_at')->nullable();
            Pg::enumColumn($t, 'lock_status', default: LotLockStatus::UNLOCKED->value);
            $t->string('lock_reference_type', 64)->nullable();
            $t->uuid('lock_reference_id')->nullable();
            Pg::enumColumn($t, 'status', default: LotStatus::ACTIVE->value);
            $t->jsonb('attributes')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('item_variant_id')->references('id')->on('item_variants')->restrictOnDelete();
            $t->foreign('supplier_id')->references('id')->on('partners')->restrictOnDelete();
            $t->index(['item_id', 'expiry_date']);
            $t->index('lock_status');
            $t->index('lot_number');
        });
        Pg::enumCheck('inventory_lots', 'qc_status', LotQcStatus::class);
        Pg::enumCheck('inventory_lots', 'lock_status', LotLockStatus::class);
        Pg::enumCheck('inventory_lots', 'status', LotStatus::class);
        Pg::check('inventory_lots', 'chk_inventory_lots_dates', 'manufacture_date IS NULL OR expiry_date IS NULL OR manufacture_date <= expiry_date');
        // Default uniqueness: (item, lot_number). The item_supplier scope is enforced by the action
        // (the narrower DB index would reject legitimate same-number lots from different suppliers).
        DB::statement('CREATE UNIQUE INDEX inventory_lots_item_supplier_number_uq ON inventory_lots (item_id, COALESCE(supplier_id, '.self::SENTINEL.'), lot_number)');

        Schema::create('item_serials', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->string('serial_number', 120);
            $t->uuid('inventory_lot_id')->nullable();
            Pg::enumColumn($t, 'lifecycle_status', default: SerialStatus::REGISTERED->value);
            $t->uuid('current_inventory_location_id')->nullable();
            Pg::enumColumn($t, 'current_condition', nullable: true);
            $t->uuid('current_warehouse_id')->nullable();
            $t->date('warranty_until')->nullable();
            $t->jsonb('attributes')->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('inventory_lot_id')->references('id')->on('inventory_lots')->restrictOnDelete();
            $t->foreign('current_inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->unique(['item_id', 'serial_number']);
            $t->index('serial_number');
            $t->index('current_inventory_location_id');
        });
        Pg::enumCheck('item_serials', 'lifecycle_status', SerialStatus::class);
        Pg::enumCheck('item_serials', 'current_condition', StockCondition::class);

        // ---------------- Ledger (append-only) ----------------
        Schema::create('stock_movements', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('movement_number', 40)->unique();
            Pg::enumColumn($t, 'movement_type');
            Pg::enumColumn($t, 'source_document_type');
            $t->uuid('source_document_id');
            $t->string('source_document_number', 40);
            $t->uuid('posting_event_id')->unique();
            $t->uuid('idempotency_key_id')->nullable();
            Pg::enumColumn($t, 'status', default: MovementStatus::POSTED->value);
            $t->uuid('posting_actor_id')->nullable();
            $t->timestampTz('posted_at');
            $t->date('business_date');
            $t->uuid('reversal_of_movement_id')->nullable();
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason_text')->nullable();
            $t->uuid('correlation_id');
            $t->uuid('warehouse_id')->nullable();
            $t->uuid('counterparty_warehouse_id')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at')->nullable();
            $t->foreign('reversal_of_movement_id')->references('id')->on('stock_movements')->restrictOnDelete();
            $t->index(['source_document_type', 'source_document_id']);
            $t->index('posted_at');
            $t->index(['movement_type', 'posted_at']);
            $t->index(['warehouse_id', 'posted_at']);
        });
        Pg::enumCheck('stock_movements', 'movement_type', MovementType::class);
        Pg::enumCheck('stock_movements', 'source_document_type', DocumentType::class);
        Pg::enumCheck('stock_movements', 'status', MovementStatus::class);
        Pg::appendOnly('stock_movements', ['status', 'updated_at']);
        Pg::grantColumnUpdate('stock_movements', ['status', 'updated_at']);

        Schema::create('stock_movement_lines', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('stock_movement_id');
            $t->unsignedInteger('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $t->uuid('source_inventory_location_id');
            $t->uuid('destination_inventory_location_id');
            Pg::enumColumn($t, 'source_condition');
            Pg::enumColumn($t, 'destination_condition');
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
            $t->timestampTz('created_at');
            $t->timestampTz('updated_at')->nullable();
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
        Pg::enumCheck('stock_movement_lines', 'source_condition', StockCondition::class);
        Pg::enumCheck('stock_movement_lines', 'destination_condition', StockCondition::class);
        Pg::check('stock_movement_lines', 'chk_sml_positive_qty', 'quantity > 0');
        Pg::check('stock_movement_lines', 'chk_sml_serial_unit', 'item_serial_id IS NULL OR quantity = 1');
        Pg::check('stock_movement_lines', 'chk_sml_distinct_ends',
            'source_inventory_location_id <> destination_inventory_location_id OR source_condition <> destination_condition');
        Pg::check('stock_movement_lines', 'chk_sml_reversed_qty', 'reversed_quantity >= 0 AND reversed_quantity <= quantity');
        Pg::check('stock_movement_lines', 'chk_sml_cost_non_negative', 'unit_cost_snapshot >= 0');
        Pg::appendOnly('stock_movement_lines', ['reversed_by_line_id', 'reversed_quantity', 'updated_at']);
        Pg::grantColumnUpdate('stock_movement_lines', ['reversed_by_line_id', 'reversed_quantity', 'updated_at']);

        // ---------------- Balance projection ----------------
        Schema::create('inventory_balances', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id');            // sentinel when the item has no variants
            $t->uuid('inventory_location_id');
            $t->uuid('inventory_lot_id');           // sentinel when not lot-tracked
            Pg::enumColumn($t, 'stock_condition');
            $t->uuid('owner_partner_id');           // sentinel unless consignment is enabled
            $t->uuid('package_id');                 // sentinel unless packages are enabled
            $t->uuid('warehouse_id')->nullable();   // denormalized for scoping
            $t->decimal('quantity_on_hand', 20, 6)->default(0);
            $t->decimal('quantity_reserved', 20, 6)->default(0);
            $t->decimal('unit_cost_projection', 20, 8)->default(0);
            $t->decimal('total_value_projection', 20, 4)->default(0);
            $t->boolean('allow_negative')->default(false);
            $t->timestampTz('last_movement_at')->nullable();
            $t->timestampTz('last_counted_at')->nullable();
            $t->boolean('is_frozen')->default(false);
            $t->uuid('frozen_by_stocktake_id')->nullable();
            $t->unsignedBigInteger('version')->default(0);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->unique(['item_id', 'item_variant_id', 'inventory_location_id', 'inventory_lot_id', 'stock_condition', 'owner_partner_id', 'package_id'], 'inventory_balances_position_uq');
            $t->index(['inventory_location_id', 'item_id']);
            $t->index(['item_id', 'stock_condition']);
            $t->index(['warehouse_id', 'item_id']);
        });
        Pg::enumCheck('inventory_balances', 'stock_condition', StockCondition::class);
        Pg::check('inventory_balances', 'chk_balances_non_negative', 'allow_negative OR quantity_on_hand >= 0');
        Pg::check('inventory_balances', 'chk_balances_reserved', 'quantity_reserved >= 0 AND (allow_negative OR quantity_reserved <= quantity_on_hand)');
        DB::statement('CREATE INDEX inventory_balances_positive ON inventory_balances (item_id, warehouse_id) WHERE quantity_on_hand <> 0');
        DB::statement('CREATE INDEX inventory_balances_lot ON inventory_balances (inventory_lot_id) WHERE inventory_lot_id <> '.self::SENTINEL);

        // ---------------- Reservations ----------------
        Schema::create('stock_reservations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'reference_type');
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
            Pg::enumColumn($t, 'status', default: ReservationStatus::ACTIVE->value);
            $t->timestampTz('expires_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('released_by')->nullable();
            $t->timestampTz('released_at')->nullable();
            $t->text('release_reason')->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
            $t->foreign('inventory_balance_id')->references('id')->on('inventory_balances')->restrictOnDelete();
            $t->foreign('item_serial_id')->references('id')->on('item_serials')->restrictOnDelete();
            $t->index(['reference_type', 'reference_id']);
            $t->index('inventory_balance_id');
        });
        Pg::enumCheck('stock_reservations', 'reference_type', ReservationReferenceType::class);
        Pg::enumCheck('stock_reservations', 'status', ReservationStatus::class);
        Pg::check('stock_reservations', 'chk_reservations_quantities', 'quantity_reserved > 0 AND quantity_consumed >= 0 AND quantity_released >= 0 AND quantity_consumed + quantity_released <= quantity_reserved');
        DB::statement("CREATE UNIQUE INDEX stock_reservations_active_uq ON stock_reservations (reference_line_id, inventory_balance_id, COALESCE(item_serial_id, ".self::SENTINEL.")) WHERE status IN ('active','partially_consumed')");
        DB::statement("CREATE UNIQUE INDEX stock_reservations_serial_active_uq ON stock_reservations (item_serial_id) WHERE item_serial_id IS NOT NULL AND status IN ('active','partially_consumed')");

        // ---------------- Quality checks ----------------
        Schema::create('quality_checks', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'document_type');
            $t->uuid('document_id');
            $t->uuid('document_line_id')->nullable();
            $t->uuid('item_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('warehouse_id');
            $t->uuid('qc_checklist_template_id')->nullable();
            $t->jsonb('results');
            Pg::enumColumn($t, 'outcome');
            $t->decimal('inspected_quantity', 20, 6)->default(0);
            $t->decimal('passed_quantity', 20, 6)->default(0);
            $t->decimal('failed_quantity', 20, 6)->default(0);
            $t->text('notes')->nullable();
            $t->uuid('inspector_id');
            $t->timestampTz('inspected_at');
            Pg::timestamps($t);
            $t->index(['document_type', 'document_id']);
            $t->index('inventory_lot_id');
        });
        Pg::enumCheck('quality_checks', 'document_type', DocumentType::class);
        Pg::enumCheck('quality_checks', 'outcome', QcOutcome::class);

        // ---------------- Valuation ----------------
        Schema::create('item_cost_projections', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id');     // sentinel when no variant
            $t->uuid('warehouse_id');        // sentinel for company-level costing
            $t->decimal('quantity', 20, 6)->default(0);
            $t->decimal('total_value', 20, 4)->default(0);
            $t->decimal('average_cost', 20, 8)->default(0);
            $t->unsignedBigInteger('version')->default(0);
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['item_id', 'item_variant_id', 'warehouse_id'], 'item_cost_projections_identity_uq');
        });

        Schema::create('valuation_layers', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('item_variant_id');
            $t->uuid('warehouse_id');             // sentinel for company-level costing
            $t->uuid('stock_movement_line_id');
            $t->decimal('original_quantity', 20, 6);
            $t->decimal('remaining_quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8);
            Pg::enumColumn($t, 'status', default: ValuationLayerStatus::OPEN->value);
            $t->timestampTz('layer_date');
            Pg::timestamps($t);
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->foreign('stock_movement_line_id')->references('id')->on('stock_movement_lines')->restrictOnDelete();
            $t->index(['item_id', 'item_variant_id', 'warehouse_id', 'layer_date']);
        });
        Pg::enumCheck('valuation_layers', 'status', ValuationLayerStatus::class);
        Pg::check('valuation_layers', 'chk_valuation_layers_qty', 'remaining_quantity >= 0 AND remaining_quantity <= original_quantity');
        DB::statement("CREATE INDEX valuation_layers_open ON valuation_layers (item_id, item_variant_id, warehouse_id, layer_date, id) WHERE status = 'open'");

        Schema::create('valuation_layer_consumptions', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('valuation_layer_id');
            $t->uuid('stock_movement_line_id');
            $t->decimal('quantity', 20, 6);
            $t->decimal('unit_cost', 20, 8);
            $t->timestampTz('created_at');
            $t->foreign('valuation_layer_id')->references('id')->on('valuation_layers')->restrictOnDelete();
            $t->foreign('stock_movement_line_id')->references('id')->on('stock_movement_lines')->restrictOnDelete();
            $t->index('stock_movement_line_id');
        });
        Pg::appendOnly('valuation_layer_consumptions');

        Schema::create('valuation_variances', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('item_id');
            $t->uuid('stock_movement_line_id');
            $t->decimal('standard_unit_cost', 20, 8);
            $t->decimal('actual_unit_cost', 20, 8);
            $t->decimal('quantity', 20, 6);
            $t->decimal('variance_value', 20, 4);
            $t->timestampTz('created_at');
            $t->foreign('stock_movement_line_id')->references('id')->on('stock_movement_lines')->restrictOnDelete();
        });
        Pg::appendOnly('valuation_variances');

        // ---------------- Reconciliation ----------------
        Schema::create('reconciliation_runs', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'status', default: ReconciliationRunStatus::RUNNING->value);
            $t->jsonb('scope')->nullable();
            $t->unsignedBigInteger('positions_checked')->default(0);
            $t->unsignedBigInteger('findings_count')->default(0);
            $t->uuid('started_by')->nullable();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->text('error')->nullable();
            Pg::timestamps($t);
        });
        Pg::enumCheck('reconciliation_runs', 'status', ReconciliationRunStatus::class);

        Schema::create('reconciliation_findings', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('reconciliation_run_id');
            $t->uuid('item_id');
            $t->uuid('item_variant_id');
            $t->uuid('inventory_location_id');
            $t->uuid('inventory_lot_id');
            $t->string('stock_condition', 64);
            $t->decimal('ledger_quantity', 20, 6);
            $t->decimal('projection_quantity', 20, 6);
            $t->decimal('difference', 20, 6);
            Pg::enumColumn($t, 'status', default: ReconciliationFindingStatus::OPEN->value);
            $t->uuid('resolved_by')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->text('resolution_note')->nullable();
            Pg::timestamps($t);
            $t->foreign('reconciliation_run_id')->references('id')->on('reconciliation_runs')->restrictOnDelete();
            $t->index('status');
        });
        Pg::enumCheck('reconciliation_findings', 'status', ReconciliationFindingStatus::class);

        // ---------------- Recalls ----------------
        Schema::create('recalls', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('document_number', 40)->unique();
            $t->text('reason');
            Pg::enumColumn($t, 'status', default: RecallStatus::INITIATED->value);
            $t->uuid('initiated_by');
            $t->timestampTz('initiated_at');
            $t->uuid('closed_by')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->text('closure_note')->nullable();
            $t->unsignedInteger('version')->default(1);
            Pg::timestamps($t);
        });
        Pg::enumCheck('recalls', 'status', RecallStatus::class);

        Schema::create('recall_lots', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('recall_id');
            $t->uuid('inventory_lot_id');
            $t->string('previous_lock_status', 64);
            Pg::timestamps($t);
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
            $t->jsonb('serial_numbers')->nullable();
            Pg::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
        }, ['stock_condition' => StockCondition::class]);

        $this->inventoryDocument('relocations', RelocationStatus::class, RelocationStatus::DRAFT->value, null, 'relocation_lines', function (Blueprint $t) {
            $t->uuid('from_storage_location_id');
            $t->uuid('to_storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            Pg::enumColumn($t, 'stock_condition', default: StockCondition::AVAILABLE->value);
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
            Pg::enumColumn($t, 'from_condition');
            Pg::enumColumn($t, 'to_condition');
            $t->decimal('quantity', 20, 6);
        }, ['from_condition' => StockCondition::class, 'to_condition' => StockCondition::class]);

        $this->inventoryDocument('scrap_documents', ScrapStatus::class, ScrapStatus::DRAFT->value, function (Blueprint $t) {
            $t->uuid('reason_lookup_value_id')->nullable();
            $t->text('reason')->nullable();
        }, 'scrap_lines', function (Blueprint $t) {
            $t->uuid('storage_location_id');
            $t->uuid('inventory_lot_id')->nullable();
            $t->uuid('item_serial_id')->nullable();
            Pg::enumColumn($t, 'stock_condition', default: StockCondition::DAMAGED->value);
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
            Pg::uuidPrimary($t);
            Pg::documentColumns($t);
            Pg::enumColumn($t, 'status', default: $defaultStatus);
            if ($extra) {
                $extra($t);
            }
        });
        Pg::enumCheck($table, 'status', $statusEnum);
        Pg::documentChecks($table);

        Schema::create($linesTable, function (Blueprint $t) use ($table, $lineColumns) {
            Pg::uuidPrimary($t);
            $t->uuid('document_id');
            $t->unsignedInteger('line_no');
            $t->uuid('item_id');
            $t->uuid('item_variant_id')->nullable();
            $lineColumns($t);
            $t->text('notes')->nullable();
            Pg::timestamps($t);
            $t->foreign('document_id')->references('id')->on($table)->restrictOnDelete();
            $t->foreign('item_id')->references('id')->on('items')->restrictOnDelete();
            $t->unique(['document_id', 'line_no']);
        });
        foreach ($lineEnums as $col => $enum) {
            Pg::enumCheck($linesTable, $col, $enum);
        }
        if (Schema::hasColumn($linesTable, 'quantity')) {
            Pg::check($linesTable, "chk_{$linesTable}_qty", 'quantity > 0');
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
