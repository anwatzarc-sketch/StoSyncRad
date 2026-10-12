# 02 — PLATFORM SETUP (FIRST-RUN BOOTSTRAP)

## 2.1 Requirement

A freshly deployed instance MUST NOT allow any login, API call, report, job or data write until a Super Admin has completed and locked the step-by-step Platform Setup. Setup creates every system configuration and executes every seed the platform needs to operate. Setup is repeatable (idempotent), resumable, auditable and scriptable for headless client onboarding.

## 2.2 Setup state machine

Persisted in `platform_setup` (single row, UUID id) with:

- `state` enum `PlatformSetupState`: `not_started`, `in_progress`, `completed`, `reopened`
- `current_step` enum `PlatformSetupStep` (ordered list in §2.5)
- `completed_steps` jsonb (step → completed_at, actor, checksum)
- `bootstrap_token_hash`, `bootstrap_token_expires_at`
- `completed_at`, `completed_by_account_id`, `locked_at`
- `config_bundle_checksum` (when seeded from a bundle)

Rules:

- Middleware `EnsurePlatformSetupComplete` is in the global HTTP stack. While `state != completed`, every route except `/setup/*`, `/health/live`, `/health/ready` and static assets responds `503 SETUP_REQUIRED` (API) or redirects to `/setup` (web).
- While `state == completed`, `/setup/*` responds `404` unless `reopened` by a holder of `platform.setup.reopen` with the instance in maintenance mode.
- Steps are strictly ordered; a step cannot be completed before its predecessors. A step can be revisited before lock; revisiting re-validates and re-runs its idempotent seed.
- Every step completion writes an `audit_events` row (`platform.setup.step_completed`) with the actor (bootstrap token holder or Super Admin after step 2) and a hash of the submitted payload.

## 2.3 Bootstrap

1. Operator deploys code, sets `.env` (Chapter 16), runs `php artisan migrate --force`.
2. Operator runs `php artisan platform:bootstrap`. The command:
   - Verifies PostgreSQL version ≥ 16, required extensions, the three DB roles (deploy / runtime / reporting) exist with expected privileges, storage writable, queue table present, `APP_KEY` set, HTTPS forced in production.
   - Creates the `platform_setup` row in `in_progress` (or reports existing state).
   - Prints a single-use, 30-minute bootstrap token (hash stored; plaintext shown once). `--token-ttl` and `--print-url` options exist.
   - Exits non-zero with a readable checklist if any precondition fails.
3. Operator opens `/setup?token=…`. The token is exchanged for a setup session cookie (not a user session) that is valid only for `/setup/*`. Token reuse is refused.
4. Headless alternative: `php artisan platform:setup --bundle=/path/bundle.json [--brand=/path/brandkit.zip] [--non-interactive]` executes all steps from a validated Config Bundle (Chapter 03 §3.8) and prints the Super Admin recovery instructions. The bundle MUST NOT contain the Super Admin password; the command prompts for it or reads `SETUP_SUPERADMIN_PASSWORD_FILE`.

## 2.4 Environment preflight (Step 0 — automatic)

Checked on every setup page load and before lock; results shown as pass / warn / fail with remediation text:

- PHP ≥ 8.3 and extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `gd` or `imagick`, `zip`, `bcmath`, `openssl`, `fileinfo`, `ctype`, `json`, `xml`, `tokenizer`, `curl`
- PostgreSQL ≥ 16; `statement_timeout`, `lock_timeout`, `idle_in_transaction_session_timeout` set on runtime and reporting roles
- Runtime role lacks `UPDATE`/`DELETE` on ledger and audit tables; reporting role has only `SELECT` on reporting views
- `storage/` and `bootstrap/cache` writable; private disk configured and not web-reachable
- Queue driver configured; scheduler heartbeat seen within 10 minutes (warn only during setup)
- Mail transport configured (warn only)
- `APP_DEBUG=false`, `APP_ENV=production`, `APP_URL` https (fail in production)
- Browser requirements for PWA (HTTPS, manifest route reachable)

## 2.5 Setup steps (ordered)

Each step: purpose → inputs → validation → seed effect → completion criteria. All inputs are validated by the global schema validation layer (Chapter 05) against the configuration key catalog (Appendix C).

### Step 1 — Welcome & license acknowledgement
- Shows platform version, preflight summary, deployment id (generated UUID).
- Input: acknowledgement checkbox; optional deployment label.
- Completion: preflight has no `fail`.

### Step 2 — Super Admin account
- Inputs: person (full name, employee number optional, email, phone optional), username (optional, defaults to email), password (validated against the password policy chosen in step 5 *defaults*; re-validated after step 5), MFA enrollment (TOTP) — mandatory if `identity.mfa.required_for_platform_admins` default `true`.
- Seed: `persons` row, `user_accounts` row with `is_super_admin = true`, `account_status = active`; system role `__super_admin` (hidden, non-editable, grants `*`) assigned company-wide.
- After this step the setup session is upgraded to a real authenticated session for the Super Admin, limited to `/setup/*` until lock.
- Completion: account created, MFA verified (if required), recovery codes shown and acknowledged.

### Step 3 — Company profile
- Inputs: legal name, trading name, registration/tax ids, addresses (registered, operational), phone, email, website, fiscal year start month, base currency, additional currencies (optional), industry (lookup), notes.
- Seed: `company_profile` single row.
- Completion: required fields valid.

### Step 4 — Brand kit
- Inputs: application display name, short name, tagline, logo (SVG/PNG light), logo dark (optional), monochrome mark, favicon source, PWA icon source (≥ 512 px, maskable safe area preview), brand colors (primary, accent, surface, text, success, warning, danger, info) with live contrast check (WCAG AA), font choice (system / bundled set), document letterhead (header/footer blocks), email header/footer, login page background (optional), support contact, legal footer text. Alternatively upload a Brand Kit zip (Chapter 03 §3.5).
- Seed: `brand_kits` row set active; generated icon set; manifest cache rebuilt; theme tokens published.
- Completion: all required assets present; contrast check passes or explicit override recorded.

### Step 5 — Localization & formats
- Inputs: default locale (+ enabled locales), timezone, calendar (Gregorian; Ethiopian display calendar optional flag), date / time / datetime display formats, number format (decimal and grouping separators), quantity display precision (0–6), currency display rules, first day of week, unit systems (metric/imperial defaults for weight/volume/length).
- Seed: configuration keys `locale.*`.

### Step 6 — Identity & security policy
- Inputs: accounts-per-person policy (`single` | `multiple`), person identity key (`email` | `employee_number` | `national_id` | `phone`), username policy, password policy (min length, classes, history, max age, breach check toggle), lockout policy, session lifetime / idle timeout / absolute timeout, concurrent session limit, MFA policy (off / optional / required for permissions matching patterns / required for all), trusted-device duration, IP allow-list (optional), self-service password reset toggle, SSO placeholders (OIDC provider config optional, disabled by default).
- Seed: configuration keys `identity.*`, `security.*`.
- Completion: Super Admin's existing password satisfies the chosen policy or is changed in-step.

### Step 7 — Organization structure
- Inputs: branches (code, name, kind, address, contact, timezone override), warehouses per branch (code, name, capabilities, default receiving/dispatch/quarantine/damaged/scrap locations auto-created), storage location hierarchy (manual or generated from pattern e.g. `Z{1-3}/A{01-10}/R{01-05}/B{01-20}`), virtual inventory locations (auto-seeded: `supplier`, `customer`, `in_transit`, `adjustment`, `opening_balance`, `scrap`, `production`, `return_inspection`).
- Option: CSV/XLSX import with preview (Chapter 11 §11.9).
- Seed: organization tables.
- Completion: ≥ 1 branch of kind `headquarters`, ≥ 1 warehouse, each warehouse has its system locations.

### Step 8 — Catalog foundations
- Inputs: units of measure (seeded standard set, editable), UoM categories, default tracking mode, default costing method (`moving_average` | `fifo` | `standard`), default removal strategy (`fefo` | `fifo` | `lifo` | `closest_location`), stock conditions (system enum; labels editable), expiry alert horizons (7/30/90 default), ABC thresholds, category taxonomy selection (import full seeded taxonomy from Appendix E, a subset by top-level, or custom CSV), product tags, packaging types, barcode symbology defaults (EAN-13 / Code128 / QR), SKU generation pattern, lot number generation pattern, serial number pattern.
- Seed: `units_of_measure`, `item_categories`, `tags`, configuration keys `catalog.*`, `inventory.*`.
- Completion: ≥ 1 UoM category, base units present, ≥ 1 leaf category.

### Step 9 — Partners foundations (optional)
- Inputs: supplier/customer/carrier import or skip; payment terms lookup; incoterms lookup; recipient types.
- Seed: `partners*`, lookups.

### Step 10 — Document numbering
- Inputs: per document type: prefix, pattern tokens (`{PREFIX}-{YYYY}-{SEQ:6}`, `{BRANCH}`, `{WH}`), sequence scope (`global` | `per_year` | `per_branch` | `per_warehouse`), reset policy, starting number.
- Seed: `document_sequences` for every `DocumentType` enum case.
- Completion: every document type has a sequence; preview rendering shown.

### Step 11 — Stock & approval policies
- Inputs: negative stock policy (`forbid` | `allow_with_permission` per warehouse override), over-receipt tolerance (% and absolute, per PO or global), under-delivery closure rule, reservation policy (`on_approval` | `on_pick` | `off`), auto-release reservation after N days, quarantine-by-default for categories, transfer discrepancy tolerance, stocktake variance thresholds (recount %, supervisor %, approval %), approval thresholds by document type (quantity / value tiers → required approver count and permission), SoD rules (list of `{creator_permission, approver_permission, same_actor_forbidden}`), reversal window and approval, backdating window, expiry blocking rules, FEFO enforcement strictness, allocation policy defaults.
- Seed: configuration keys `stock.*`, `approval.*`, `sod.*`.

### Step 12 — Roles & access
- Permission registry is synced from code (`permissions:sync` runs automatically; shows count by module).
- Inputs: import role templates (optional seed: Inventory Manager, Procurement Officer, Warehouse Manager, Storekeeper, Stock Requester, Approver, Auditor, Executive, Reporting Analyst, Platform Administrator) — each becomes an *editable* role; create custom roles by picking permissions; assign roles + warehouse scopes to initial accounts (create accounts inline).
- Seed: `roles`, `role_permissions`, `role_assignments`.
- Completion: at least one non-super-admin account holding `platform.manage` is **recommended** (warn), not required.

### Step 13 — Reporting engine
- Inputs: reporting DB credentials test (`pgsql_reporting`), limits (interactive timeout, sync row cap, export timeout, max joins, max concurrent interactive, max export workers, export retention hours, max file size), PDF renderer (`dompdf` | `chromium`), XLSX writer settings, default page sizes/orientation, report sharing policy, datasets enabled, seeded report definitions (import all standard report families from Spec 1.0 §16 as saved definitions — recommended).
- Seed: configuration keys `reporting.*`, `report_datasets` catalog sync, `report_definitions` (seeded, owner = system, shared read-only, cloneable).
- Completion: reporting role verified read-only by probe (attempted `INSERT` must fail with `42501`).

### Step 14 — Notifications & integrations
- Inputs: mail from-name/address, transport test, in-app notification defaults per event, digest schedule, webhook endpoints (optional), API client registration (optional), e-commerce/POS integration toggles (disabled by default).
- Seed: configuration keys `messaging.*`, `integration.*`.

### Step 15 — Opening data (optional)
- Inputs: catalog import, partner import, opening balances import (creates `opening_balance` documents in draft for later posting by an authorized account — never posted during setup).
- Completion: imports validated; drafts created.

### Step 16 — Review, seed & lock
- Shows full configuration diff vs. defaults, seed execution log (idempotent, re-runnable), preflight re-check, "export Config Bundle" button (downloads `bundle.json` + brand kit zip for reuse on other clients), and acceptance checklist.
- Action **Lock & Launch**: sets `state = completed`, `locked_at`, disables setup routes, invalidates setup session, writes `platform.setup.completed` audit, enables login, queues welcome email to initial accounts, emits outbox event `platform.setup.completed`.
- Post-lock, every setting remains editable in Administration by permission (Chapter 03), with full audit.

## 2.6 Seeding rules

- All seeds are idempotent upserts keyed by natural keys (`code`, enum case, permission code) — safe to re-run.
- Seeds never post stock. Opening balances are drafted, then posted by an authorized actor through `PostOpeningBalanceAction`.
- Seeds are versioned (`seed_runs` table: seed name, version, checksum, started/finished, result). A newer platform release may ship additive seed versions (new permissions, new enum labels, new report datasets); `platform:upgrade` applies them without touching client-edited rows (client edits win; additions are inserted; removals are flagged, never deleted).
- Seeded roles are templates: once imported they are client-owned data.

## 2.7 Reopening setup

- Permission `platform.setup.reopen`; requires MFA step-up and maintenance mode (`platform:down --secret`).
- Reopened setup shows all steps with current values; lock again required. All changes audited as configuration changes.
- Intended for major re-brand or policy migration; routine edits use Administration screens.

## 2.8 Tests (mandatory)

- No route except setup/health is reachable before lock (web + API + Inertia).
- Bootstrap token single-use, expiring, hashed.
- Steps cannot be skipped or completed out of order.
- Re-running every seed is a no-op on second run (row counts equal, checksums equal).
- Headless bundle setup produces identical configuration to wizard setup (golden test).
- Super Admin cannot be deleted; MFA enforced when policy requires.
- Lock disables setup routes; reopen requires permission + maintenance mode.
- Reporting role read-only probe fails on write.
