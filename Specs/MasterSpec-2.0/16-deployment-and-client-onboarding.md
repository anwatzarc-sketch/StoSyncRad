# 16 — DEPLOYMENT, OPERATIONS AND NEW-CLIENT ONBOARDING

## 16.1 Environment requirements
PHP 8.3+ with extensions (Chapter 02 §2.4); Composer; Node LTS for builds (build machine only); PostgreSQL 16+ with `pg_trgm`, `uuid-ossp`/`pgcrypto` (optional), four roles (deploy, app_runtime, reporting_reader, backup); HTTPS with valid certificate; web server serving `public/` only; private writable `storage/`; queue (database driver default; Redis optional); scheduler (cron every minute); SMTP (optional); optional Chromium for PDF renderer `chromium`; adequate disk for attachments, exports, logs, WAL.

## 16.2 Environment variables (infrastructure only)
`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL`, `DB_*` for `pgsql` (runtime role) and `DB_DEPLOY_*` (used only by migrations/`platform:*` commands), `DB_REPORTING_*` (reporting role), `DB_STATEMENT_TIMEOUT_MS`, `DB_LOCK_TIMEOUT_MS`, `SESSION_*` (secure, same-site), `QUEUE_CONNECTION`, `MAIL_*`, `FILESYSTEM_PRIVATE_DISK`, `PDF_RENDERER`, `TRUSTED_PROXIES`, `SETUP_SUPERADMIN_PASSWORD_FILE` (headless only). Business configuration is **not** in `.env`; it lives in the configuration registry.

## 16.3 Deployment procedure
1. Verify release artifact (tag/commit, checksum); build assets on the build machine; install production dependencies (`--no-dev`, lock file).
2. Back up database (and verify) and private storage.
3. `php artisan down --secret=…` (maintenance with bypass).
4. `php artisan migrate --force` using the deploy role (`--database=pgsql_deploy`); migrations are backward-compatible; destructive changes shipped in a later release.
5. `php artisan platform:upgrade` — runs `permissions:sync`, `config:registry:sync`, `report-datasets:sync`, additive seeds, enum label additions, template defaults (client edits preserved), `schema:audit-routes`, privilege probes.
6. `config:cache`, `route:cache`, `view:cache`, `event:cache`; regenerate brand derived assets if the asset pipeline changed.
7. Restart queue workers (`queue:restart`), outbox relay, reporting workers; verify scheduler heartbeat.
8. `php artisan platform:health --strict` (DB roles, append-only triggers, timeouts, storage, queue, setup state).
9. Smoke test: login, read-only stock view, one report run on a seeded definition, manifest + theme served.
10. `php artisan up`. Record deployment in `deployments` log (version, actor, time, health report).
Rollback: previous release artifact + `migrate:rollback` only for the reversible migrations of that release; database restore only for disaster recovery.

## 16.4 New-client onboarding playbook (rebrand & deploy — the easiest path)
1. **Provision**: PostgreSQL database + four roles, hosting, HTTPS, `.env`.
2. **Deploy code** (identical artifact for every client — no fork).
3. **Prepare brand kit**: fill `brandkit/brand.json` (names, colors, fonts, support/legal text) and drop logo/icon/letterhead files into `brandkit/assets/`; zip. (Template kit shipped in `resources/brandkit-template/`.)
4. **Prepare config bundle**: clone a reference `bundle.json` (e.g. retail-chain, distribution, pharma) and edit company profile, locale, policies, organization (branches/warehouses), numbering, roles (templates), datasets, integrations.
5. **Run headless setup**: `php artisan platform:bootstrap` → `php artisan platform:setup --bundle=bundle.json --brand=brandkit.zip` (prompts for Super Admin credentials) → Lock.
   — or — hand the bootstrap URL to the client's Super Admin to complete the wizard interactively with the brand kit upload.
6. **Verify**: `platform:health --strict`, rebrand E2E (app shell, login, manifest, PDF letterhead, email), reporting role probe, performance smoke.
7. **Load opening data**: catalog/partner/opening-balance imports (drafts), then authorized posting.
8. **Hand over**: Super Admin recovery codes, bundle export for DR, runbook.
Target: a trained operator rebrands and launches a new client in under one working day without code changes.

## 16.5 Plesk / shared-hosting compatibility
Document root → `public/`; PHP version and extensions selectable; PostgreSQL provided by the plan (or external managed instance); scheduled tasks for `schedule:run` (every minute) and `queue:work --stop-when-empty` fallback if daemons are unavailable; database queue default; DomPDF default renderer; Redis/Horizon not required; `.env`, `storage/`, `vendor/` not web-accessible; private disk outside the document root.

## 16.6 Operations runbook
Daily: failed jobs, dead outbox, reconciliation findings, backup status, slow queries, deadlock count, disk. Weekly: privilege probes, export purge verification, expiring lots digest, access review export. Quarterly: restore drill, credential rotation, dependency updates, performance benchmark, security scan. Incident: correlation-id lookup across logs/audit; posting outcome resolution via `operations/{key}`; service worker reset route; maintenance mode with bypass secret.

## 16.7 Upgrades
`platform:upgrade` is idempotent and additive; release notes list new permissions (assigned to no role by default; admins decide), new config keys (defaults applied), new enum cases (labels added), new datasets/seeded reports, template changes (new defaults; client edits untouched). Breaking schema changes follow expand/contract across two releases.
