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
use App\Support\Database\Ddl;
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
            Ddl::uuidPrimary($t);
            $t->string('legal_name', 200);
            $t->string('trading_name', 200)->nullable();
            $t->string('registration_number', 80)->nullable();
            $t->string('tax_id', 80)->nullable();
            $t->json('registered_address')->nullable();
            $t->json('operational_address')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('website', 2048)->nullable();
            $t->smallInteger('fiscal_year_start_month')->default(1);
            $t->string('base_currency', 3);
            $t->uuid('industry_lookup_value_id')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('singleton')->default(true)->unique();   // exactly one row
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
        });
        Ddl::check('company_profile', 'chk_company_profile_singleton', 'singleton = true');
        Ddl::check('company_profile', 'chk_company_profile_fiscal_month', 'fiscal_year_start_month BETWEEN 1 AND 12');

        Schema::create('branches', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 32)->unique();
            $t->string('name', 160);
            Ddl::enumColumn($t, 'kind');
            $t->uuid('parent_branch_id')->nullable();
            $t->json('address')->nullable();
            $t->json('contact')->nullable();
            $t->string('timezone', 64)->nullable();
            $t->uuid('manager_person_id')->nullable();
            Ddl::enumColumn($t, 'status', default: BranchStatus::ACTIVE->value);
            $t->date('opened_on')->nullable();
            $t->date('closed_on')->nullable();
            $t->json('metadata')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('parent_branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('manager_person_id')->references('id')->on('persons')->restrictOnDelete();
        });
        Ddl::enumCheck('branches', 'kind', BranchKind::class);
        Ddl::enumCheck('branches', 'status', BranchStatus::class);

        Schema::create('storage_categories', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 40)->unique();
            $t->string('name', 120);
            $t->json('constraints')->nullable();    // temperature, hazard, max weight…
            $t->boolean('is_active')->default(true);
            Ddl::timestamps($t);
        });

        Schema::create('warehouses', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('branch_id');
            $t->string('code', 32)->unique();
            $t->string('name', 160);
            Ddl::enumColumn($t, 'kind', default: WarehouseKind::MAIN->value);
            $t->json('address')->nullable();
            $t->json('contact')->nullable();
            $t->uuid('manager_person_id')->nullable();
            $t->uuid('default_receiving_location_id')->nullable();
            $t->uuid('default_dispatch_location_id')->nullable();
            $t->uuid('default_quarantine_location_id')->nullable();
            $t->uuid('default_damaged_location_id')->nullable();
            $t->uuid('default_scrap_location_id')->nullable();
            $t->uuid('default_returns_location_id')->nullable();
            $t->uuid('default_packing_location_id')->nullable();
            $t->uuid('default_storage_location_id')->nullable();
            Ddl::enumColumn($t, 'status', default: WarehouseStatus::ACTIVE->value);
            $t->json('operational_calendar')->nullable();
            $t->json('metadata')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('manager_person_id')->references('id')->on('persons')->restrictOnDelete();
            $t->index('branch_id');
        });
        Ddl::enumCheck('warehouses', 'kind', WarehouseKind::class);
        Ddl::enumCheck('warehouses', 'status', WarehouseStatus::class);
        Ddl::enumArray('warehouses', 'capabilities', WarehouseCapability::class);

        Schema::create('storage_locations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('warehouse_id');
            $t->uuid('parent_id')->nullable();
            $t->string('code', 40);
            $t->string('full_path', 400)->unique();
            $t->string('name', 160);
            Ddl::enumColumn($t, 'level', default: StorageLocationLevel::BIN->value);
            Ddl::enumColumn($t, 'usage', default: StorageLocationUsage::STORAGE->value);
            $t->uuid('storage_category_id')->nullable();
            $t->json('capacity')->nullable();
            $t->json('temperature_range')->nullable();
            $t->boolean('is_pickable')->default(true);
            $t->boolean('is_putaway_target')->default(true);
            $t->boolean('is_system')->default(false);
            $t->integer('counting_frequency_days')->nullable();
            $t->dateTime('last_counted_at', 6)->nullable();
            $t->string('barcode', 128)->nullable()->unique();
            Ddl::enumColumn($t, 'status', default: StorageLocationStatus::ACTIVE->value);
            $t->integer('sort_order')->default(0);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('parent_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->foreign('storage_category_id')->references('id')->on('storage_categories')->restrictOnDelete();
            $t->unique(['warehouse_id', 'code']);
            $t->index(['warehouse_id', 'usage']);
        });
        Ddl::enumCheck('storage_locations', 'level', StorageLocationLevel::class);
        Ddl::enumCheck('storage_locations', 'usage', StorageLocationUsage::class);
        Ddl::enumCheck('storage_locations', 'status', StorageLocationStatus::class);
        // A child location must belong to the same warehouse as its parent. Raised as a CHECK
        // violation (errno 3819) so the API reports it like any other integrity rule.
        foreach (['ins' => 'INSERT', 'upd' => 'UPDATE'] as $suffix => $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_storage_locations_parent_wh_{$suffix}");
            DB::unprepared(
                "CREATE TRIGGER trg_storage_locations_parent_wh_{$suffix} BEFORE {$event} ON storage_locations FOR EACH ROW BEGIN ".
                'IF NEW.parent_id IS NOT NULL AND (SELECT warehouse_id FROM storage_locations WHERE id = NEW.parent_id) <> NEW.warehouse_id THEN '.
                "SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 3819, MESSAGE_TEXT = 'STORAGE_LOCATION_WAREHOUSE_MISMATCH'; END IF; END"
            );
        }

        // Warehouses are inserted first and their default locations set afterwards
        // (CreateWarehouseAction), so these need no deferral.
        foreach (['receiving', 'dispatch', 'quarantine', 'damaged', 'scrap', 'returns', 'packing', 'storage'] as $usage) {
            DB::statement("ALTER TABLE warehouses ADD CONSTRAINT fk_warehouses_default_{$usage} FOREIGN KEY (default_{$usage}_location_id) REFERENCES storage_locations(id) ON DELETE RESTRICT");
        }

        Schema::create('inventory_locations', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'location_type');
            $t->uuid('warehouse_id')->nullable();
            $t->uuid('storage_location_id')->nullable()->unique();
            $t->uuid('lane_source_warehouse_id')->nullable();
            $t->uuid('lane_destination_warehouse_id')->nullable();
            $t->string('code', 120)->unique();
            $t->string('name', 200);
            $t->boolean('is_active')->default(true);
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('storage_location_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->foreign('lane_source_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('lane_destination_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->index(['location_type', 'warehouse_id']);
        });
        Ddl::enumCheck('inventory_locations', 'location_type', InventoryLocationType::class);
        Ddl::check('inventory_locations', 'chk_inventory_locations_physical',
            "(location_type = 'physical' AND storage_location_id IS NOT NULL AND warehouse_id IS NOT NULL) OR (location_type <> 'physical' AND storage_location_id IS NULL)");
        Ddl::uniqueIndex('inventory_locations', 'inventory_locations_transit_lane_uq',
            "(CASE WHEN location_type = 'in_transit' THEN lane_source_warehouse_id END), (CASE WHEN location_type = 'in_transit' THEN lane_destination_warehouse_id END)");

        Schema::create('putaway_rules', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('warehouse_id');
            Ddl::enumColumn($t, 'applies_to');
            $t->uuid('target_id');
            Ddl::enumColumn($t, 'source_usage', default: StorageLocationUsage::RECEIVING->value);
            $t->uuid('destination_location_id');
            $t->integer('priority')->default(10);
            $t->boolean('is_active')->default(true);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $t->foreign('destination_location_id')->references('id')->on('storage_locations')->restrictOnDelete();
            $t->index(['warehouse_id', 'applies_to', 'target_id']);
        });
        Ddl::enumCheck('putaway_rules', 'applies_to', PutawayAppliesTo::class);
        Ddl::enumCheck('putaway_rules', 'source_usage', StorageLocationUsage::class);

        Schema::create('partners', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 40)->unique();
            $t->string('name', 200);
            $t->string('legal_name', 200)->nullable();
            $t->string('tax_id', 80)->nullable();
            $t->json('addresses')->nullable();
            $t->string('email', 254)->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('website', 2048)->nullable();
            $t->string('default_currency', 3)->nullable();
            $t->uuid('payment_terms_lookup_value_id')->nullable();
            $t->uuid('incoterm_lookup_value_id')->nullable();
            $t->integer('lead_time_days')->nullable();
            $t->smallInteger('rating')->nullable();
            $t->boolean('is_internal')->default(false);
            $t->uuid('internal_branch_id')->nullable();
            $t->uuid('department_lookup_value_id')->nullable();
            $t->string('tracking_url_template', 2048)->nullable();
            Ddl::enumColumn($t, 'status', default: PartnerStatus::ACTIVE->value);
            $t->text('notes')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('internal_branch_id')->references('id')->on('branches')->restrictOnDelete();
        });
        Ddl::enumArray('partners', 'partner_types', PartnerType::class);
        Ddl::enumCheck('partners', 'status', PartnerStatus::class);
        Ddl::check('partners', 'chk_partners_has_type', 'JSON_LENGTH(partner_types) > 0');
        Ddl::check('partners', 'chk_partners_rating', 'rating IS NULL OR rating BETWEEN 1 AND 5');
        // Multi-valued index serving `'supplier' MEMBER OF (partner_types)` lookups.
        DB::statement('CREATE INDEX partners_types_idx ON partners ((CAST(partner_types AS CHAR(64) ARRAY)))');
        DB::statement('CREATE INDEX partners_name_idx ON partners (name)');

        Schema::create('partner_contacts', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('partner_id');
            $t->string('name', 160);
            $t->string('role', 120)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('phone', 32)->nullable();
            $t->boolean('is_primary')->default(false);
            Ddl::timestamps($t);
            $t->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
        });

        Schema::create('document_attachments', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'document_type');
            $t->uuid('document_id');
            Ddl::enumColumn($t, 'kind', default: AttachmentKind::OTHER->value);
            $t->string('original_filename', 255);
            $t->string('private_path', 512);
            $t->string('mime_type', 120);
            $t->bigInteger('file_size');
            $t->string('checksum', 64);
            Ddl::enumColumn($t, 'classification', default: DataClassification::OPERATIONAL->value);
            $t->uuid('uploaded_by');
            Ddl::timestamps($t);
            $t->index(['document_type', 'document_id']);
        });
        Ddl::enumCheck('document_attachments', 'document_type', AttachableType::class);
        Ddl::enumCheck('document_attachments', 'kind', AttachmentKind::class);
        Ddl::enumCheck('document_attachments', 'classification', DataClassification::class);

        // persons.branch_id now that branches exist
        DB::statement('ALTER TABLE persons ADD CONSTRAINT fk_persons_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::table('persons', fn (Blueprint $t) => $t->dropForeign('fk_persons_branch'));
        foreach (['document_attachments', 'partner_contacts', 'partners', 'putaway_rules', 'inventory_locations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('warehouses', function (Blueprint $t) {
            foreach (['receiving', 'dispatch', 'quarantine', 'damaged', 'scrap', 'returns', 'packing', 'storage'] as $usage) {
                $t->dropForeign("fk_warehouses_default_{$usage}");
            }
        });
        // The storage-location triggers are dropped with their table.
        foreach (['storage_locations', 'warehouses', 'storage_categories', 'branches', 'company_profile'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
