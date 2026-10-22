<?php

use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * One view per catalog dataset in the companion `<db>_reporting` database (Chapter 13 §13.11),
 * defined by the deployment account and readable only by the reporting account. `platform:upgrade`
 * re-runs installViews() when dataset definitions change.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(DatasetCatalog::class)->installViews($this->getConnection());
    }

    public function down(): void
    {
        app(DatasetCatalog::class)->dropViews($this->getConnection());
    }
};
