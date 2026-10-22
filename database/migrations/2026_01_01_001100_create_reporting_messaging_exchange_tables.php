<?php

use App\Domains\DataExchange\Enums\ExportFileKind;
use App\Domains\DataExchange\Enums\ImportBatchStatus;
use App\Domains\DataExchange\Enums\ImportRowStatus;
use App\Domains\DataExchange\Enums\ImportType;
use App\Domains\Messaging\Enums\NotificationChannel;
use App\Domains\Messaging\Enums\NotificationStatus;
use App\Domains\Messaging\Enums\WebhookDeliveryStatus;
use App\Domains\ReportingEngine\Enums\ReportFieldType;
use App\Domains\ReportingEngine\Enums\ReportOutputFormat;
use App\Domains\ReportingEngine\Enums\ReportRelationshipCardinality;
use App\Domains\ReportingEngine\Enums\ReportRunStatus;
use App\Domains\ReportingEngine\Enums\ReportSlotKind;
use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Support\Database\Ddl;
use App\Support\Enums\DataClassification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting engine metadata (Chapter 10), messaging (Chapter 11 §11.7) and data exchange
 * (Chapter 11 §11.9–11.10). Reporting views live in the `reporting` schema (next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->reporting();
        $this->messaging();
        $this->exchange();
    }

    private function reporting(): void
    {
        Schema::create('report_datasets', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 80)->unique();
            $t->string('label', 160);
            $t->text('description')->nullable();
            $t->string('source_view', 120);
            $t->string('primary_key', 80);
            $t->string('warehouse_scope_column', 80)->nullable();
            $t->string('branch_scope_column', 80)->nullable();
            $t->string('own_records_column', 80)->nullable();
            Ddl::enumColumn($t, 'classification', default: DataClassification::OPERATIONAL->value);
            $t->string('required_permission', 120);
            $t->string('feature_flag', 120)->nullable();
            $t->json('default_sort')->nullable();
            $t->string('module', 40);
            $t->boolean('is_active')->default(true);
            $t->string('definition_hash', 64);
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('report_datasets', 'classification', DataClassification::class);

        Schema::create('report_dataset_fields', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('report_dataset_id');
            $t->string('code', 80);
            $t->string('label', 160);
            Ddl::enumColumn($t, 'field_type');
            $t->string('enum_code', 120)->nullable();
            Ddl::enumColumn($t, 'classification', default: DataClassification::OPERATIONAL->value);
            $t->string('required_permission', 120)->nullable();
            $t->boolean('is_aggregatable')->default(false);
            $t->boolean('is_groupable')->default(true);
            $t->boolean('is_filterable')->default(true);
            $t->boolean('is_sortable')->default(true);
            $t->string('drill_target', 120)->nullable();
            $t->integer('sort_order')->default(0);
            Ddl::timestamps($t);
            $t->foreign('report_dataset_id')->references('id')->on('report_datasets')->cascadeOnDelete();
            $t->unique(['report_dataset_id', 'code']);
        });
        Ddl::enumCheck('report_dataset_fields', 'field_type', ReportFieldType::class);
        Ddl::enumCheck('report_dataset_fields', 'classification', DataClassification::class);

        Schema::create('report_dataset_relationships', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('report_dataset_id');
            $t->string('code', 80);
            $t->string('label', 160);
            $t->string('target_dataset_code', 80);
            $t->json('join_keys');
            Ddl::enumColumn($t, 'cardinality', default: ReportRelationshipCardinality::MANY_TO_ONE->value);
            Ddl::timestamps($t);
            $t->foreign('report_dataset_id')->references('id')->on('report_datasets')->cascadeOnDelete();
            $t->unique(['report_dataset_id', 'code']);
        });
        Ddl::enumCheck('report_dataset_relationships', 'cardinality', ReportRelationshipCardinality::class);

        Schema::create('report_definitions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 120)->unique();
            $t->string('name', 200);
            $t->text('description')->nullable();
            $t->uuid('owner_account_id')->nullable();
            Ddl::enumColumn($t, 'visibility', default: ReportVisibility::PRIVATE->value);
            $t->string('dataset_code', 80);
            $t->json('definition');
            $t->integer('definition_version')->default(1);
            $t->integer('schema_version')->default(1);
            $t->boolean('is_system_seed')->default(false);
            $t->boolean('is_active')->default(true);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->index(['owner_account_id', 'is_active']);
            $t->index('dataset_code');
        });
        Ddl::enumCheck('report_definitions', 'visibility', ReportVisibility::class);
        Ddl::jsonArray('report_definitions', 'shared_role_ids', ['type' => 'string', 'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$']);
        Ddl::jsonArray('report_definitions', 'tags', ['type' => 'string', 'maxLength' => 60]);

        Schema::create('report_definition_versions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('report_definition_id');
            $t->integer('definition_version');
            $t->string('name', 200);
            $t->json('definition');
            $t->uuid('changed_by')->nullable();
            $t->text('change_note')->nullable();
            $t->dateTime('created_at', 6);
            $t->foreign('report_definition_id')->references('id')->on('report_definitions')->restrictOnDelete();
            $t->unique(['report_definition_id', 'definition_version']);
        });
        Ddl::appendOnly('report_definition_versions');

        Schema::create('report_runs', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('report_definition_id')->nullable();
            $t->integer('definition_version')->nullable();
            $t->string('dataset_code', 80);
            $t->uuid('runner_account_id');
            Ddl::enumColumn($t, 'output_format', default: ReportOutputFormat::WEB->value);
            $t->json('parameters')->nullable();
            $t->json('authorized_scope_snapshot')->nullable();
            Ddl::enumColumn($t, 'status', default: ReportRunStatus::QUEUED->value);
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->integer('duration_ms')->nullable();
            $t->bigInteger('row_count')->nullable();
            $t->boolean('truncated')->default(false);
            $t->string('file_path', 400)->nullable();
            $t->bigInteger('file_size')->nullable();
            $t->dateTime('file_expires_at', 6)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->text('error_message')->nullable();
            $t->integer('backend_pid')->nullable();
            $t->uuid('correlation_id')->nullable();
            Ddl::timestamps($t);
            $t->index(['runner_account_id', 'started_at']);
            $t->index(['status', 'file_expires_at']);
        });
        Ddl::enumCheck('report_runs', 'output_format', ReportOutputFormat::class);
        Ddl::enumCheck('report_runs', 'status', ReportRunStatus::class);

        Schema::create('report_execution_slots', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'kind');
            $t->uuid('report_run_id')->nullable();
            $t->dateTime('acquired_at', 6);
            $t->dateTime('expires_at', 6);
            $t->index(['kind', 'expires_at']);
        });
        Ddl::enumCheck('report_execution_slots', 'kind', ReportSlotKind::class);

        // The reporting account reads only the views in the companion <db>_reporting database
        // (Chapter 13 §13.11), which database/mysql/setup.sql creates and grants.
    }

    private function messaging(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->string('event_type', 120);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->string('link', 400)->nullable();
            $t->json('data')->nullable();
            Ddl::enumColumn($t, 'status', default: NotificationStatus::UNREAD->value);
            $t->uuid('outbox_event_id')->nullable();
            $t->dateTime('read_at', 6)->nullable();
            Ddl::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->index(['user_account_id', 'status', 'created_at']);
            $t->unique(['user_account_id', 'outbox_event_id']);
        });
        Ddl::enumCheck('notifications', 'status', NotificationStatus::class);

        Schema::create('notification_preferences', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->string('event_type', 120);
            $t->boolean('is_muted')->default(false);
            Ddl::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->unique(['user_account_id', 'event_type']);
        });
        Ddl::enumArray('notification_preferences', 'channels', NotificationChannel::class);

        Schema::create('webhook_endpoints', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('name', 120);
            $t->string('url', 500);
            $t->text('secret_encrypted');
            $t->boolean('is_active')->default(true);
            $t->integer('consecutive_failures')->default(0);
            $t->dateTime('last_success_at', 6)->nullable();
            $t->dateTime('disabled_at', 6)->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
        });
        Ddl::jsonArray('webhook_endpoints', 'event_types', ['type' => 'string', 'maxLength' => 120]);

        Schema::create('webhook_deliveries', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('webhook_endpoint_id');
            $t->uuid('outbox_event_id');
            Ddl::enumColumn($t, 'status', default: WebhookDeliveryStatus::PENDING->value);
            $t->integer('attempts')->default(0);
            $t->dateTime('next_attempt_at', 6);
            $t->smallInteger('last_status_code')->nullable();
            $t->text('last_error')->nullable();
            $t->dateTime('delivered_at', 6)->nullable();
            Ddl::timestamps($t);
            $t->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->restrictOnDelete();
            $t->foreign('outbox_event_id')->references('id')->on('outbox_events')->restrictOnDelete();
            $t->unique(['webhook_endpoint_id', 'outbox_event_id']);
            $t->index(['status', 'next_attempt_at']);
        });
        Ddl::enumCheck('webhook_deliveries', 'status', WebhookDeliveryStatus::class);
    }

    private function exchange(): void
    {
        Schema::create('import_batches', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'import_type');
            Ddl::enumColumn($t, 'status', default: ImportBatchStatus::UPLOADED->value);
            $t->string('file_path', 400);
            $t->string('original_filename', 255);
            $t->string('file_checksum', 64);
            $t->integer('row_count')->default(0);
            $t->integer('valid_count')->default(0);
            $t->integer('invalid_count')->default(0);
            $t->integer('imported_count')->default(0);
            $t->json('options')->nullable();
            $t->uuid('confirmed_by')->nullable();
            $t->dateTime('confirmed_at', 6)->nullable();
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->text('error')->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->index(['import_type', 'status']);
        });
        Ddl::enumCheck('import_batches', 'import_type', ImportType::class);
        Ddl::enumCheck('import_batches', 'status', ImportBatchStatus::class);

        Schema::create('import_rows', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('import_batch_id');
            $t->integer('row_number');
            $t->json('data');
            Ddl::enumColumn($t, 'status', default: ImportRowStatus::VALID->value);
            $t->json('errors')->nullable();
            $t->uuid('result_id')->nullable();
            Ddl::timestamps($t);
            $t->foreign('import_batch_id')->references('id')->on('import_batches')->restrictOnDelete();
            $t->unique(['import_batch_id', 'row_number']);
            $t->index(['import_batch_id', 'status']);
        });
        Ddl::enumCheck('import_rows', 'status', ImportRowStatus::class);

        Schema::create('export_files', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'kind');
            $t->string('disk', 40)->default('private');
            $t->string('path', 400);
            $t->string('filename', 255);
            $t->string('mime_type', 120);
            $t->bigInteger('size')->default(0);
            $t->uuid('owner_account_id');
            $t->uuid('report_run_id')->nullable();
            $t->dateTime('expires_at', 6);
            $t->dateTime('last_downloaded_at', 6)->nullable();
            $t->integer('download_count')->default(0);
            Ddl::timestamps($t);
            $t->index(['owner_account_id', 'expires_at']);
        });
        Ddl::enumCheck('export_files', 'kind', ExportFileKind::class);
    }

    public function down(): void
    {
        foreach (['export_files', 'import_rows', 'import_batches', 'webhook_deliveries', 'webhook_endpoints', 'notification_preferences', 'notifications',
            'report_execution_slots', 'report_runs', 'report_definition_versions', 'report_definitions', 'report_dataset_relationships',
            'report_dataset_fields', 'report_datasets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
