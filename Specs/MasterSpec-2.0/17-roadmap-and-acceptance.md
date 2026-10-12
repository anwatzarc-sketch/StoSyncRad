# 17 — IMPLEMENTATION ROADMAP, RELEASE ACCEPTANCE, FINAL RULES

## 17.1 Phases (each phase accepted before the next starts)

**Phase 0 — Foundations & Data Standards**
Laravel 13 skeleton; PostgreSQL roles and connections; base model (UUID v7); enum framework + migration helper + parity tests; property schema registry + global validation middleware + error envelope; configuration registry + lookups + enum labels + templates; permission registry + route binding + sync; audit foundation; outbox; idempotency; CI pipeline with all audits. *Exit:* all Chapter 05/03/04 structural tests green on PostgreSQL.

**Phase 1 — Platform Setup, Branding, Identity & RBAC**
Bootstrap command; setup state machine and wizard (all 16 steps); seeds (UoM, taxonomy, virtual locations, document sequences, role templates, report definitions placeholders); brand kit + derived assets + dynamic manifest + theme.css; persons/accounts/accounts-per-person policy; auth + MFA + sessions + tokens; roles/assignments/scopes/SoD/approval policies; administration UI; config bundles. *Exit:* no login before lock; headless == wizard; rebrand without rebuild; RBAC matrix tests green.

**Phase 2 — Organization & Catalog**
Branches/warehouses/locations/inventory locations; Odoo-level item master (all tabs), variants, packagings, barcodes, vendor pricelists, reorder rules; categories with full taxonomy and policy inheritance; partners; imports for all master data. *Exit:* Chapter 06 tests green; item detail screen complete.

**Phase 3 — Inventory Engine (release-gating core)**
Ledger, positions, balances, reservations, lots/QC/expiry, serials, conditions, valuation, reversals, opening balances, reconciliation/rebuild, traceability queries, posting sequence with locks/retries/timeouts, failure-injection and concurrency suites. *Exit:* Chapter 07/09 and §15.5–15.6 tests green on PostgreSQL. **No downstream workflow starts before this gate.**

**Phase 4 — Operational Workflows**
Procurement; receiving + QC + put-away; supplier returns; transfers (dispatch/in-transit lock/receipt with batch verify/discrepancies/closure); requests/picking/issues/internal returns; stocktaking/adjustments/relocations/condition changes; planning (replenishment, allocation plans); document numbering; notifications. *Exit:* Chapter 08 tests, E2E journeys 1–9 green.

**Phase 5 — Sales & E-commerce Fulfillment (feature-flagged)**
Sales orders (retail counter, e-commerce intake API), pick waves, packing, shipments, carrier webhooks, deliveries, customer returns, recall workflow. *Exit:* fulfillment tests; flags gate everything.

**Phase 6 — Custom Reporting Engine**
Reporting schema views; dataset catalog; definitions/versioning; compiler with authorization injection; executor on read-only role; web grid; PDF (DomPDF default) and XLSX (streaming) exports; limits/capacity; seeded standard report families; reporting audit; builder UI. *Exit:* §15.7 tests green; all Spec 1.0 §16 families reproduced as seeded definitions with golden outputs.

**Phase 7 — Frontend Completion & PWA**
All screens mobile-first; schema-driven forms; barcode component; dashboards; traceability UI; PWA manifest/service worker/offline/update manager; accessibility; Web Vitals budgets. *Exit:* Chapter 12 suites, viewport matrix, Lighthouse budgets.

**Phase 8 — Hardening, Performance & Production Readiness**
Reference-dataset benchmarks with mixed load; EXPLAIN plan review; privilege probes; backup/restore drill; deployment automation; onboarding playbook rehearsal (rebrand a second client from bundle in < 1 day); security review; documentation. *Exit:* full acceptance checklist.

## 17.2 Release acceptance checklist

**Platform & configuration**
- [ ] No access before setup lock; wizard and headless bundle paths pass golden test
- [ ] Every business value in Appendix C is a registry key; grep gate for literals passes
- [ ] Brand kit swap verified without rebuild (shell, login, manifest, icons, PDF, email)
- [ ] Config bundle export/import round-trips
- [ ] Feature flags gate endpoints, navigation and datasets

**Data standards**
- [ ] All PKs UUID v7; no serial columns
- [ ] Every list-valued property is an enum with DB CHECK parity; `ENUM_INVALID` messages list allowed values
- [ ] Every write route declares a schema; global validation middleware active; generated TS/OpenAPI current

**Identity & RBAC**
- [ ] Every route bound to exactly one permission; sync idempotent; role builder functional
- [ ] No role names in code; templates editable; Super Admin protected
- [ ] Accounts-per-person policy enforced at app + DB
- [ ] Scope matrix, SoD, approval limits, MFA step-up verified

**Inventory correctness**
- [ ] Ledger append-only via privilege + trigger; projections reconcile; rebuild never touches ledger
- [ ] Atomic posting with failure injection at all eight points
- [ ] Concurrent issues/reservations/dispatches/receipts safe; idempotency under lost responses
- [ ] Transfers conserve quantity; in-transit lots locked; batch verify at receipt; discrepancies traceable
- [ ] Expiry/QC/condition/serial rules block ineligible stock; recall locks lots
- [ ] Valuation reconciles per policy; reversals compensating only

**Workflows**
- [ ] PO approval, receipts (partial, over-receipt control), QC release, supplier returns
- [ ] Allocation plans generate transfers; requests/reservations/issues/returns
- [ ] Retail sale and e-commerce ship/deliver post to customer; customer returns restock
- [ ] Stocktake freeze/snapshot, recount thresholds, adjustment SoD
- [ ] Numbering unique under concurrency

**Reporting**
- [ ] Separate subsystem on read-only role; probes fail on write
- [ ] Custom builder: fields, filters, groups, aggregates, joins, calculations, parameters
- [ ] Web view, PDF and XLSX outputs with metadata; exports private and expiring
- [ ] No result caches, materialized report tables or scheduled runs; each run reads live data
- [ ] Scope and field restrictions hold through joins/aggregates/exports; limits and capacity enforced
- [ ] All seeded standard report families execute with golden results

**Security & operations**
- [ ] Server-side auth everywhere; warehouse scope on every request; CSP/HSTS/secure cookies
- [ ] Audit for all mandatory events; failed attempts never read as success
- [ ] DB least privilege per role; secrets not in repo/responses/logs
- [ ] Performance targets measured on reference dataset with concurrent reporting
- [ ] Backups verified; restore drill completed; deployment and rollback rehearsed
- [ ] Monitoring for failed jobs, dead outbox, deadlocks, reconciliation, slow queries, privilege violations
- [ ] New-client onboarding rehearsal completed in < 1 working day

## 17.3 Final non-negotiable rules
1. One company, many branches, one ledger. No tenancy.
2. PostgreSQL only; Laravel 13; modular monolith.
3. Nothing runs before Platform Setup is locked.
4. Every brand string, policy, threshold, numbering scheme, template, label and role is configuration or data — never code.
5. Code declares permissions bound to endpoints; humans compose roles.
6. Every PK is a UUID; every list-valued property is an enum validated at API, model and DB.
7. Every write passes the global schema validation middleware with explicit error codes.
8. Stock changes only through dedicated posting actions in one transaction with locks, idempotency, audit and outbox.
9. Ledger is append-only; corrections are compensating postings.
10. Transfers conserve quantity; in-transit lots are locked; receipt verifies batches.
11. Availability is decided under row locks at posting time — never in the browser.
12. Reporting is separate, read-only, 100% custom and 100% on demand with web, PDF and Excel outputs.
13. Warehouse scope, SoD, approval limits and data classification are enforced server-side on every entry point.
14. Audit everything sensitive; failed attempts are never success.
15. Performance is measured; correctness and security are never traded for speed.
16. No feature is complete until its acceptance tests demonstrate the required behavior on PostgreSQL.

**Definition of success:** A new client is rebranded and launched from a config bundle in a day; its Super Admin completes setup before anyone logs in; admins compose roles from endpoint permissions; HQ plans, buys, receives and QC-assigns lots; depots and stores receive batch-verified transfers; stores sell and hubs ship to customers; counts and adjustments reconcile; every lot is traceable across every branch; and every report anyone needs is built on demand and exported to PDF or Excel — all from one secure, auditable, mobile-first application.
