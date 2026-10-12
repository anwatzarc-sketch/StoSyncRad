<?php

use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\ApprovalValueBasis;
use App\Domains\IdentityAccess\Enums\AssignmentScopeType;
use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Enums\MfaStatus;
use App\Domains\IdentityAccess\Enums\PermissionGrantSource;
use App\Domains\IdentityAccess\Enums\PermissionScopeKind;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('full_name', 200);
            $t->string('employee_number', 40)->nullable()->unique();
            $t->text('national_id')->nullable();
            $t->string('national_id_hash', 64)->nullable()->unique();
            $t->string('email', 254)->nullable();
            $t->string('phone', 32)->nullable();
            $t->uuid('branch_id')->nullable()->index();
            $t->uuid('department_lookup_value_id')->nullable();
            $t->string('job_title', 120)->nullable();
            Pg::enumColumn($t, 'status', default: PersonStatus::ACTIVE->value);
            $t->timestampTz('archived_at')->nullable();
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->index('email');
        });
        Pg::enumCheck('persons', 'status', PersonStatus::class);
        DB::statement('CREATE INDEX persons_full_name_trgm ON persons USING gin (full_name gin_trgm_ops)');

        Schema::create('user_accounts', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('person_id');
            $t->string('username', 64);
            $t->string('email', 254);
            $t->string('password_hash', 255)->nullable();
            Pg::enumColumn($t, 'account_type', default: AccountType::HUMAN->value);
            Pg::enumColumn($t, 'account_status', default: AccountStatus::PENDING_ACTIVATION->value);
            $t->boolean('is_super_admin')->default(false);
            Pg::enumColumn($t, 'mfa_status', default: MfaStatus::NOT_ENROLLED->value);
            $t->timestampTz('password_changed_at')->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->timestampTz('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->unsignedInteger('failed_login_count')->default(0);
            $t->timestampTz('locked_until')->nullable();
            $t->timestampTz('deactivated_at')->nullable();
            $t->uuid('deactivated_by')->nullable();
            $t->rememberToken();
            // Generated marker maintained by the accounts-per-person policy (Chapter 04 §4.1).
            $t->boolean('single_policy_marker')->default(true);
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->foreign('person_id')->references('id')->on('persons')->restrictOnDelete();
            $t->index('person_id');
        });
        Pg::enumCheck('user_accounts', 'account_type', AccountType::class);
        Pg::enumCheck('user_accounts', 'account_status', AccountStatus::class);
        Pg::enumCheck('user_accounts', 'mfa_status', MfaStatus::class);
        DB::statement('CREATE UNIQUE INDEX user_accounts_username_ci_uq ON user_accounts (lower(username))');
        DB::statement('CREATE UNIQUE INDEX user_accounts_email_ci_uq ON user_accounts (lower(email))');
        DB::statement("CREATE UNIQUE INDEX user_accounts_single_human_per_person ON user_accounts (person_id) WHERE account_type = 'human' AND account_status <> 'deactivated' AND single_policy_marker = true");

        Schema::create('account_credentials_history', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->string('password_hash', 255);
            $t->timestampTz('created_at');
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->index(['user_account_id', 'created_at']);
        });

        Schema::create('mfa_credentials', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('user_account_id')->unique();
            $t->text('secret');
            $t->jsonb('recovery_code_hashes')->default('[]');
            $t->timestampTz('confirmed_at')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->unsignedBigInteger('last_used_counter')->nullable();
            Pg::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
        });

        Schema::create('trusted_devices', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->string('token_hash', 128)->unique();
            $t->string('label', 120)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('last_seen_at')->nullable();
            Pg::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->index(['user_account_id', 'expires_at']);
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->uuid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestampTz('created_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuidMorphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable()->index();
            Pg::timestamps($t);
        });

        Schema::create('permissions', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 120)->unique();
            $t->string('module', 40);
            $t->string('resource', 60);
            $t->string('action', 60);
            $t->string('label', 160);
            $t->text('description');
            Pg::enumColumn($t, 'scope_kind', default: PermissionScopeKind::GLOBAL->value);
            Pg::enumColumn($t, 'sensitivity', default: PermissionSensitivity::NORMAL->value);
            $t->boolean('is_virtual')->default(false);
            $t->boolean('is_deprecated')->default(false);
            $t->string('feature_flag', 80)->nullable();
            Pg::timestamps($t);
            $t->index(['module', 'resource']);
        });
        Pg::enumCheck('permissions', 'scope_kind', PermissionScopeKind::class);
        Pg::enumCheck('permissions', 'sensitivity', PermissionSensitivity::class);

        Schema::create('permission_implications', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('permission_id');
            $t->uuid('implies_permission_id');
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->foreign('implies_permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->unique(['permission_id', 'implies_permission_id']);
        });

        Schema::create('permission_endpoint_bindings', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('permission_id');
            $t->string('http_method', 10);
            $t->string('route_name', 160)->nullable();
            $t->string('uri_pattern', 255);
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->unique(['permission_id', 'http_method', 'uri_pattern']);
        });

        Schema::create('roles', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 64)->unique();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_template_origin')->default(false);
            Pg::enumColumn($t, 'status', default: RoleStatus::ACTIVE->value);
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });
        Pg::enumCheck('roles', 'status', RoleStatus::class);

        Schema::create('role_permissions', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('role_id');
            $t->uuid('permission_id');
            $t->string('permission_code', 120);
            Pg::enumColumn($t, 'granted_via', default: PermissionGrantSource::DIRECT->value);
            $t->jsonb('constraints')->nullable();
            Pg::timestamps($t);
            $t->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->unique(['role_id', 'permission_id']);
            $t->index('permission_code');
        });
        Pg::enumCheck('role_permissions', 'granted_via', PermissionGrantSource::class);

        Schema::create('role_assignments', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->uuid('role_id');
            Pg::enumColumn($t, 'scope_type', default: AssignmentScopeType::COMPANY->value);
            $t->uuid('scope_id')->nullable();
            $t->timestampTz('effective_from')->nullable();
            $t->timestampTz('effective_to')->nullable();
            $t->uuid('assigned_by')->nullable();
            $t->text('reason')->nullable();
            Pg::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $t->index('user_account_id');
            $t->index(['scope_type', 'scope_id']);
        });
        Pg::enumCheck('role_assignments', 'scope_type', AssignmentScopeType::class);
        Pg::check('role_assignments', 'chk_role_assignments_scope_id', "(scope_type = 'company' AND scope_id IS NULL) OR (scope_type <> 'company' AND scope_id IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX role_assignments_identity_uq ON role_assignments (user_account_id, role_id, scope_type, COALESCE(scope_id, \'00000000-0000-7000-8000-000000000000\'::uuid))');

        Schema::create('sod_rules', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('code', 80)->unique();
            Pg::enumColumn($t, 'document_type');
            Pg::enumColumn($t, 'first_action');
            Pg::enumColumn($t, 'second_action');
            Pg::enumColumn($t, 'rule', default: SodRuleType::DIFFERENT_ACTOR_REQUIRED->value);
            $t->jsonb('threshold')->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            Pg::actorColumns($t);
            Pg::timestamps($t);
        });
        Pg::enumCheck('sod_rules', 'document_type', DocumentType::class);
        Pg::enumCheck('sod_rules', 'rule', SodRuleType::class);
        Pg::enumCheck('sod_rules', 'first_action', DocumentAction::class);
        Pg::enumCheck('sod_rules', 'second_action', DocumentAction::class);
        Pg::check('sod_rules', 'chk_sod_rules_distinct_actions', 'first_action <> second_action');
        DB::statement('CREATE UNIQUE INDEX sod_rules_identity_uq ON sod_rules (document_type, first_action, second_action)');

        Schema::create('approval_policies', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'document_type');
            Pg::enumColumn($t, 'value_basis', default: ApprovalValueBasis::DOCUMENT_TOTAL->value);
            $t->string('currency', 3)->nullable();
            $t->jsonb('tiers');
            $t->boolean('is_active')->default(true);
            Pg::actorColumns($t);
            Pg::timestamps($t);
            $t->unique('document_type');
        });
        Pg::enumCheck('approval_policies', 'document_type', DocumentType::class);
        Pg::enumCheck('approval_policies', 'value_basis', ApprovalValueBasis::class);

        Schema::create('approval_records', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'document_type');
            $t->uuid('document_id');
            Pg::enumColumn($t, 'action');
            $t->uuid('actor_account_id');
            $t->text('comment')->nullable();
            $t->jsonb('limit_snapshot')->nullable();
            $t->unsignedSmallInteger('tier')->default(1);
            $t->uuid('correlation_id')->nullable();
            $t->timestampTz('acted_at');
            $t->index(['document_type', 'document_id', 'acted_at']);
            $t->index(['actor_account_id', 'acted_at']);
        });
        Pg::enumCheck('approval_records', 'document_type', DocumentType::class);
        Pg::enumCheck('approval_records', 'action', ApprovalAction::class);
        Pg::appendOnly('approval_records');
    }

    public function down(): void
    {
        foreach (['approval_records', 'approval_policies', 'sod_rules', 'role_assignments', 'role_permissions', 'roles',
            'permission_endpoint_bindings', 'permission_implications', 'permissions', 'personal_access_tokens',
            'password_reset_tokens', 'sessions', 'trusted_devices', 'mfa_credentials', 'account_credentials_history',
            'user_accounts', 'persons'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
