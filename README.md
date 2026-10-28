# StoSyncRad — white-label stock management platform

Multi-branch inventory with company-wide batch/serial traceability: HQ planning → procurement →
receiving & QC → central ledger → allocation and inter-branch transfers → retail, regional depot and
e-commerce fulfilment → stocktaking, reporting and re-planning. One codebase serves every client;
rebranding and configuration are data, not code.

The functional specification is in [`Specs/MasterSpec-2.0/`](Specs/MasterSpec-2.0/00-README.md). This
file covers running, deploying and extending it.

## What makes it different

- **Platform Setup before anything else.** A fresh deployment answers nothing but `/setup`, health and
  brand assets until a Super Admin completes and locks a 16-step setup (wizard or headless bundle).
- **RBAC is entirely data.** Code declares ~350 permissions, each bound to exactly one API endpoint.
  Roles are permission lists that administrators build; warehouse/branch scope is per assignment.
  Whether one person may hold several accounts is a company setting.
- **Everything configurable.** ~210 typed configuration keys with scoped overrides (global → branch →
  warehouse → category → item), all audited.
- **Rebrand without a rebuild.** Names, colours, logos, icons, manifest, PDF letterheads and e-mails
  come from the active brand kit at request time.
- **Strict data rules.** UUIDv7 keys everywhere; every closed list is a backed enum checked in the
  database; every write passes a global schema-validation middleware with field-level errors.
- **An append-only stock ledger.** One posting path, idempotent postings, row-level balance locking,
  reversals instead of edits, reconciliation against the ledger.
- **Custom reporting.** A dataset catalog over read-only `reporting.*` views, a report builder, saved
  and shared definitions, on-screen results and PDF / Excel / CSV exports — each format its own permission.

## Requirements

PHP 8.3+ (`pdo_mysql`, `mbstring`, `intl`, `gd` or `imagick`, `zip`, `bcmath`, `openssl`,
`fileinfo`, `ctype`, `json`, `xml`, `tokenizer`, `curl`), MySQL 8.0.32 or later — 8.4 LTS recommended
(not MariaDB), Composer, Node 22
LTS (build machine only), HTTPS, a scheduler entry running `php artisan schedule:run` every minute, and a
queue worker for all three queues (`php artisan queue:work --queue=imports,reporting,default`) — a plain
`queue:work` only serves `default`, so imports and report exports would wait forever.

MySQL needs two databases per deployment — `<db>` and `<db>_reporting` (the reporting views) — and four
accounts: **deploy** (owns the schema, runs migrations), **runtime** (the app; no DDL, cannot update or
delete ledger/audit rows), **reporting** (read-only on the reporting views) and **backup**.
`database/mysql/setup.sql` creates them (run once as an administrator; it also sets
`log_bin_trust_function_creators`, which the append-only triggers need):

```bash
mysql -u root -p -e "SET @deploy_pw='…', @app_pw='…', @reporting_pw='…', @backup_pw='…'; SOURCE database/mysql/setup.sql"
```

On a hosting panel such as Plesk, database users get no GRANT OPTION and `setup.sql` cannot run. Create
the two databases and three users in the panel instead (deploy: every privilege on both databases;
runtime: Select, Insert, Update, Delete, Lock Tables and Show View on both; reporting: Select on
`<db>_reporting` only) and set `DB_MANAGE_GRANTS=false`. Ledger and audit rows are then protected by the
append-only triggers alone (preflight warns), and the server must let ordinary users create triggers
(`log_bin_trust_function_creators=ON`, or binary logging off).

`php artisan platform:health` verifies the privileges, the append-only triggers and the session settings.

## Local development

```bash
composer install && npm ci
cp .env.example .env          # set DB_* for your local MySQL accounts, APP_URL, APP_ENV=local
php artisan key:generate
php artisan migrate --database=mysql_deploy
php artisan platform:bootstrap --print-url   # prints a one-time setup link
npm run dev & php artisan serve
```

Open the printed `/setup?token=…` link and complete the wizard, or run headless setup (below).

## Deploying a release

Follow Spec Chapter 16 §16.3. In short:

```bash
php artisan down --secret=<bypass>
php artisan migrate --database=mysql_deploy --force
php artisan platform:upgrade          # permissions, config registry, reporting views, runtime grants, additive seeds, route gates
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
php artisan platform:health --strict
php artisan up
```

## Onboarding a new client (the fast path)

1. Provision MySQL 8.4 with `database/mysql/setup.sql`, HTTPS hosting and `.env` (infrastructure values only).
2. Deploy the same artifact as every other client.
3. Prepare a **brand kit** zip: `brand.json` (names, colours, support and legal text) plus
   `assets/<kind>.<ext>` (`logo_light`, `logo_dark`, `logo_mark`, `favicon_source`, `pwa_icon_source`, …).
4. Prepare a **config bundle**: export one from a reference deployment
   (`php artisan platform:bundle:export --output=bundle.json`) and edit company profile, organization,
   configuration and roles.
5. Run headless setup:
   ```bash
   php artisan platform:bootstrap
   php artisan platform:setup --bundle=bundle.json --brand=brandkit.zip
   ```
   The Super Admin password is prompted for (or read from `SETUP_SUPERADMIN_PASSWORD_FILE`); MFA
   enrolment happens at that account's first sign-in.
6. Verify with `php artisan platform:health --strict`, then load catalog, partners and opening balances
   through **Imports** (opening balances are drafts until an authorised person posts them).

On a running deployment, `php artisan platform:bundle:import bundle.json` shows a per-section diff;
add `--apply` to write it. The same is available in Administration → Config bundles.

## Commands

| Command | Purpose |
| --- | --- |
| `platform:bootstrap` | Preflight checks, open setup, print a single-use setup token |
| `platform:setup --bundle= [--brand=]` | Headless setup from a config bundle, then lock |
| `platform:upgrade` | Apply a release: permissions, reporting catalog, additive seeds, route gates |
| `platform:health [--strict] [--json]` | Preflight plus runtime invariants (append-only, roles, queue, outbox, scheduler) |
| `platform:bundle:export` / `platform:bundle:import [--apply]` | Portable configuration |
| `permissions:audit-routes` / `schema:audit-routes` | CI gates: every permission bound to an endpoint; every write route validated |
| `types:export-ts [--check]` | Regenerate `resources/js/types/generated.ts` from the backend registries |
| `outbox:relay`, `webhooks:deliver`, `reservations:expire`, `reports:purge`, `inventory:expiry-monitor`, `inventory:reconcile`, `sales:expire-quotations` | Scheduled jobs (see `routes/console.php`) |

## Customer Portal (CP-1.1)

A public landing page and a customer-facing portal in the same application, separated from the staff
app by layout, routes and authorization — not a second system. Switch it on with
`feature.customer_portal` (Administration → Configuration), set `portal.orders.warehouse_id` (the
warehouse that fulfils portal orders) and publish items with **Available online**.

| Customers (`/portal`) | Staff (Customers & billing menu) |
| --- | --- |
| Register → confirm e-mail → set up an authenticator → wait for approval (or accept an invitation) | Approve registrations (link an existing customer or create one), send invitations |
| Browse published items (list price, availability band — never costs or locations) | — |
| Order from list prices or request a quotation; accept/decline/ask to revise | Price and send quotations; confirm portal orders as usual (channel `customer_portal`) |
| Follow each order (timeline of recorded events, shipments, tracking) and confirm receipt or report issues | Resolve delivery issues (customer returns, replacements) — stock never changes from the portal |
| See invoices and balances; confirm a payment with a bank slip / screenshot / PDF | Issue invoices (from an order — ordered or delivered quantity, in parts — or per shipment), review evidence, **verify** (records the payment and allocates it), request information, reject, void |
| Customer administrators invite colleagues, promote them and remove their access (Team page) | Invite contacts (optionally as customer administrator); set customer credit limits; maintain tax rates |

Boundaries: customer accounts (`account_type = customer`) belong to one customer partner and hold only
the system role `__customer_member` (`customer.*` except `customer.member.manage`) or `__customer_admin`
(all `customer.*`; the first member of a customer and invited administrators); `EnsureAccountAudience` keeps customer
sessions on `/portal` + `/api/v1/portal` and staff sessions out of them, by path, for every request.
Every portal query is scoped to the account's own customer; another customer's id answers 404.

Billing ledger: an upload never changes an invoice. Only `PaymentService` moves `amount_paid` /
`balance_due` (row locks, one transaction); `payments` and `payment_allocations` are append-only, a bank
reference can be posted once (`payments_reference_posted_uq`), and voids reverse allocations instead of
deleting them. Evidence is stored on the private disk with content-detected types and streamed only after
an authorization check. Settings live under `portal.*` and `billing.*`.

Invoicing and credit: invoice lines are taxed per line (`tax_rates`; line rate → item `tax_rate_id` → default
rate → `billing.invoice.tax_rate_percent`). `InvoiceWorkflow::fromSalesOrder($id, $actor, 'ordered'|'delivered')`
and `fromShipment()` bill what is still open; an order line is never invoiced beyond its ordered quantity
across non-void invoices. `CreditService` blocks portal orders / quotation acceptance above a customer's
`credit_limit` and lets staff confirm above it only with `billing.credit.override` plus a reason. Sent
quotations past `valid_until` expire nightly (`sales:expire-quotations`, 00:45).

E-mail: all application mail goes through `TransactionalMailer`. `messaging.mail.transport` = `environment`
(the `.env` mailer), `smtp` (server, port, security, username and encrypted password from the registry — no
redeploy needed) or `log`. Administration → Configuration → messaging has **Send test e-mail**
(`POST /api/v1/admin/mail/test`).

Product photos: `items/{id}/images` (JPG/PNG/WebP, content-checked, private disk, limits under
`catalog.images.*`); the primary photo is shown in the catalogue and the portal.

## Code map

| Path | Contents |
| --- | --- |
| `app/Domains/*` | Modular monolith: Catalog, Organization, Partners, IdentityAccess, Inventory (ledger, poster, reservations, valuation), Procurement, Receiving, Transfers, Fulfillment, Stocktaking, Planning, ReportingEngine, Messaging, DataExchange, Platform (setup, config, brand, templates), Audit, CustomerPortal, Sales (quotations), Billing (invoices, payments) |
| `app/Support` | Schema validation, enums, documents, transactions, HTTP middleware |
| `app/Http/Controllers/Api` | JSON API (`/api/v1`), generic resource CRUD from `ResourceRegistry`, workflow actions |
| `routes/api/*.php` | One permission per endpoint (`->permission()`), payload schema per write (`->schema()` / `->noBody()`) |
| `resources/js` | Vue 3 + TypeScript + Inertia PWA: schema-driven forms, app shell, setup wizard, report builder |
| `database/migrations` | MySQL schema with enum CHECKs, append-only triggers and the reporting views (helpers in `app/Support/Database`) |
| `database/mysql` | One-time database and account setup (`setup.sql`, `setup-database.sql`) |

## Tests

```bash
vendor/bin/phpunit        # needs the MySQL test database stosyncrad_test (created by setup.sql; see phpunit.xml)
npx vitest run            # frontend units
npx vue-tsc --noEmit      # type check
npm run build             # production bundle + service worker
```

## Security notes

- Critical permissions require a recent step-up (password or authenticator) on every call; token
  clients cannot step up, so service accounts should never hold them.
- Accounts with a pending password change or required MFA enrolment can reach only `/me` and `/auth`.
- The service worker caches only versioned build assets, brand theme/icons and the offline page —
  never authenticated pages, API responses, reports or exports, and it never replays writes.
