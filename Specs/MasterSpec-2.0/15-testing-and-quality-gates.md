# 15 — TESTING AND QUALITY GATES

All suites run in CI against PostgreSQL 16 (the production major version). SQLite is permitted only for pure unit tests of business math and is never evidence for locking, transactions, constraints or recovery.

## 15.1 Unit tests
Quantity/UoM conversion; decimal math and rounding boundaries; availability calculation per condition/expiry/lot lock/serial state; reorder/replenishment calculation (included quantities); allocation policies; forecast methods; valuation (average, FIFO, standard) golden cases; status transition tables; approval tier and SoD evaluation; expression grammar parsing/type-checking; config scope resolver; permission scope resolver; enum label resolver; formatting utilities (backend + frontend parity fixtures).

## 15.2 Data-standard tests
`UuidPrimaryKeyTest`; `EnumConstraintParityTest`; `SchemaMigrationParityTest`; `SchemaRouteCoverageTest` (every write route has a schema); generated TS/OpenAPI/Appendix-A freshness; unknown/server-controlled/immutable property rejection; enum error message shape with allowed values; precision overflow rejection.

## 15.3 Platform setup & configuration tests
Pre-lock route gating (web/API/Inertia); bootstrap token lifecycle; step ordering; idempotent seeds (two runs identical); headless bundle == wizard golden config; reopen requires permission + maintenance; config key registry completeness; scope precedence; secret masking; brand activation without rebuild (build hash unchanged, manifest ETag changed); hard-coded brand string grep gate; bundle export/import round trip; feature flag gating (endpoints, nav, datasets).

## 15.4 RBAC tests
Route permission coverage audit; `permissions:sync` idempotence and deprecation handling; implications; role edits effective immediately; scope matrix (company/branch/warehouse × scope kinds × transfer sides); request-supplied warehouse ids cannot widen; token abilities ≤ live permissions; SoD required/recommended/override; approval limits per permission constraints; accounts-per-person `single`/`multiple` with DB-level enforcement; deactivation effects; Super Admin protections; step-up MFA on critical permissions.

## 15.5 Inventory integrity tests (must pass)
1. Receipt increases the correct position (lot/condition/location).
2. Prohibited over-receipt rejected; tolerance honored.
3. Failed receipt leaves no partial ledger/balance/lot/serial/audit/outbox/idempotency.
4. Dispatch reduces source and increases in-transit equally; lots locked; serials `in_transit`.
5. Dispatch does not increase destination availability.
6. Receipt reduces in-transit and increases destination by accepted qty; batch mismatch rejected.
7. Partial receipts preserve remainder; closure requires conservation.
8. Discrepancies never erased; each resolution posts a traceable movement.
9. Issue cannot exceed available; reservations reduce availability; cancellations release.
10. Expired/blocked/quarantined/in-transit/recalled stock cannot be issued/sold via standard workflows.
11. Duplicate serials rejected; serial cannot exist in two positions.
12. Repeated posting requests (same key) do not duplicate; different payload rejected.
13. Unauthorized warehouse posting rejected; cost fields hidden without permission (API, export, report).
14. Posted movements immutable via app and DB (trigger/privilege).
15. Reversal preserves original, creates traceable compensation, blocked by downstream dependencies.
16. Balance projections reconcile to ledger after every workflow suite; rebuild never touches ledger.
17. Valuation reconciles per policy; transfers do not create profit/loss.
18. Opening balances only through controlled posting.
19. Counter sale and e-commerce ship/deliver post to `customer`; customer returns restock to inspection condition.
20. Lot forward/backward trace is complete across branches; recall locks lots everywhere.

## 15.6 Failure-injection & concurrency tests (PostgreSQL)
Deterministic fault injection at the eight posting points for every `Post*Action`; commit-ambiguity (committed/lost response, not committed); concurrent issue 2×70 on 100 (plain, reserved, lot-specific, serialized); concurrent reservations; concurrent dispatches; duplicate receipt events; concurrent document numbering; deadlock classification, bounded retry, no duplicated external effects; lock timeout handling; stocktake freeze under concurrent postings; outbox relay duplicate delivery idempotence.

## 15.7 Reporting isolation tests
Reporting role write probes fail; warehouse scope through joins/calculated fields/grouping/exports/saved definitions/parameter tampering; restricted fields via aggregates/expressions blocked; injection values inert; invalid joins/expressions rejected; timeouts cancel and do not affect postings; exports only on request; live data per run; capacity caps; each seeded standard report executes on fixtures and matches golden output; XLSX typed cells and metadata sheet; PDF truncation notice and branding.

## 15.8 Security tests
See Chapter 14 §14.13; plus CSP/nonce verification, session fixation, password policy, MFA enrollment/step-up, rate limits, import file abuse, webhook signature validation, API token revocation.

## 15.9 Frontend tests
Unit, component, E2E and PWA suites per Chapter 12 §12.12; viewport matrix; axe accessibility checks; Lighthouse CI budgets.

## 15.10 Performance & recovery tests
Nightly benchmark on the reference dataset with mixed operational + reporting load; EXPLAIN plan gates; timeout/cancellation tests; backup/restore drill automation (restore into scratch DB, run reconciliation, compare checksums).

## 15.11 Quality gates (CI pipeline, release-blocking)
PHP: Pint, PHPStan (level 8 on domains; baseline elsewhere), Rector safe set (optional), composer audit; migrations fresh + rollback test; seeds idempotent; permission/schema/config/enum/UUID audits; full PHPUnit/Pest suite on PostgreSQL; frontend: `vue-tsc`, ESLint, Vitest, Playwright, Lighthouse CI; production asset build; OpenAPI validation; dependency vulnerability scan; secret scan; hard-coded brand grep; coverage thresholds for domains (≥ 90 % lines on `Inventory`, `IdentityAccess`, `ReportingEngine` compilers, `Platform` setup).

A release is not accepted if any critical inventory integrity, atomicity, concurrency, RBAC, reporting-isolation or setup test fails.
