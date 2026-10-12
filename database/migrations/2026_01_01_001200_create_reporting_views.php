<?php

use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One `reporting.<dataset>` view per catalog dataset (Chapter 13 §13.11), owned by the deployment
 * role and readable only by the reporting role. `platform:upgrade` re-runs installViews() when
 * dataset definitions change.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(DatasetCatalog::class)->installViews($this->getConnection());
    }

    public function down(): void
    {
        DB::statement('DROP SCHEMA IF EXISTS reporting CASCADE');
        DB::statement('CREATE SCHEMA reporting');
    }
};
