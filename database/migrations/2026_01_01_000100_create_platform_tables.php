<?php

use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\SequenceResetPolicy;
use App\Domains\Organization\Enums\SequenceScope;
use App\Domains\Platform\Config\ConfigScopeType;
use App\Domains\Platform\Config\ConfigValueType;
use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Enums\SeedRunStatus;
use App\Domains\Platform\Enums\TemplateEngine;
use App\Support\Database\Ddl;
use App\Support\Sentinel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_setup', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('deployment_id');
            Ddl::enumColumn($t, 'state', default: PlatformSetupState::NOT_STARTED->value);
            Ddl::enumColumn($t, 'current_step', nullable: true);
            $t->json('completed_steps')->default(new Expression('(JSON_OBJECT())'));
            $t->string('bootstrap_token_hash', 128)->nullable();
            $t->dateTime('bootstrap_token_expires_at', 6)->nullable();
            $t->dateTime('completed_at', 6)->nullable();
            $t->uuid('completed_by_account_id')->nullable();
            $t->dateTime('locked_at', 6)->nullable();
            $t->dateTime('reopened_at', 6)->nullable();
            $t->string('config_bundle_checksum', 64)->nullable();
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('platform_setup', 'state', PlatformSetupState::class);
        Ddl::enumCheck('platform_setup', 'current_step', PlatformSetupStep::class);

        Schema::create('seed_runs', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('seed', 160);
            $t->string('version', 32);
            $t->string('checksum', 64);
            Ddl::enumColumn($t, 'status', default: SeedRunStatus::PENDING->value);
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->json('result')->nullable();
            $t->text('error')->nullable();
            Ddl::timestamps($t);
            $t->index(['seed', 'version']);
        });
        Ddl::enumCheck('seed_runs', 'status', SeedRunStatus::class);

        Schema::create('configuration_entries', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('key', 160);
            Ddl::enumColumn($t, 'scope_type', default: ConfigScopeType::GLOBAL->value);
            $t->uuid('scope_id')->nullable();
            $t->json('value');
            Ddl::enumColumn($t, 'value_type');
            $t->integer('version')->default(1);
            $t->dateTime('effective_from', 6)->nullable();
            $t->uuid('updated_by')->nullable();
            Ddl::timestamps($t);
            $t->unique(['key', 'scope_type', 'scope_id'], 'configuration_entries_key_scope_uq');
            $t->index(['scope_type', 'scope_id']);
        });
        Ddl::enumCheck('configuration_entries', 'scope_type', ConfigScopeType::class);
        Ddl::enumCheck('configuration_entries', 'value_type', ConfigValueType::class);
        Ddl::check('configuration_entries', 'chk_configuration_entries_scope_id', "(scope_type = 'global' AND scope_id IS NULL) OR (scope_type <> 'global' AND scope_id IS NOT NULL)");

        Schema::create('configuration_entry_versions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('configuration_entry_id');
            $t->string('key', 160);
            $t->string('scope_type', 64);
            $t->uuid('scope_id')->nullable();
            $t->json('value');
            $t->integer('version');
            $t->uuid('changed_by')->nullable();
            $t->text('reason')->nullable();
            $t->dateTime('created_at', 6);
            $t->index(['configuration_entry_id', 'version']);
            $t->index(['key', 'created_at']);
        });
        Ddl::appendOnly('configuration_entry_versions');

        Schema::create('brand_kits', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('app_name', 80);
            $t->string('app_short_name', 12);
            $t->string('tagline', 160)->nullable();
            $t->text('legal_footer')->nullable();
            $t->string('support_email', 254)->nullable();
            $t->string('support_phone', 32)->nullable();
            $t->string('support_url', 2048)->nullable();
            $t->json('theme');            // color tokens, font, radius, density, dark overrides
            $t->json('locale_defaults')->nullable();
            $t->integer('version')->default(1);
            $t->string('checksum', 64)->nullable();
            $t->string('imported_from', 255)->nullable();
            $t->boolean('is_active')->default(false);
            $t->dateTime('activated_at', 6)->nullable();
            $t->uuid('activated_by')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
        });
        Ddl::uniqueExpression('brand_kits', 'brand_kits_single_active_uq', 'CASE WHEN is_active THEN 1 END');   // at most one active kit

        Schema::create('brand_assets', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('brand_kit_id');
            Ddl::enumColumn($t, 'kind');
            $t->string('variant', 40)->nullable();   // e.g. icon-192, icon-512-maskable
            $t->string('path', 512);                // private disk path
            $t->string('public_path', 512)->nullable();
            $t->string('mime', 100);
            $t->string('checksum', 64);
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->bigInteger('bytes')->default(0);
            Ddl::timestamps($t);
            $t->foreign('brand_kit_id')->references('id')->on('brand_kits')->restrictOnDelete();
            $t->unique(['brand_kit_id', 'kind', 'variant']);
        });
        Ddl::enumCheck('brand_assets', 'kind', BrandAssetKind::class);

        Schema::create('templates', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 160)->unique();
            $t->string('name', 160);
            Ddl::enumColumn($t, 'engine');
            $t->text('body');
            $t->json('variables_schema')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('is_system_default')->default(false);
            $t->boolean('is_active')->default(true);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('templates', 'engine', TemplateEngine::class);

        Schema::create('template_versions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('template_id');
            $t->integer('version');
            $t->text('body');
            $t->json('variables_schema')->nullable();
            $t->uuid('changed_by')->nullable();
            $t->dateTime('created_at', 6);
            $t->foreign('template_id')->references('id')->on('templates')->restrictOnDelete();
            $t->unique(['template_id', 'version']);
        });
        Ddl::appendOnly('template_versions');

        Schema::create('lookups', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 80)->unique();
            $t->string('label', 160);
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            Ddl::timestamps($t);
        });

        Schema::create('lookup_values', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('lookup_id');
            $t->string('code', 80);
            $t->string('label', 160);
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->json('metadata')->nullable();
            Ddl::timestamps($t);
            $t->foreign('lookup_id')->references('id')->on('lookups')->restrictOnDelete();
            $t->unique(['lookup_id', 'code']);
        });

        Schema::create('enum_labels', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('enum_code', 120);
            $t->string('case_value', 64);
            $t->string('locale', 12);
            $t->string('label', 160);
            $t->text('description')->nullable();
            $t->uuid('updated_by')->nullable();
            Ddl::timestamps($t);
            $t->unique(['enum_code', 'case_value', 'locale']);
        });

        Schema::create('translation_overrides', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('locale', 12);
            $t->string('key', 255);
            $t->text('value');
            $t->uuid('updated_by')->nullable();
            Ddl::timestamps($t);
            $t->unique(['locale', 'key']);
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'document_type');
            Ddl::enumColumn($t, 'scope', default: SequenceScope::GLOBAL->value);
            // Sentinel (not NULL) for global scope so the identity unique index is total.
            $t->uuid('scope_id')->default(Sentinel::UUID);
            // '' = template row (Setup Step 10); otherwise the counter period ("2026", "2026-03", "*").
            $t->string('period_key', 16)->default('');
            $t->string('prefix', 16);
            $t->string('pattern', 80);                    // e.g. "{PREFIX}-{YYYY}-{SEQ:6}"
            Ddl::enumColumn($t, 'reset_policy', default: SequenceResetPolicy::NEVER->value);
            $t->bigInteger('next_number')->default(1);
            $t->integer('sequence_padding')->default(6);
            Ddl::timestamps($t);
            $t->unique(['document_type', 'scope', 'scope_id', 'period_key'], 'document_sequences_identity_uq');
        });
        Ddl::enumCheck('document_sequences', 'document_type', DocumentType::class);
        Ddl::enumCheck('document_sequences', 'scope', SequenceScope::class);
        Ddl::enumCheck('document_sequences', 'reset_policy', SequenceResetPolicy::class);

        // Transaction-scoped named locks (App\Support\Database\AdvisoryLock). Rows are never deleted.
        Schema::create('advisory_locks', function (Blueprint $t) {
            $t->string('lock_key', 191)->charset('ascii')->collation('ascii_bin')->primary();
            $t->dateTime('created_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['advisory_locks', 'document_sequences', 'translation_overrides', 'enum_labels', 'lookup_values', 'lookups',
            'template_versions', 'templates', 'brand_assets', 'brand_kits', 'configuration_entry_versions',
            'configuration_entries', 'seed_runs', 'platform_setup'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
