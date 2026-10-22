<?php

use App\Domains\Platform\Enums\TemplateEngine;
use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deployments created before the template engine was renamed (blade → safe_html) kept the old CHECK
 * constraint, so the template seed could not insert any template (documents, e-mails). Re-apply the
 * constraint from the current enum; existing rows using the old name are converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Ddl::dropCheckIfExists('templates', Ddl::enumConstraintName('templates', 'engine'));
        DB::table('templates')->where('engine', 'blade')->update(['engine' => TemplateEngine::SAFE_HTML->value]);
        Ddl::enumCheck('templates', 'engine', TemplateEngine::class);
    }

    public function down(): void
    {
        // The constraint always follows the enum; nothing to undo.
    }
};
