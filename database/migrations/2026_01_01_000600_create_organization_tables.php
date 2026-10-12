<?php

use App\Domains\Catalog\Enums\AttachmentKind;
use App\Domains\Organization\Enums\BranchKind;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\PutawayAppliesTo;
use App\Domains\Organization\Enums\StorageLocationLevel;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Enums\WarehouseCapability;
use App\Domains\Organization\Enums\WarehouseKind;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Enums\PartnerType;
use App\Domains\Platform\Enums\AttachableType;
use App\Support\Database\Pg;
use App\Support\Enums\DataClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profile', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('legal_name', 200);
            $t->string('trading_name', 200)->nullable();
            $t->string('registration_number', 80)->nullable();
            $t->string('tax_id', 80)->nullable();
            $t->jsonb('registered_address')->nullable();
            $t->jsonb('operational_address')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('website', 2048)->nullable();
            $t->unsignedSmallInteger('fiscal_year_start_month')->default(1);
            $t->string('base_currency', 3);
            $t->uuid('industry_lookup_value_id')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('singleton')->default(true)->unique();   // exactly one row
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });
        Pg::check('company_profile', 'chk_company_profile_singleton', 'singleton = true');
        Pg::check('company_profile', 'chk_company_profile_fiscal_month', 'fiscal_year_start_month BETWEEN 1 AND 12');

        Schema::create('branches', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 32)->unique();
            $t->string('name', 160);
            Pg::enumColumn($t, 'kind');
            $t->uuid('parent_branch_id')->nullable();
            $t->jsonb('address')->nullable();
            $t->jsonb('contact')->nullable();
            $t->string('timezone', 64)->nullable();
            $t->uuid('manager_person_id')->nullable();
            Pg::enumColumn($t, 'status', default: BranchStatus::ACTIVE->value);
            $t->date('opened_on')->nullable();
            $t->date('closed_on')->nullable();
            $t->jsonb('metadata')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('parent_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('manager_person_id')->references('id')->on('persons')->restrictOnDelete();
        });
        Pg::enumCheck('branches', 'kind', BranchKind::class);
        Pg::enumCheck('branches', 'status', BranchStatus::class);

        Schema::create('storage_categories', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->jsonb('constraints')->nullable();    // temperature, hazard, max weight…
            $t->boolean('is_active')->default(true);
            Pg::timestamps($t);
        });

        Schema::create('warehouses', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('branch_id');
            $t->string('code', 32)->unique();
            $t->string('name', 160);
            Pg::enumColumn($t, 'kind', default: WarehouseKind::MAIN->value);
            $t->jsonb('address')->nullable();
            $t->jsonb('contact')->nullable();
            $t->uuid('manager_person_id')->nullable();
            $t->uuid('default_receiving_location_id')->nullable();
            $t->uuid('default_dispatch_location_id')->nullable();
            $t->uuid('default_quarantine_location_id')->nullable();
            $t->uuid('default_damaged_location_id')->nullable();
            $t->uuid('default_scrap_location_id')->nullable();
            $t->uuid('default_returns_location_id')->nullable();
            $t->uuid('default_packing_location_id')->nullable();
            $t->uuid('default_storage_location_id')->nullable();
            Pg::enumColumn($t, 'status', default: WarehouseStatus::ACTIVE->value);
            $t->jsonb('operational_calendar')->nullable();
            $t->jsonb('metadata')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('manager_person_id')->references('id')->on('persons')->restrictOnDelete();
            $t->index('branch_id');
        });
        Pg::enumCheck('warehouses', 'kind', WarehouseKind::class);
        Pg::enumCheck('warehouses', 'status', WarehouseStatus::class);
        Pg::enumArray('warehouses', 'capabilities', WarehouseCapability::class);

        Schema::create('storage_locations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('warehouse_id');
            $t->uuid('parent_id')->nullable();
            $t->string('code', 40);
            $t->string('full_path', 400)->unique();
            $t->string('name', 160);
            Pg::enumColumn($t, 'level', default: StorageLocationLevel::BIN->value);
            Pg::enumColumn($t, 'usage', default: StorageLocationUsage::STORAGE->value);
            $t->uuid('storage_category_id')->nullable();
            $t->jsonb('capacity')->nullable();
            $t->jsonb('temperature_range')->nullable();
            $t->boolean('is_pickable')->default(true);
            $t->boolean('is_putaway_target')->default(true);
            $t->boolean('is_system')->default(false);
            $t->unsignedInteger('counting_frequency_days')->nullable();
            $t->timestampTz('last_counted_at')->nullable();
            $t->string('barcode', 128)->nullable()->unique();
            Pg::enumColumn($t, 'status', default: StorageLocationStatus::ACTIVE->value);
            $t->unsignedInteger('sort_order')->default(0);
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('parent_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->foreign('storage_category_id')->references('id')->on('storage_categories')->restrictOnDelete();
            $t->unique(['warehouse_id', 'code']);
            $t->index(['warehouse_id', 'usage']);
        });
        Pg::enumCheck('storage_locations', 'level', StorageLocationLevel::class);
        Pg::enumCheck('storage_locations', 'usage', StorageLocationUsage::class);
        Pg::enumCheck('storage_locations', 'status', StorageLocationStatus::class);
        // A child location must belong to the same warehouse as its parent.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION storage_location_parent_same_warehouse() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.parent_id IS NOT NULL AND (SELECT warehouse_id FROM storage_locations WHERE id = NEW.parent_id) <> NEW.warehouse_id THEN
        RAISE EXCEPTION 'STORAGE_LOCATION_WAREHOUSE_MISMATCH' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER trg_storage_locations_parent_wh BEFORE INSERT OR UPDATE ON storage_locations
    FOR EACH ROW EXECUTE FUNCTION storage_location_parent_same_warehouse();
SQL);

        foreach (['receiving', 'dispatch', 'quarantine', 'damaged', 'scrap', 'returns', 'packing', 'storage'] as $usage) {
            DB::statement("ALTER TABLE warehouses ADD CONSTRAINT fk_warehouses_default_{$usage} FOREIGN KEY (default_{$usage}_location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED");
        }

        Schema::create('inventory_locations', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'location_type');
            $t->uuid('warehouse_id')->nullable();
            $t->uuid('storage_location_id')->nullable()->unique();
            $t->uuid('lane_source_warehouse_id')->nullable();
            $t->uuid('lane_destination_warehouse_id')->nullable();
            $t->string('code', 120)->unique();
            $t->string('name', 200);
            $t->boolean('is_active')->default(true);
            Pg::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('storage_location_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->foreign('lane_source_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('lane_destination_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->index(['location_type', 'warehouse_id']);
        });
        Pg::enumCheck('inventory_locations', 'location_type', InventoryLocationType::class);
        Pg::check('inventory_locations', 'chk_inventory_locations_physical',
            "(location_type = 'physical' AND storage_location_id IS NOT NULL AND warehouse_id IS NOT NULL) OR (location_type <> 'physical' AND storage_location_id IS NULL)");
        DB::statement("CREATE UNIQUE INDEX inventory_locations_transit_lane_uq ON inventory_locations (lane_source_warehouse_id, lane_destination_warehouse_id) WHERE location_type = 'in_transit'");

        Schema::create('putaway_rules', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('warehouse_id');
            Pg::enumColumn($t, 'applies_to');
            $t->uuid('target_id');
            Pg::enumColumn($t, 'source_usage', default: StorageLocationUsage::RECEIVING->value);
            $t->uuid('destination_location_id');
            $t->unsignedInteger('priority')->default(10);
            $t->boolean('is_active')->default(true);
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('destination_location_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->index(['warehouse_id', 'applies_to', 'target_id']);
        });
        Pg::enumCheck('putaway_rules', 'applies_to', PutawayAppliesTo::class);
        Pg::enumCheck('putaway_rules', 'source_usage', StorageLocationUsage::class);

        Schema::create('partners', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 40)->unique();
            $t->string('name', 200);
            $t->string('legal_name', 200)->nullable();
            $t->string('tax_id', 80)->nullable();
            $t->jsonb('addresses')->nullable();
            $t->string('email', 254)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('website', 2048)->nullable();
            $t->string('default_currency', 3)->nullable();
            $t->uuid('payment_terms_lookup_value_id')->nullable();
            $t->uuid('incoterm_lookup_value_id')->nullable();
            $t->unsignedInteger('lead_time_days')->nullable();
            $t->unsignedSmallInteger('rating')->nullable();
            $t->boolean('is_internal')->default(false);
            $t->uuid('internal_branch_id')->nullable();
            $t->uuid('department_lookup_value_id')->nullable();
            $t->string('tracking_url_template', 2048)->nullable();
            Pg::enumColumn($t, 'status', default: PartnerStatus::ACTIVE->value);
            $t->text('notes')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('internal_branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
        Pg::enumArray('partners', 'partner_types', PartnerType::class);
        Pg::enumCheck('partners', 'status', PartnerStatus::class);
        Pg::check('partners', 'chk_partners_has_type', 'cardinality(partner_types) > 0');
        Pg::check('partners', 'chk_partners_rating', 'rating IS NULL OR rating BETWEEN 1 AND 5');
        DB::statement('CREATE INDEX partners_types_gin ON partners USING gin (partner_types)');
        DB::statement('CREATE INDEX partners_name_trgm ON partners USING gin (name gin_trgm_ops)');

        Schema::create('partner_contacts', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('partner_id');
            $t->string('name', 160);
            $t->string('role', 120)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('is_primary')->default(false);
            Pg::timestamps($t);
            $t->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
        });

        Schema::create('document_attachments', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'document_type');
            $t->uuid('document_id');
            Pg::enumColumn($t, 'kind', default: AttachmentKind::OTHER->value);
            $t->string('original_filename', 255);
            $t->string('private_path', 512);
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('file_size');
            $t->string('checksum', 64);
            Pg::enumColumn($t, 'classification', default: DataClassification::OPERATIONAL->value);
            $t->uuid('uploaded_by');
            Pg::timestamps($t);
            $t->index(['document_type', 'document_id']);
        });
        Pg::enumCheck('document_attachments', 'document_type', AttachableType::class);
        Pg::enumCheck('document_attachments', 'kind', AttachmentKind::class);
        Pg::enumCheck('document_attachments', 'classification', DataClassification::class);

        // persons.branch_id now that branches exist
        DB::statement('ALTER TABLE persons ADD CONSTRAINT fk_persons_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE persons DROP CONSTRAINT IF EXISTS fk_persons_branch');
        foreach (['document_attachments', 'partner_contacts', 'partners', 'putaway_rules', 'inventory_locations'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['receiving', 'dispatch', 'quarantine', 'damaged', 'scrap', 'returns', 'packing', 'storage'] as $usage) {
            DB::statement("ALTER TABLE warehouses DROP CONSTRAINT IF EXISTS fk_warehouses_default_{$usage}");
        }
        foreach (['storage_locations', 'warehouses', 'storage_categories', 'branches', 'company_profile'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP FUNCTION IF EXISTS storage_location_parent_same_warehouse() CASCADE');
    }
};
