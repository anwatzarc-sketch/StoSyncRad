# 04 — IDENTITY, AUTHENTICATION AND 100% ADMIN-DEFINED RBAC

## 4.1 Persons and user accounts

Two distinct entities:

- **`persons`** — a natural person: `id uuid`, `full_name`, `employee_number` (nullable, unique when present), `national_id` (nullable, encrypted, hashed for uniqueness), `email` (nullable), `phone` (nullable), `branch_id` (home branch, nullable), `department_lookup_id`, `job_title`, `status` enum `PersonStatus` (`active`, `inactive`, `left`), `created_at`, `archived_at`.
- **`user_accounts`** — a login identity owned by a person: `id uuid`, `person_id`, `username` (unique, case-insensitive), `email` (unique, case-insensitive; login identifier alongside username), `password_hash`, `account_type` enum `AccountType` (`human`, `service`), `account_status` enum `AccountStatus` (`pending_activation`, `active`, `locked`, `suspended`, `deactivated`), `is_super_admin bool`, `mfa_status` enum `MfaStatus` (`not_enrolled`, `enrolled`, `required_pending`), `password_changed_at`, `must_change_password bool`, `last_login_at`, `last_login_ip`, `failed_login_count`, `locked_until`, `deactivated_at`, `deactivated_by`, `created_at`, `updated_at`.

### Accounts-per-person policy (corporate configuration)

Key `identity.accounts_per_person` enum `AccountsPerPersonPolicy`:

- `single` — a person may own exactly one `human` account.
- `multiple` — a person may own several accounts (e.g., one per branch or duty), each with its own roles, MFA and audit identity.

Supporting keys: `identity.person_identity_key` (`email` | `employee_number` | `national_id` | `phone`) used to match an existing person on account creation; `identity.max_accounts_per_person` (int, used when `multiple`); `identity.service_accounts_enabled`.

Enforcement:
- `CreateUserAccountAction` locks the `persons` row (`SELECT … FOR UPDATE`), counts existing non-deactivated `human` accounts, and rejects with `422 ACCOUNT_LIMIT_REACHED` when the policy forbids another.
- A partial unique index `user_accounts_single_human_per_person` on `(person_id) WHERE account_type = 'human' AND account_status <> 'deactivated' AND single_policy_marker = true` is maintained: the `single_policy_marker` generated column is `true` only while the policy is `single` (set by the policy-change migration/command), giving database-level enforcement under `single`.
- Switching `multiple → single` is refused while any person has more than one active human account; the admin UI lists violations to resolve first.
- Historical records always reference the `user_account` id (actor), and the person is resolvable through it; deactivation never breaks attribution.

### Service accounts
Non-interactive accounts for integrations (API clients). They have no password; they authenticate with scoped API tokens (§4.3), have roles like any account, cannot approve documents unless a permission explicitly allows machine approval (default none), and are excluded from the accounts-per-person policy.

## 4.2 Authentication

- Session-based auth (Laravel session, same-origin, Inertia) with `Secure`, `HttpOnly`, `SameSite=Lax` cookies; session regeneration on login/privilege change; invalidation on logout, deactivation, password change, role change (configurable `security.session.invalidate_on_role_change`, default `true`).
- Password hashing: Argon2id (configurable memory/time) with rehash-on-login when parameters change.
- Password policy from `identity.password.*` (min length, character classes, dictionary/breach check toggle, history N, max age, lockout threshold/duration).
- Login throttling per account + per IP; generic failure messages; security events logged.
- MFA: TOTP (RFC 6238) mandatory where `identity.mfa.policy` requires; recovery codes (hashed, single-use); trusted devices (`identity.mfa.trusted_device_days`); step-up re-verification for sensitive actions listed in `security.step_up.permissions` (default: reversals, config changes, role changes, setup reopen, export of cost data).
- Password reset: signed, single-use, expiring tokens; no account enumeration.
- Optional OIDC SSO (disabled by default; config under `identity.sso.*`); local accounts remain the fallback; JIT provisioning is off by default.
- Account lifecycle events (created, activated, locked, suspended, deactivated, password changed, MFA enrolled/reset, role assigned/revoked, scope changed) are audited.

## 4.3 API authentication

- Same-origin SPA/Inertia: session cookie + CSRF token.
- External clients: Laravel Sanctum personal access tokens bound to a `service` or `human` account, with **token abilities = a subset of the account's permission codes**; tokens expire (`security.api_token.max_lifetime_days`), are hashed at rest, revocable, and audited. Token abilities can never exceed the account's effective permissions at request time (evaluated live, not at issue time).

## 4.4 Permissions: defined in code, bound to endpoints

The system knows only **permissions**. A permission is a stable code `module.resource.action` declared once in code and attached to every endpoint/action it guards.

### Declaration
`app/Domains/<Module>/Permissions.php` returns a list of `PermissionDefinition { code, module, resource, action, label, description, scope_kind, sensitivity, implies[] }`:

- `scope_kind` enum `PermissionScopeKind`: `global` (not warehouse-bound, e.g. `platform.config.read`), `warehouse` (requires warehouse scope on the assignment), `branch`, `own_records` (e.g. `fulfillment.stock_request.view_own`).
- `sensitivity` enum `PermissionSensitivity`: `normal`, `elevated` (step-up MFA may be required), `critical` (always step-up, always audited with reason).
- `implies[]` — permissions automatically granted with this one (e.g. `catalog.item.update` implies `catalog.item.read`). Implication is resolved at sync time into explicit rows; the UI shows implied permissions as checked-and-locked.

### Binding to endpoints
Every route MUST declare exactly one primary permission via route middleware `permission:<code>` (or the `#[RequiresPermission('code')]` attribute on the controller action, which the route registrar converts to middleware). Additional conditions (document state, SoD, scope) are enforced by policies invoked inside the action. A route without a permission declaration fails CI (`permissions:audit-routes`) unless explicitly listed as public (`login`, `health`, `setup`, `manifest`).

### Registry sync
`php artisan permissions:sync`:
1. Collects all definitions and all route bindings.
2. Fails if a route references an undeclared code or a declared code is bound to no route (unless flagged `virtual`, e.g. UI-only capabilities like `reporting.cost_fields.view`).
3. Upserts `permissions` rows (never deletes; removed codes are marked `is_deprecated`, assignments retained but inert, admin UI shows a warning).
4. Writes `permission_endpoint_bindings` (`permission_id`, `http_method`, `route_name`, `uri_pattern`) for documentation and the Appendix B export.
Runs automatically in Setup Step 12 and on every deployment (`platform:upgrade`).

### Metadata endpoint
`GET /api/v1/meta/permissions` returns the catalog (module tree, labels, scope kinds, sensitivity, implications, bound endpoints) for the role-builder UI.

## 4.5 Roles: 100% admin-defined

- `roles`: `id uuid`, `code` (unique, slug), `name`, `description`, `is_system bool` (only `__super_admin`), `is_template_origin` (imported from seed), `status` enum `RoleStatus` (`active`, `archived`), `created_by`, timestamps.
- `role_permissions`: `role_id`, `permission_id`, `granted_via` enum `PermissionGrantSource` (`direct`, `implied`), `constraints jsonb` (optional per-permission constraints such as approval limits: `{"max_value": 50000, "max_quantity": 1000}`).
- Admins create roles by picking permissions in a module/resource tree with search, "select all in module", and consequence preview (which endpoints become reachable; which SoD rules now matter). No role names or compositions are required by code.
- Shipped **role templates** (Spec 1.0 §3.2 list) are optional seeds importable in Setup or later; after import they are ordinary roles.
- A role cannot be deleted while assigned; archive instead. Permission changes to a role take effect immediately (sessions re-evaluate permissions per request; cached per request only).
- `__super_admin` is hidden, unassignable through UI except to the bootstrap account, and grants `*`. Owner-level emergency access still routes through normal actions, locks, audits and SoD unless a specific override permission with reason is used (Chapter 14 §14.7).

## 4.6 Role assignments and warehouse scope

- `role_assignments`: `id uuid`, `user_account_id`, `role_id`, `scope_type` enum `AssignmentScopeType` (`company`, `branch`, `warehouse`), `scope_id uuid null` (branch or warehouse), `effective_from`, `effective_to` (nullable), `assigned_by`, `reason`, timestamps. Unique `(user_account_id, role_id, scope_type, scope_id)`.
- Effective permission for a request = union over active assignments of (role permissions ∩ permissions whose `scope_kind` is satisfied by the assignment's scope). A `warehouse`-scoped permission granted at `company` scope applies to every warehouse; at `branch` scope to that branch's warehouses; at `warehouse` scope to that warehouse only.
- Authorization helper `Access::can($account, 'transfers.transfer.dispatch', warehouse: $wh)` resolves scope. Policies MUST use it; never role names.
- Warehouse scope resolution per request (mandatory sequence): authenticate → derive the target warehouse(s) **from the document/resource on the server** (never from the request body) → check permission for every involved warehouse (transfers: dispatch needs source, receipt needs destination, view needs either) → check document state → check SoD → execute → audit.
- Indirect relationships do not widen scope (viewing a transfer does not grant viewing unrelated source-warehouse records).

## 4.7 Separation of duties (configurable)

`sod_rules` (seeded defaults, editable): `id`, `code`, `document_type` enum, `first_action` (permission code or document event, e.g. `created`, `submitted`), `second_action` (e.g. `approve`, `post`, `reverse`), `rule` enum `SodRuleType` (`different_actor_required`, `different_actor_recommended`, `none`), `threshold jsonb` (apply only above quantity/value), `is_active`.

Enforcement in the approving/posting action: evaluate applicable rules against the document's recorded actors; `different_actor_required` → `409 SOD_VIOLATION`; `recommended` → requires explicit `override_reason` and permission `approval.sod.override`, audited.

Default seeded rules: PO creator ≠ approver; adjustment creator ≠ approver; stocktake counter ≠ approver of resulting adjustment above threshold; transfer requester cannot dispatch and receive the same transfer; reversal requester ≠ reversal approver; receipt poster cannot modify the approved PO.

## 4.8 Approval limits

`approval_policies` (editable): `document_type`, tiers `[{min_value, max_value, required_approvals, required_permission, sequential bool}]`, value basis (`document_total` | `line_quantity` | `cost_value`), currency. Evaluated server-side; per-permission `constraints` on `role_permissions` (e.g. `max_value`) cap an individual approver.

## 4.9 Administration UI (Identity & Access)

Persons, User Accounts (create, activate, lock, suspend, deactivate, reset MFA, force password change, sessions list/revoke), Roles (builder, clone, compare, archive, usage), Assignments (per account and per warehouse views; bulk assign by branch), SoD Rules, Approval Policies, API Tokens, Security Events, Access Review export (who can do what where).

## 4.10 Tests (mandatory)

- Every route has exactly one permission binding or is in the public allow-list.
- `permissions:sync` idempotent; deprecated codes inert; implications materialized.
- Role with no permissions grants nothing; role edits apply on next request.
- Scope resolution matrix: company/branch/warehouse × permission scope kinds × transfer source/destination.
- Warehouse id in request body/URL cannot widen access (manipulation tests).
- Accounts-per-person: `single` blocks second account (app + DB index); `multiple` honors max; switching policies validates violations.
- Service account tokens cannot exceed live permissions; expired/revoked tokens fail.
- SoD required/recommended paths; override requires permission and reason; audited.
- MFA step-up enforced for `critical` permissions.
- Deactivated accounts cannot authenticate, approve, or be assigned; history still resolves actor names.
