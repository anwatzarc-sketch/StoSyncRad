<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Datasets;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\IdentityAccess\Enums\AssignmentScopeType;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\ReportingEngine\Catalog\DatasetDefinition as D;
use App\Domains\ReportingEngine\Catalog\DatasetField as F;
use App\Domains\ReportingEngine\Catalog\ProvidesDatasets;
use App\Domains\ReportingEngine\Enums\ReportOutputFormat;
use App\Domains\ReportingEngine\Enums\ReportRunStatus;
use App\Support\Enums\DataClassification as C;

/** Approvals, audit summaries (no before/after payloads), report run metadata, access review. */
final class AdministrationDatasets implements ProvidesDatasets
{
    public static function datasets(): array
    {
        return [
            new D('approval_records', 'Approval history', 'identity', <<<'SQL'
                SELECT a.id, a.document_type, a.document_id, a.action, a.tier, a.actor_account_id, u.username AS actor_username, a.comment, a.acted_at
                  FROM approval_records a LEFT JOIN user_accounts u ON u.id = a.actor_account_id
                SQL, [
                F::uuid('id', 'Record ID'), F::enum('document_type', 'Document type', DocumentType::class), F::uuid('document_id', 'Document ID'),
                F::enum('action', 'Action', ApprovalAction::class), F::int('tier', 'Tier'), F::uuid('actor_account_id', 'Actor ID'), F::personal('actor_username', 'Actor'),
                F::string('comment', 'Comment'), F::datetime('acted_at', 'When'),
            ], 'audit.approval.read', defaultSort: [['acted_at', 'desc']]),

            new D('audit_event_summaries', 'Audit events (summary)', 'audit', <<<'SQL'
                SELECT e.id, e.event_type, split_part(e.event_type, '.', 1) AS module, e.actor_type, e.actor_account_id, u.username AS actor_username,
                       e.subject_type, e.subject_id, e.reason, e.is_failed_attempt, e.correlation_id, e.occurred_at
                  FROM audit_events e LEFT JOIN user_accounts u ON u.id = e.actor_account_id
                SQL, [
                F::uuid('id', 'Event ID'), F::string('event_type', 'Event'), F::string('module', 'Module'), F::enum('actor_type', 'Actor type', AuditActorType::class),
                F::uuid('actor_account_id', 'Actor ID'), F::personal('actor_username', 'Actor'), F::string('subject_type', 'Subject type'), F::uuid('subject_id', 'Subject ID'),
                F::string('reason', 'Reason'), F::bool('is_failed_attempt', 'Failed attempt'), F::uuid('correlation_id', 'Correlation ID'), F::datetime('occurred_at', 'When'),
            ], 'audit.event.read', classification: C::PERSONAL_DATA, defaultSort: [['occurred_at', 'desc']]),

            new D('report_runs', 'Report runs (metadata)', 'reporting', <<<'SQL'
                SELECT r.id, r.report_definition_id, d.name AS report_name, r.dataset_code, r.runner_account_id, u.username AS runner_username, r.output_format,
                       r.status, r.started_at, r.finished_at, r.duration_ms, r.row_count, r.truncated, r.error_code
                  FROM report_runs r LEFT JOIN report_definitions d ON d.id = r.report_definition_id LEFT JOIN user_accounts u ON u.id = r.runner_account_id
                SQL, [
                F::uuid('id', 'Run ID'), F::uuid('report_definition_id', 'Definition ID'), F::string('report_name', 'Report'), F::string('dataset_code', 'Dataset'),
                F::uuid('runner_account_id', 'Runner ID'), F::personal('runner_username', 'Runner'), F::enum('output_format', 'Format', ReportOutputFormat::class),
                F::enum('status', 'Status', ReportRunStatus::class), F::datetime('started_at', 'Started'), F::datetime('finished_at', 'Finished'),
                F::int('duration_ms', 'Duration (ms)'), F::int('row_count', 'Rows'), F::bool('truncated', 'Truncated'), F::string('error_code', 'Error'),
            ], 'reporting.run.read_all', ownColumn: 'runner_account_id', defaultSort: [['started_at', 'desc']]),

            new D('users_access_review', 'Access review (accounts, roles, scopes)', 'identity', <<<'SQL'
                SELECT ra.id, u.id AS user_account_id, u.username, p.full_name, u.account_type, u.account_status, u.is_super_admin, u.last_login_at,
                       ro.code AS role_code, ro.name AS role_name, ra.scope_type,
                       COALESCE(w.code, b.code) AS scope_code, ra.effective_from, ra.effective_to
                  FROM role_assignments ra JOIN user_accounts u ON u.id = ra.user_account_id JOIN roles ro ON ro.id = ra.role_id
                  LEFT JOIN persons p ON p.id = u.person_id LEFT JOIN warehouses w ON w.id = ra.scope_id AND ra.scope_type = 'warehouse'
                  LEFT JOIN branches b ON b.id = ra.scope_id AND ra.scope_type = 'branch'
                SQL, [
                F::uuid('id', 'Assignment ID'), F::uuid('user_account_id', 'Account ID'), F::personal('username', 'Username'), F::personal('full_name', 'Person'),
                F::enum('account_type', 'Account type', AccountType::class), F::enum('account_status', 'Account status', AccountStatus::class),
                F::bool('is_super_admin', 'Super admin'), F::datetime('last_login_at', 'Last login'), F::string('role_code', 'Role code'), F::string('role_name', 'Role'),
                F::enum('scope_type', 'Scope', AssignmentScopeType::class), F::string('scope_code', 'Scope target'), F::datetime('effective_from', 'From'),
                F::datetime('effective_to', 'Until'),
            ], 'identity.access_review.export', classification: C::RESTRICTED, defaultSort: [['username', 'asc']]),
        ];
    }
}
