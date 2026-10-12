<?php

use App\Domains\Platform\Config\ConfigScopeType;
use App\Domains\Platform\Config\ConfigValueType;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Enums\SeedRunStatus;
use App\Domains\Platform\Enums\TemplateEngine;
use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\SequenceResetPolicy;
use App\Domains\Organization\Enums\SequenceScope;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_setup', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('deployment_id');
            Pg::enumColumn($t, 'state', default: PlatformSetupState::NOT_STARTED->value);
            Pg::enumColumn($t, 'current_step', nullable: true);
            $t->jsonb('completed_steps')->default('{}');
            $t->string('bootstrap_token_hash', 128)->nullable();
            $t->timestampTz('bootstrap_token_expires_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->uuid('completed_by_account_id')->nullable();
            $t->timestampTz('locked_at')->nullable();
            $t->timestampTz('reopened_at')->nullable();
            $t->string('config_bundle_checksum', 64)->nullable();
            Pg::timestamps($t);
        });
        Pg::enumCheck('platform_setup', 'state', PlatformSetupState::class);
        Pg::enumCheck('platform_setup', 'current_step', PlatformSetupStep::class);

        Schema::create('seed_runs', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('seed', 160);
            $t->string('version', 32);
            $t->string('checksum', 64);
            Pg::enumColumn($t, 'status', default: SeedRunStatus::PENDING->value);
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->jsonb('result')->nullable();
            $t->text('error')->nullable();
            Pg::timestamps($t);
            $t->index(['seed', 'version']);
        });
        Pg::enumCheck('seed_runs', 'status', SeedRunStatus::class);

        Schema::create('configuration_entries', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('key', 160);
            Pg::enumColumn($t, 'scope_type', default: ConfigScopeType::GLOBAL->value);
            $t->uuid('scope_id')->nullable();
            $t->jsonb('value');
            Pg::enumColumn($t, 'value_type');
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('effective_from')->nullable();
            $t->uuid('updated_by')->nullable();
            Pg::timestamps($t);
            $t->unique(['key', 'scope_type', 'scope_id'], 'configuration_entries_key_scope_uq');
            $t->index(['scope_type', 'scope_id']);
        });
        Pg::enumCheck('configuration_entries', 'scope_type', ConfigScopeType::class);
        Pg::enumCheck('configuration_entries', 'value_type', ConfigValueType::class);
        Pg::check('configuration_entries', 'chk_configuration_entries_scope_id', "(scope_type = 'global' AND scope_id IS NULL) OR (scope_type <> 'global' AND scope_id IS NOT NULL)");

        Schema::create('configuration_entry_versions', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('configuration_entry_id');
            $t->string('key', 160);
            $t->string('scope_type', 64);
            $t->uuid('scope_id')->nullable();
            $t->jsonb('value');
            $t->unsignedInteger('version');
            $t->uuid('changed_by')->nullable();
            $t->text('reason')->nullable();
            $t->timestampTz('created_at');
            $t->index(['configuration_entry_id', 'version']);
            $t->index(['key', 'created_at']);
        });
        Pg::appendOnly('configuration_entry_versions');

        Schema::create('brand_kits', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('app_name', 80);
            $t->string('app_short_name', 12);
            $t->string('tagline', 160)->nullable();
            $t->text('legal_footer')->nullable();
            $t->string('support_email', 254)->nullable();
            $t->string('support_phone', 32)->nullable();
            $t->string('support_url', 2048)->nullable();
            $t->jsonb('theme');            // color tokens, font, radius, density, dark overrides
            $t->jsonb('locale_defaults')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('checksum', 64)->nullable();
            $t->string('imported_from', 255)->nullable();
            $t->boolean('is_active')->default(false);
            $t->timestampTz('activated_at')->nullable();
            $t->uuid('activated_by')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });
        \Illuminate\Support\Facades\DB::statement('CREATE UNIQUE INDEX brand_kits_single_active_uq ON brand_kits (is_active) WHERE is_active = true');

        Schema::create('brand_assets', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('brand_kit_id');
            Pg::enumColumn($t, 'kind');
            $t->string('variant', 40)->nullable();   // e.g. icon-192, icon-512-maskable
            $t->string('path', 512);                // private disk path
            $t->string('public_path', 512)->nullable();
            $t->string('mime', 100);
            $t->string('checksum', 64);
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('bytes')->default(0);
            Pg::timestamps($t);
            $t->foreign('brand_kit_id')->references('id')->on('brand_kits')->restrictOnDelete();
            $t->unique(['brand_kit_id', 'kind', 'variant']);
        });
        Pg::enumCheck('brand_assets', 'kind', BrandAssetKind::class);

        Schema::create('templates', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 160)->unique();
            $t->string('name', 160);
            Pg::enumColumn($t, 'engine');
            $t->text('body');
            $t->jsonb('variables_schema')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_system_default')->default(false);
            $t->boolean('is_active')->default(true);
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });
        Pg::enumCheck('templates', 'engine', TemplateEngine::class);

        Schema::create('template_versions', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('template_id');
            $t->unsignedInteger('version');
            $t->text('body');
            $t->jsonb('variables_schema')->nullable();
            $t->uuid('changed_by')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('template_id')->references('id')->on('templates')->restrictOnDelete();
            $t->unique(['template_id', 'version']);
        });
        Pg::appendOnly('template_versions');

        Schema::create('lookups', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 80)->unique();
            $t->string('label', 160);
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            Pg::timestamps($t);
        });

        Schema::create('lookup_values', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('lookup_id');
            $t->string('code', 80);
            $t->string('label', 160);
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->jsonb('metadata')->nullable();
            Pg::timestamps($t);
            $t->foreign('lookup_id')->references('id')->on('lookups')->restrictOnDelete();
            $t->unique(['lookup_id', 'code']);
        });

        Schema::create('enum_labels', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('enum_code', 120);
            $t->string('case_value', 64);
            $t->string('locale', 12);
            $t->string('label', 160);
            $t->text('description')->nullable();
            $t->uuid('updated_by')->nullable();
            Pg::timestamps($t);
            $t->unique(['enum_code', 'case_value', 'locale']);
        });

        Schema::create('translation_overrides', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('locale', 12);
            $t->string('key', 255);
            $t->text('value');
            $t->uuid('updated_by')->nullable();
            Pg::timestamps($t);
            $t->unique(['locale', 'key']);
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'document_type');
            Pg::enumColumn($t, 'scope', default: SequenceScope::GLOBAL->value);
            // Sentinel (not NULL) for global scope so the identity unique index is total.
            $t->uuid('scope_id')->default(\App\Support\Sentinel::UUID);
            // '' = template row (Setup Step 10); otherwise the counter period ("2026", "2026-03", "*").
            $t->string('period_key', 16)->default('');
            $t->string('prefix', 16);
            $t->string('pattern', 80);                    // e.g. "{PREFIX}-{YYYY}-{SEQ:6}"
            Pg::enumColumn($t, 'reset_policy', default: SequenceResetPolicy::NEVER->value);
            $t->unsignedBigInteger('next_number')->default(1);
            $t->unsignedInteger('sequence_padding')->default(6);
            Pg::timestamps($t);
            $t->unique(['document_type', 'scope', 'scope_id', 'period_key'], 'document_sequences_identity_uq');
        });
        Pg::enumCheck('document_sequences', 'document_type', DocumentType::class);
        Pg::enumCheck('document_sequences', 'scope', SequenceScope::class);
        Pg::enumCheck('document_sequences', 'reset_policy', SequenceResetPolicy::class);
    }

    public function down(): void
    {
        foreach (['document_sequences', 'translation_overrides', 'enum_labels', 'lookup_values', 'lookups',
            'template_versions', 'templates', 'brand_assets', 'brand_kits', 'configuration_entry_versions',
            'configuration_entries', 'seed_runs', 'platform_setup'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
