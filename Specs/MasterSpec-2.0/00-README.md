# STOSYNCRAD — MASTER SPECIFICATION 2.0
## Production-Ready, White-Label, Single-Company Multi-Branch Stock Management Platform

**Document ID:** STOSYNCRAD-MASTER-SPEC-2.0
**Status:** Implementation-ready baseline (supersedes and consolidates all prior documents)
**Consolidates:**
- `SCMS-TECH-SPEC-1.0` (specmaster.txt) — business and functional baseline
- `SCMS-ENGINEERING-CONTROLS-1.0` (AddendumOnSpecMaster.txt) — performance, security, concurrency, atomicity, reporting controls
- `STOSYNCRAD-FRONTEND-SPEC-1.0` (frontendspecs.txt) — mobile-first PWA frontend
- Business-flow diagram: *Stock Management Workflow for Single Company with Multiple Branches*
- Owner directives (2026-10-11): 100% configurability, easiest white-label rebranding, 100% admin-defined RBAC, configurable accounts-per-person policy, mandatory Platform Setup before first login, 100% custom reporting (web / PDF / Excel), Odoo-level item detail, complete category/sub-category coverage, enum-typed properties with global validation middleware, UUID primary keys

**Platform codename:** StoSyncRad (engineering name only — every deployed brand is configurable)
**Backend:** Laravel 13, PHP 8.3+
**Database:** PostgreSQL 16+ (only supported database)
**Frontend:** Vue 3, TypeScript (strict), Inertia.js, Tailwind CSS, Vite, installable PWA
**Architecture:** Modular monolith; independently governed read-only Reporting Engine
**Business model:** One legal company, many company-owned branches/warehouses, one unified ledger
**Default locale/timezone/currency:** Configurable at setup (reference defaults: `en`, `Africa/Addis_Ababa`, `ETB`)

---

## How to read this specification

The specification is split into numbered chapters. Each chapter is normative. Chapters are ordered so that a coding agent can implement them roughly in sequence, but cross-references are explicit.

| # | Chapter | Scope |
|---|---|---|
| 00 | [README](00-README.md) | Document control, precedence, glossary |
| 01 | [Vision & Business Model](01-vision-business-model.md) | Purpose, diagram-driven operating model, principles, invariants |
| 02 | [Platform Setup](02-platform-setup.md) | Bootstrap, Super Admin, step-by-step setup wizard, seeding, setup lock |
| 03 | [Configuration & White-Label Branding](03-configuration-and-branding.md) | Configuration registry, brand kit, zero-rebuild rebranding |
| 04 | [Identity & RBAC](04-identity-and-rbac.md) | Persons vs accounts, accounts-per-person policy, auth, endpoint permissions, admin-defined roles, warehouse scope, SoD |
| 05 | [Data Standards](05-data-standards.md) | UUID keys, enums, schema registry, global validation middleware, error format, precision, time |
| 06 | [Organization & Catalog](06-organization-and-catalog.md) | Company, branches, warehouses, locations, Odoo-level item master, categories, UoM, partners |
| 07 | [Inventory Engine](07-inventory-engine.md) | Quantities, conditions, ledger, positions, balances, reservations, lots/serials/expiry, valuation, batch traceability |
| 08 | [Operational Workflows](08-operational-workflows.md) | Planning, procurement, receiving & QC, allocation, transfers, requests, issues, retail & e-commerce fulfillment, returns, stocktaking, adjustments |
| 09 | [Transactions, Concurrency & Recovery](09-transactions-concurrency-recovery.md) | Atomic posting, outbox, idempotency, locking, retries, timeouts, rollback vs reversal |
| 10 | [Custom Reporting Engine](10-reporting-engine.md) | Catalog, builder, compiler, authorization, limits, web/PDF/Excel exports |
| 11 | [API & Integration](11-api-and-integration.md) | REST contract, versioning, error envelope, idempotency, endpoint-permission registry, imports/exports, webhooks |
| 12 | [Frontend & PWA](12-frontend-pwa.md) | Mobile-first UX, white-label theming, app shell, workflow UX, service-worker policy, budgets |
| 13 | [Database Model](13-database-model.md) | Tables, constraints, indexes, enum columns, append-only safeguards |
| 14 | [Security, Audit & NFRs](14-security-audit-nfr.md) | Defense in depth, DB roles, audit, performance, observability, backup |
| 15 | [Testing & Quality Gates](15-testing-and-quality-gates.md) | Mandatory test suites, failure injection, concurrency, RBAC, enum/UUID, setup, rebrand |
| 16 | [Deployment & Client Onboarding](16-deployment-and-client-onboarding.md) | Environments, deployment procedure, new-client rebrand playbook, Plesk |
| 17 | [Roadmap & Acceptance](17-roadmap-and-acceptance.md) | Phases, release checklist, final non-negotiable rules |
| A | [Enum Catalog](appendix-A-enum-catalog.md) | Every system enum and its cases |
| B | [Permission Catalog](appendix-B-permission-catalog.md) | Endpoint → permission registry |
| C | [Configuration Key Catalog](appendix-C-configuration-keys.md) | Every configuration key, type, default, scope |
| D | [Item Field Catalog](appendix-D-item-field-catalog.md) | Odoo-parity item master fields by tab |
| E | [Category Taxonomy](appendix-E-category-taxonomy.md) | Seeded, complete category / sub-category tree |

---

## Precedence rules

1. This Master Specification 2.0 supersedes the three source documents wherever they conflict.
2. Where this document is silent, the source documents remain in force in this order: Engineering Controls Addendum → Frontend Spec → Technical Spec 1.0.
3. **Resolved conflicts (binding):**
   - **Database:** PostgreSQL 16+ is the only supported database. MySQL/MariaDB/SQLite are not supported for any environment that validates concurrency, locking, or transaction recovery.
   - **Performance targets:** the tighter Addendum targets apply (see Chapter 14).
   - **Reporting:** the Custom Reporting Engine replaces the fixed "standard report" list. Every report family listed in Spec 1.0 §16 must be reproducible as a saved report definition shipped as a seed, executed on demand.
   - **Roles:** the named roles in Spec 1.0 §3.2 are **not hard-coded**. They are shipped only as optional, editable *role templates* (Chapter 04). The system itself knows only permissions.
   - **Identifiers:** every primary key is a UUID (Chapter 05). Spec 1.0 integer `id` columns are replaced.
   - **Sales fulfillment:** the diagram adds retail sale and e-commerce fulfillment. These are included as *stock-side* sales fulfillment (Chapter 08 §8.9), not as a POS, payment, or storefront product.
4. Non-negotiable invariants (Chapter 01 §1.6) override every other provision, including performance targets and UX convenience.

---

## Terminology

| Term | Meaning |
|---|---|
| **Platform** | The deployable software product (codename StoSyncRad). |
| **Deployment / Client** | One installation serving exactly one legal company. Not a tenant. |
| **Super Admin** | The bootstrap account created during Platform Setup. The only identity that exists before setup completes. Holds every permission; cannot be deleted; can be deactivated only when another account holds `platform.manage`. |
| **Branch** | A company-owned operating site (HQ, retail store, distribution depot, e-commerce fulfillment hub, production store…). A branch owns one or more warehouses. |
| **Warehouse** | A stock-holding facility inside a branch, subdivided into storage locations. The unit of operational authorization. |
| **Storage location** | Zone / aisle / rack / shelf / bin inside a warehouse. |
| **Inventory location** | Physical storage location *or* controlled virtual location (supplier, customer, in-transit, adjustment, opening, scrap, quarantine-virtual…). The ledger moves quantity between inventory locations. |
| **Stock position** | Unique combination of item + inventory location + lot (nullable) + stock condition (+ packaging/owner dimensions if enabled). The lockable unit. |
| **Ledger** | Append-only `stock_movements` / `stock_movement_lines`. Authoritative record of inventory. |
| **Projection** | Derived table (`inventory_balances`, serial state, valuation layer) kept in the same transaction as the ledger; never a source of truth. |
| **Posting** | Committing a stock-changing operation through a dedicated Action inside one PostgreSQL transaction. |
| **Reversal** | A new compensating posting that references an original posting. Never deletion. |
| **Permission** | A code bound to one or more HTTP endpoints/actions (`module.resource.action`). Defined in code, synced to DB. |
| **Role** | A named, admin-created set of permissions. Pure data. |
| **Scope** | The warehouses (and optionally branches) a role assignment applies to. |
| **Person** | A natural person (employee/contractor). |
| **User account** | A login identity belonging to a person. Accounts-per-person policy is configurable. |
| **Configuration key** | A typed, validated, versioned setting stored in the configuration registry (Chapter 03). |
| **Brand kit** | The bundle of name, logos, colors, fonts, document templates and PWA identity that defines a client's branding. |
| **Schema** | Declarative property metadata for a table/model used for validation, enum enforcement, API docs, TS types and reporting catalog (Chapter 05). |
| **Report definition** | Saved, versioned, declarative description of a custom report. Never stores results. |
| **Report run** | One on-demand execution of a definition (web view, PDF, or XLSX). Stores metadata only. |

---

## Conventions used in this specification

- **MUST / MUST NOT** — mandatory. Violations block release.
- **SHOULD** — expected unless a documented reason exists.
- **MAY** — optional.
- Code identifiers are given in `monospace`. Enum cases are `snake_case`. Permission codes are `module.resource.action`. Configuration keys are `domain.group.key`.
- "Server" means Laravel application code plus PostgreSQL constraints. "Client" means browser code; it is never authoritative.
