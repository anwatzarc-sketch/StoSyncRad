# 01 — VISION AND BUSINESS MODEL

## 1.1 Purpose

Build a secure, modern, white-label, production-ready stock management platform that lets **one legal company** run inventory across **many company-owned branches and warehouses** from one unified ledger, on desktop, tablet and phone.

The platform must answer, at any moment and with full traceability:

- What does the company own, where is it, in what condition, and in which lot/serial?
- How much is on hand, reserved, available, quarantined, damaged, expired, blocked, or in transit — per warehouse, per location, per branch, company-wide?
- Which branch has excess, which has shortage, and what should be allocated or reordered?
- What was purchased, received, inspected, allocated, transferred, sold, delivered, returned, counted or adjusted — by whom, when, and under which approval?
- What is the recorded inventory value at any reporting date?
- Can every balance be reproduced from posted movements, and can every lot be traced forward and backward across every branch?

The platform must be deployable for a **new client in hours, not weeks**, by configuration and branding alone — no code changes.

## 1.2 Operating model (from the business-flow diagram)

The diagram defines the canonical flow the platform must implement end to end:

```
CENTRAL OPERATIONS & SUPPLY CHAIN (HQ)
  Demand forecasting & planning
    → Supplier procurement & ordering
      → Central supply chain / logistics (inbound)
        → Incoming stock & QC  (batch/lot assignment: capture lot #, mfg date, expiry)
          → CENTRALIZED DATA CONSOLIDATION (UNIFIED LEDGER)
            → Stock allocation & distribution
              ├─ Branch A  Flagship Retail Store         (branch receiving w/ batch verify, local stock bins, customer sales orders, retail sale to customer)
              ├─ Branch B  Regional Distribution Depot   (multi-warehouse zones/bins, inter-branch transfer dispatch, transit w/ batch IDs locked)
              └─ Branch C  E-commerce Fulfillment Hub    (hub receiving w/ batch verify, high-speed FEFO picking, pack & ship, e-com delivery)
                → Fulfillment & closure → performance monitoring & re-planning (feeds forecasting)
BATCH TRACKING across all branches: central assign → depot storage → branch → sale
```

Normative consequences:

1. **HQ is a branch of kind `headquarters`** that owns central receiving/QC warehouses and the planning, procurement and allocation functions. It is not a separate company.
2. **Branch kinds are first-class** (`headquarters`, `retail_store`, `distribution_depot`, `ecommerce_fulfillment_hub`, `production_store`, `service_center`, `transit_hub`, `other`). Kind drives default capabilities, not hard-coded permissions. Capabilities are per-warehouse flags (Chapter 06).
3. **Inbound goods pass QC and lot assignment at receiving.** Lot number, manufacture date and expiry are captured at the goods receipt line before stock becomes available. Quarantine-required items enter `quarantine` condition.
4. **One unified ledger.** Every movement in every branch writes to the same append-only ledger. "Centralized data consolidation" is the ledger itself — not a nightly sync.
5. **Allocation & distribution** is a planning document (Allocation Plan) that generates approved inter-branch transfers; it never moves stock itself.
6. **Inter-branch transfers are batch-verified at both ends.** Dispatch records the exact lots/serials; in transit those lots are **locked** (cannot be issued, sold, adjusted or re-dispatched); receipt verifies the same lots/serials before destination stock increases.
7. **Retail sale and e-commerce delivery are stock-side fulfillment documents** (sales order → pick (FEFO) → pack → ship/deliver → customer virtual location). Payment, pricing engines, storefronts and POS hardware are integration points, not platform scope.
8. **Closure and monitoring feed re-planning.** Consumption, sales, lead-time and discrepancy history feed the replenishment and forecasting inputs (Chapter 08 §8.1).
9. **Batch traceability is company-wide.** For any lot: every receipt, QC decision, storage position, transfer, sale/issue, return, adjustment and current remaining quantity per branch is reproducible from the ledger (Chapter 07 §7.10).

## 1.3 What the platform is and is not

**Is:**
- One company, many branches/warehouses, one ledger, one catalog, one configuration, one brand per deployment.
- White-label: every visible name, logo, color, document template, email template, PWA identity and locale is configuration.
- 100% configurable: every policy, threshold, numbering scheme, approval rule, stock rule, reporting limit and feature toggle is a validated configuration key.
- 100% admin-defined RBAC on endpoint permissions.
- Stock-side procurement, receiving, QC, allocation, transfer, requisition, issue, retail/e-commerce fulfillment, returns, stocktaking, adjustment, valuation, replenishment, traceability and on-demand custom reporting.

**Is not:**
- Multi-tenant SaaS, holding-company or marketplace platform.
- General ledger / accounting, payroll, HR, CRM, MRP/manufacturing execution, full POS, storefront, payment processing, fleet, customs, ML forecasting, offline posting.

Integration points for those systems are exposed through the versioned API, outbox events and webhooks (Chapter 11).

## 1.4 Design principles

1. **Inventory accuracy first.** Every posted change is a consistent, traceable ledger movement.
2. **Single source of truth.** Posted ledger entries are authoritative; balances are controlled projections.
3. **No silent corrections.** Posted records are never edited or deleted; corrections are reversals or compensating postings.
4. **No unauthorized negative stock.**
5. **Server-side control.** Quantities, permissions, approvals, costs, timestamps, statuses and outcomes are decided by the server and PostgreSQL.
6. **Configuration over code.** Any behavior a client might reasonably want different is a configuration key, a lookup, a role, a template or a seed — never a fork.
7. **Permissions, not roles, in code.** Code knows permissions bound to endpoints; humans compose roles.
8. **Typed everything.** UUID keys; enum-typed properties; schema-declared tables; global validation; generated TypeScript types.
9. **Separation of duties** is a configurable policy enforced server-side.
10. **Warehouse-scoped authorization** on every request, export, report, attachment and job.
11. **Auditable operations.** Every sensitive action is attributable to an authenticated actor with a correlation id.
12. **Modular monolith.** Domain modules with explicit dependencies; no microservices in release 1.
13. **Mobile-first, installable, offline-honest.** The PWA never pretends an uncommitted operation succeeded.
14. **Reporting is separate, read-only and 100% on demand.** No result caches, no materialized report tables, no scheduled snapshots.
15. **Performance is measured**, never assumed, and never purchased with correctness.

## 1.5 Critical business domains (modules)

| Module | Directory | Owns |
|---|---|---|
| Platform | `app/Domains/Platform/` | Setup wizard, configuration registry, branding, feature flags, seeds, health |
| IdentityAccess | `app/Domains/IdentityAccess/` | Persons, accounts, auth, MFA, sessions, permissions registry, roles, scopes, SoD policies |
| Organization | `app/Domains/Organization/` | Company profile, branches, warehouses, storage locations, inventory locations |
| Catalog | `app/Domains/Catalog/` | Items, variants/attributes, categories, UoM, packagings, barcodes, tags, vendor pricelists, reorder rules |
| Partners | `app/Domains/Partners/` | Suppliers, customers, recipients, carriers, contacts |
| Inventory | `app/Domains/Inventory/` | Ledger, positions, balances, reservations, lots, serials, expiry, conditions, valuation, reconciliation, reversals |
| Planning | `app/Domains/Planning/` | Demand history, forecast inputs, replenishment suggestions, allocation plans |
| Procurement | `app/Domains/Procurement/` | Purchase requests, purchase orders, supplier returns |
| Receiving | `app/Domains/Receiving/` | Goods receipts, inspection/QC, lot assignment, put-away |
| Transfers | `app/Domains/Transfers/` | Transfer orders, dispatches, in-transit, receipts, discrepancies |
| Fulfillment | `app/Domains/Fulfillment/` | Stock requests, picking, issues, sales orders (retail/e-commerce), packing, shipments, deliveries, customer returns, internal returns |
| Stocktaking | `app/Domains/Stocktaking/` | Stocktakes, cycle counts, blind counts, recounts, adjustments |
| ReportingEngine | `app/Domains/ReportingEngine/` | Dataset catalog, definitions, compiler, runs, exports (web/PDF/XLSX), limits, reporting audit |
| Audit | `app/Domains/Audit/` | Audit events, approval records, security events, integrity monitoring |
| Messaging | `app/Domains/Messaging/` | Notifications, outbox processing, mail, webhooks |
| DataExchange | `app/Domains/DataExchange/` | CSV/XLSX imports, exports, opening balances, config bundles |

Dependency rule: lower modules must not depend on higher ones. `ReportingEngine` depends on nothing operational except read-only dataset views. No module may write to the ledger except `Inventory` posting actions invoked by workflow actions.

## 1.6 Non-negotiable invariants

These override every other provision.

1. One committed posting = one complete business operation.
2. A failed transaction leaves no partial inventory movement, projection, reservation, audit success record, outbox event, idempotency result or document status.
3. Ledger movements are never updated or deleted by ordinary application paths.
4. Every posted quantity is traceable to its source document line and lot/serial.
5. Transfers conserve quantity through source → in-transit → destination; in-transit lots are locked.
6. Availability is validated under PostgreSQL row locks at posting time.
7. Repeated posting requests cannot duplicate movements (idempotency + uniqueness).
8. Authorization (permission + warehouse scope + document state + SoD) is enforced server-side on every entry point.
9. Reporting cannot modify operational data and runs on a read-only database role.
10. Reports read committed data at execution time; no cached or scheduled results.
11. Post-commit corrections are traceable compensating postings.
12. Every primary key is a UUID; every list-valued property is an enum validated at API, application and database layers.
13. No user can log in before Platform Setup is complete and locked.
14. No role, brand string, policy threshold or document template is hard-coded.
15. Every invariant has an automated test that runs against PostgreSQL in CI.
