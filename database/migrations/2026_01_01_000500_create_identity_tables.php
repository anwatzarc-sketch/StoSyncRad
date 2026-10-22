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
use App\Support\Database\Ddl;
use App\Support\Sentinel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('full_name', 200);
            $t->string('employee_number', 40)->nullable()->unique();
            $t->text('national_id')->nullable();
            $t->string('national_id_hash', 64)->nullable()->unique();
            $t->string('email', 254)->nullable();
            $t->string('phone', 32)->nullable();
            $t->uuid('branch_id')->nullable()->index();
            $t->uuid('department_lookup_value_id')->nullable();
            $t->string('job_title', 120)->nullable();
            Ddl::enumColumn($t, 'status', default: PersonStatus::ACTIVE->value);
            $t->dateTime('archived_at', 6)->nullable();
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->index('email');
        });
        Ddl::enumCheck('persons', 'status', PersonStatus::class);
        // Name search is a case-insensitive LIKE '%…%' scan on MySQL; this index serves sorting and prefixes.
        DB::statement('CREATE INDEX persons_full_name_idx ON persons (full_name)');

        Schema::create('user_accounts', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('person_id');
            $t->string('username', 64);
            $t->string('email', 254);
            $t->string('password_hash', 255)->nullable();
            Ddl::enumColumn($t, 'account_type', default: AccountType::HUMAN->value);
            Ddl::enumColumn($t, 'account_status', default: AccountStatus::PENDING_ACTIVATION->value);
            $t->boolean('is_super_admin')->default(false);
            Ddl::enumColumn($t, 'mfa_status', default: MfaStatus::NOT_ENROLLED->value);
            $t->dateTime('password_changed_at', 6)->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->dateTime('last_login_at', 6)->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->integer('failed_login_count')->default(0);
            $t->dateTime('locked_until', 6)->nullable();
            $t->dateTime('deactivated_at', 6)->nullable();
            $t->uuid('deactivated_by')->nullable();
            $t->rememberToken();
            // Generated marker maintained by the accounts-per-person policy (Chapter 04 §4.1).
            $t->boolean('single_policy_marker')->default(true);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->foreign('person_id')->references('id')->on('persons')->restrictOnDelete();
            $t->index('person_id');
        });
        Ddl::enumCheck('user_accounts', 'account_type', AccountType::class);
        Ddl::enumCheck('user_accounts', 'account_status', AccountStatus::class);
        Ddl::enumCheck('user_accounts', 'mfa_status', MfaStatus::class);
        Ddl::uniqueExpression('user_accounts', 'user_accounts_username_ci_uq', 'lower(username)');
        Ddl::uniqueExpression('user_accounts', 'user_accounts_email_ci_uq', 'lower(email)');
        Ddl::uniqueExpression('user_accounts', 'user_accounts_single_human_per_person',
            "CASE WHEN account_type = 'human' AND account_status <> 'deactivated' AND single_policy_marker THEN person_id END");

        Schema::create('account_credentials_history', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->string('password_hash', 255);
            $t->dateTime('created_at', 6);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->index(['user_account_id', 'created_at']);
        });

        Schema::create('mfa_credentials', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id')->unique();
            $t->text('secret');
            $t->json('recovery_code_hashes')->default(new Expression('(JSON_ARRAY())'));
            $t->dateTime('confirmed_at', 6)->nullable();
            $t->dateTime('last_used_at', 6)->nullable();
            $t->bigInteger('last_used_counter')->nullable();
            Ddl::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
        });

        Schema::create('trusted_devices', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->string('token_hash', 128)->unique();
            $t->string('label', 120)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('expires_at', 6);
            $t->dateTime('last_seen_at', 6)->nullable();
            Ddl::timestamps($t);
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
            $t->dateTime('created_at', 6)->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuidMorphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->dateTime('last_used_at', 6)->nullable();
            $t->dateTime('expires_at', 6)->nullable()->index();
            Ddl::timestamps($t);
        });

        Schema::create('permissions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 120)->unique();
            $t->string('module', 40);
            $t->string('resource', 60);
            $t->string('action', 60);
            $t->string('label', 160);
            $t->text('description');
            Ddl::enumColumn($t, 'scope_kind', default: PermissionScopeKind::GLOBAL->value);
            Ddl::enumColumn($t, 'sensitivity', default: PermissionSensitivity::NORMAL->value);
            $t->boolean('is_virtual')->default(false);
            $t->boolean('is_deprecated')->default(false);
            $t->string('feature_flag', 80)->nullable();
            Ddl::timestamps($t);
            $t->index(['module', 'resource']);
        });
        Ddl::enumCheck('permissions', 'scope_kind', PermissionScopeKind::class);
        Ddl::enumCheck('permissions', 'sensitivity', PermissionSensitivity::class);

        Schema::create('permission_implications', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('permission_id');
            $t->uuid('implies_permission_id');
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->foreign('implies_permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->unique(['permission_id', 'implies_permission_id']);
        });

        Schema::create('permission_endpoint_bindings', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('permission_id');
            $t->string('http_method', 10);
            $t->string('route_name', 160)->nullable();
            $t->string('uri_pattern', 255);
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->unique(['permission_id', 'http_method', 'uri_pattern']);
        });

        Schema::create('roles', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 64)->unique();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_template_origin')->default(false);
            Ddl::enumColumn($t, 'status', default: RoleStatus::ACTIVE->value);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('roles', 'status', RoleStatus::class);

        Schema::create('role_permissions', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('role_id');
            $t->uuid('permission_id');
            $t->string('permission_code', 120);
            Ddl::enumColumn($t, 'granted_via', default: PermissionGrantSource::DIRECT->value);
            $t->json('constraints')->nullable();
            Ddl::timestamps($t);
            $t->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $t->foreign('permission_id')->references('id')->on('permissions')->restrictOnDelete();
            $t->unique(['role_id', 'permission_id']);
            $t->index('permission_code');
        });
        Ddl::enumCheck('role_permissions', 'granted_via', PermissionGrantSource::class);

        Schema::create('role_assignments', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->uuid('user_account_id');
            $t->uuid('role_id');
            Ddl::enumColumn($t, 'scope_type', default: AssignmentScopeType::COMPANY->value);
            $t->uuid('scope_id')->nullable();
            $t->dateTime('effective_from', 6)->nullable();
            $t->dateTime('effective_to', 6)->nullable();
            $t->uuid('assigned_by')->nullable();
            $t->text('reason')->nullable();
            Ddl::timestamps($t);
            $t->foreign('user_account_id')->references('id')->on('user_accounts')->restrictOnDelete();
            $t->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $t->index('user_account_id');
            $t->index(['scope_type', 'scope_id']);
        });
        Ddl::enumCheck('role_assignments', 'scope_type', AssignmentScopeType::class);
        Ddl::check('role_assignments', 'chk_role_assignments_scope_id', "(scope_type = 'company' AND scope_id IS NULL) OR (scope_type <> 'company' AND scope_id IS NOT NULL)");
        Ddl::uniqueIndex('role_assignments', 'role_assignments_identity_uq',
            "user_account_id, role_id, scope_type, (COALESCE(scope_id, '".Sentinel::UUID."'))");

        Schema::create('sod_rules', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('code', 80)->unique();
            Ddl::enumColumn($t, 'document_type');
            Ddl::enumColumn($t, 'first_action');
            Ddl::enumColumn($t, 'second_action');
            Ddl::enumColumn($t, 'rule', default: SodRuleType::DIFFERENT_ACTOR_REQUIRED->value);
            $t->json('threshold')->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
        });
        Ddl::enumCheck('sod_rules', 'document_type', DocumentType::class);
        Ddl::enumCheck('sod_rules', 'rule', SodRuleType::class);
        Ddl::enumCheck('sod_rules', 'first_action', DocumentAction::class);
        Ddl::enumCheck('sod_rules', 'second_action', DocumentAction::class);
        Ddl::check('sod_rules', 'chk_sod_rules_distinct_actions', 'first_action <> second_action');
        DB::statement('CREATE UNIQUE INDEX sod_rules_identity_uq ON sod_rules (document_type, first_action, second_action)');

        Schema::create('approval_policies', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'document_type');
            Ddl::enumColumn($t, 'value_basis', default: ApprovalValueBasis::DOCUMENT_TOTAL->value);
            $t->string('currency', 3)->nullable();
            $t->json('tiers');
            $t->boolean('is_active')->default(true);
            Ddl::actorColumns($t);
            Ddl::timestamps($t);
            $t->unique('document_type');
        });
        Ddl::enumCheck('approval_policies', 'document_type', DocumentType::class);
        Ddl::enumCheck('approval_policies', 'value_basis', ApprovalValueBasis::class);

        Schema::create('approval_records', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'document_type');
            $t->uuid('document_id');
            Ddl::enumColumn($t, 'action');
            $t->uuid('actor_account_id');
            $t->text('comment')->nullable();
            $t->json('limit_snapshot')->nullable();
            $t->smallInteger('tier')->default(1);
            $t->uuid('correlation_id')->nullable();
            $t->dateTime('acted_at', 6);
            $t->index(['document_type', 'document_id', 'acted_at']);
            $t->index(['actor_account_id', 'acted_at']);
        });
        Ddl::enumCheck('approval_records', 'document_type', DocumentType::class);
        Ddl::enumCheck('approval_records', 'action', ApprovalAction::class);
        Ddl::appendOnly('approval_records');
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
