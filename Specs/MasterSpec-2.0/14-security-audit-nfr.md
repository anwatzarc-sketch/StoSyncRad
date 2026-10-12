# 14 — SECURITY, AUDIT AND NON-FUNCTIONAL REQUIREMENTS

## 14.1 Defense in depth
Layers: browser → HTTP middleware (CSP, HSTS, CSRF, rate limits, setup gate, schema validation) → authentication → permission + scope + state + SoD authorization → business actions → PostgreSQL constraints, triggers, role privileges → private storage → queue workers → reporting subsystem → logs/backups/secrets. No layer substitutes for another.

## 14.2 Authentication & session (summary)
Argon2id; policy-driven passwords; throttling; MFA (TOTP) with step-up; secure cookies; regeneration; invalidation on privilege change/deactivation; concurrent session limits; HTTPS-only; security event logging; no credential/tokens in client storage.

## 14.3 Authorization (summary)
Every endpoint: one permission; scope from server-resolved warehouse/branch; document state; approval limits; SoD; property-level `writable_by`; data classification for cost/personal fields in responses, exports and reports. Row-Level Security may be added as defense in depth after context-propagation testing — never as a replacement.

## 14.4 Input, output, files
Schema validation on all writes; parameterized SQL only; allow-listed identifiers (reporting, sorting, filtering); escaped output; sanitized rich text; CSRF on same-origin; uploads validated (type sniffing, size, extension allow-list, image re-encoding, antivirus hook optional), stored privately under random paths, served via authorized streamed responses; never executed; export files private and expiring; no stack traces in responses.

## 14.5 Database roles (least privilege)
`deploy` (migrations, owns objects), `app_runtime` (DML per table; no UPDATE/DELETE on append-only; no DDL), `reporting_reader` (SELECT on `reporting.*`, read-only transactions), `backup` (read-all). Credentials via environment/secret manager; rotation procedure documented; connection limits per role; `statement_timeout`/`lock_timeout`/`idle_in_transaction_session_timeout` per role.

## 14.6 Audit
Append-only `audit_events` with actor (account; system events flagged), event type (enum catalog per module), subject, before/after snapshots (sensitive masked), reason, correlation id, IP/UA (per privacy config), timestamp. Mandatory events: auth success/failure/lockout; account lifecycle; role/permission/assignment changes; SoD overrides; configuration/brand/template/lookup changes; setup steps; approvals/rejections; every posting and reversal (success and failed attempt); reservation changes; stock overrides; cost policy changes; imports/exports; report definition changes, runs, downloads; access-denied on sensitive operations; API token lifecycle; webhook changes. Audit never records passwords/tokens. Failed-attempt records never imply success. Audit log UI with filters and export (permission `audit.event.view`, exports classified `restricted`).

## 14.7 Overrides
No universal bypass. Specific override permissions (`stock.negative.override`, `approval.sod.override`, `receiving.over_receipt.override`, `stock.expired_issue.override`, `transfers.discrepancy.accept`) each require a reason, step-up MFA, audit and (configurable) approval; all overrides are reportable.

## 14.8 Performance targets (reference environment, measured)
| Operation | Target |
|---|---|
| Item/barcode lookup | p95 < 250 ms |
| Paginated list page | p95 < 1 s |
| Stock posting | p95 < 1.5 s, p99 < 3 s (with 10 concurrent reports running) |
| Standard aggregate report (web) | p95 < 5 s |
| Interactive report timeout | 15 s |
| Export attempt window | ≤ 120 s |
| Health check | < 500 ms |
| Setup wizard step save | p95 < 1 s |
| Enum/schema meta endpoints | p95 < 100 ms (cached) |

Reference dataset: 100,000 items (+ variants), 100 warehouses, 10,000,000 movement lines, 100 concurrent interactive users, ≥ 30 simultaneous postings, 10 concurrent report executions. Each benchmark records version, PostgreSQL version, hardware, dataset, cache state, p50/p95/p99, error rate, DB CPU/IO, date. No correctness or security check is weakened for performance.

## 14.9 Application performance rules
Keyset pagination; no N+1 (tested); select needed columns; never load full histories into PHP; chunked/cursor processing; bulk inserts where semantics allow; bounded transactions; external calls outside transactions; decimal math only; cache only immutable/meta data (enums, schemas, permissions catalog, config) with invalidation on change — never stock or report results.

## 14.10 Observability
Structured JSON logs with correlation id (no secrets/payloads); metrics (request latency by route, posting latency, retry counts, deadlocks, lock timeouts, queue depth, outbox lag, report durations/cancellations, reconciliation findings, failed jobs, DB connections by role); health endpoints `/health/live`, `/health/ready` (DB per role, queue, scheduler heartbeat, storage, setup state) without secrets; alerting on failed jobs, dead outbox, reconciliation mismatch, append-only violation attempts, privilege probe failures, slow query thresholds, backup failures.

## 14.11 Backup and recovery
Daily full + continuous WAL (or hosting-equivalent); private attachments and export store backup; encrypted off-site copies; retention policy; completion monitoring; quarterly restore drill with documented RPO/RTO (targets: RPO ≤ 1 h for ledger with WAL, ≤ 24 h minimum; RTO ≤ 1 business day); restore is disaster recovery only.

## 14.12 Data protection & privacy
HTTPS; secrets in env/secret manager; encrypted sensitive columns (national id, MFA secrets, API token hashes); log hygiene; retention for exports (24 h default), audit (indefinite unless legally configured), import files (30 d), personal data minimization in reports; configurable IP/UA capture.

## 14.13 Tests
Role-escalation, warehouse-id manipulation, object-level auth, property-level auth, mass assignment, CSRF, file upload abuse, path traversal, export access, audit tampering (trigger + privilege), override paths, CSP header presence, rate limits, secrets not in responses/logs, performance benchmarks in CI nightly on reference dataset with regression thresholds.
