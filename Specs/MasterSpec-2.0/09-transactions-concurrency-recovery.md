# 09 — TRANSACTION ATOMICITY, CONCURRENCY, IDEMPOTENCY AND RECOVERY

## 9.1 Atomicity definition

A stock-changing operation is atomic when all required writes commit together or none do: document posted state + accepted line quantities + ledger movement + lines + balance projections + reservation changes + lot/serial state + valuation layers + success audit event + idempotency result + outbox event. No split transactions; no "commit document then write movements".

## 9.2 Mandatory posting sequence (every `Post*Action`)

1. **Validate request** — schema validation (Chapter 05), idempotency key presence (`Idempotency-Key` header or `idempotency_key` field; UUID v4/v7).
2. **Authenticate & authorize** — actor from session/token; permission + warehouse scope for every involved warehouse; document access; approval requirements; SoD; step-up MFA when `critical`.
3. **Idempotency pre-check** — look up `(scope, key)`; if `completed` with same payload hash → return stored result (`200` with `Idempotent-Replay: true`); if `in_progress` → `409 IDEMPOTENT_IN_PROGRESS`; if hash differs → `422 IDEMPOTENCY_PAYLOAD_MISMATCH`.
4. **Begin transaction** on the primary `pgsql` connection (`READ COMMITTED`), set `lock_timeout` and `statement_timeout` from config.
5. **Claim idempotency key** — `INSERT … ON CONFLICT DO NOTHING RETURNING` into `idempotency_keys` with `in_progress` (within the transaction; the unique constraint is the race breaker).
6. **Lock the business document** — `SELECT … FOR UPDATE` on header (and relevant lines); verify state and `version`; reject already-posted (`409 ALREADY_POSTED`) unless a distinct partial posting event is permitted.
7. **Resolve and lock positions** — compute all affected position keys (source/destination × lot × condition × serial), deduplicate, sort by `(item_id, item_variant_id, inventory_location_id, inventory_lot_id, stock_condition, owner, package)` as a global total order, upsert missing balance rows (`INSERT … ON CONFLICT DO NOTHING`), then `SELECT … FOR UPDATE` in that order; lock reservation and lot rows in the same ordering tier after balances; lock serial rows last.
8. **Revalidate under locks** — availability, reservations, lot QC/lock/expiry, serial state, condition eligibility, UoM conversion, approvals, permitted quantity, over-receipt tolerance, frozen positions, costing inputs.
9. **Allocate document number** (if posting creates a numbered record) under the sequence row lock.
10. **Write ledger** — movement header + lines (positive, base UoM, snapshots).
11. **Update projections** — balances (`version + 1`), reservations, lot statuses, serial positions, valuation layers/averages, document line projections (received/dispatched/fulfilled), derived document status.
12. **Write audit** (`audit_events`, success) and **outbox** (`outbox_events`, `pending`).
13. **Complete idempotency** — update row to `completed` with response payload hash + minimal result.
14. **Commit**.
15. **Return** `201/200` with the committed document and movement references. Never return success before commit.

Retry wrapper: the action body runs inside `TransactionRetry::run(maxAttempts: config('db.transaction.max_attempts', 3))` that retries only on SQLSTATE `40P01` (deadlock) and `40001` (serialization) with jittered backoff, re-running from step 4 with fresh reads; the idempotency claim is part of the retried transaction so the row is rolled back and re-claimed.

## 9.3 Transaction scope exclusions

Inside the transaction, never: SMTP, HTTP to external systems, file generation, queue dispatch that is not `afterCommit`, user interaction, unbounded loops, report generation. Post-commit work is expressed as outbox events.

## 9.4 Transactional outbox

`outbox_events`: `id`, `event_type`, `aggregate_type`, `aggregate_id`, `payload jsonb` (minimal), `correlation_id`, `status` enum `OutboxStatus` (`pending`, `processing`, `processed`, `failed`, `dead`), `attempts`, `available_at`, `processed_at`, `last_error`, `created_at`. Relay worker (`outbox:relay`) claims rows with `SELECT … FOR UPDATE SKIP LOCKED`, dispatches handlers (notifications, webhooks, integration publishers, search index), marks processed; handlers are idempotent on `event id`; bounded retries then `dead` with alert. Laravel jobs needing committed data use `->afterCommit()`.

## 9.5 Idempotency

`idempotency_keys`: `id`, `scope_type` enum (`account`, `document`, `api_client`), `scope_id`, `key`, `operation`, `request_hash`, `status` enum `IdempotencyStatus` (`in_progress`, `completed`, `failed`), `response_code`, `response_body jsonb`, `result_document_id`, `created_at`, `expires_at` (config `api.idempotency.ttl_hours`, default 72). Unique `(scope_type, scope_id, key)`. Failed business validations store `failed` so a retry re-executes (payload may have been corrected → different hash → new key expected; same hash → re-run allowed).

Document-level duplicate protection in addition: unique `posting_event_id` on movements; unique `(document_type, document_id)` for one-shot postings; unique `(source_line_id, dispatch_event_id)` for partial events.

## 9.6 Concurrency controls

- Isolation: `READ COMMITTED` + explicit row locks + unique constraints. `SERIALIZABLE` only for explicitly identified multi-row conditions (e.g. allocation plan approval across many positions) with retry.
- Lock ordering convention is global and documented; a static test asserts all actions call `PositionLockSet::sorted()`.
- Availability is validated after locks; two concurrent 70-unit issues on 100 available: one succeeds, the other receives `409 INSUFFICIENT_STOCK` with available quantity.
- Reservations: locked with their positions; no duplicate active reservation (unique index); no double consumption (consumed ≤ reserved check under lock).
- Transfers: dispatch locks source positions; receipt locks transfer header, dispatch lines and in-transit/destination positions; receipt cannot exceed outstanding; duplicate receipt events rejected by uniqueness.
- Document numbering: sequence row lock.
- Optimistic `version` on documents for edits (`409 STALE_VERSION`) — pessimistic locks for postings.
- Multi-process safety: no in-memory mutexes; database is the coordinator.

## 9.7 Timeouts

Config `db.connect_timeout_ms`, `db.lock_timeout_ms` (default 5000), `db.statement_timeout_ms` (default 15000 for operational, 15000 interactive reporting, 120000 export), `db.idle_in_transaction_timeout_ms`, HTTP request timeout, job timeouts, export timeouts. A timed-out posting rolls back; the client receives `504 OPERATION_TIMEOUT` with guidance to re-submit with the same idempotency key.

## 9.8 Error classification

SQLSTATE → outcome: `40P01`, `40001` → retry; `55P03` (lock not available) → `409 LOCK_TIMEOUT` (no retry by default; client may retry same key); `23505` → if on idempotency table → replay path; if on business uniqueness → `409 DUPLICATE_<RESOURCE>`; `23514` → `422 CONSTRAINT_VIOLATION` (indicates application bug; alert); `23503` → `422 REFERENCE_NOT_FOUND`; `57014` (query cancelled) → `504`; others → `500` with correlation id. Business exceptions (`InsufficientStock`, `InvalidTransition`, `Unauthorized`, `SodViolation`, `LotMismatch`) are never retried.

## 9.9 Rollback before commit

PostgreSQL rollback leaves no partial posting, movement, projection, reservation, audit success, outbox, idempotency completion or status. After rollback, the action writes a **failed-attempt audit** via a separate connection/transaction (`audit.posting_failed`, with reason, correlation id) — never implying stock moved; if that write fails, the failure is logged to the protected application log and metrics.

## 9.10 Uncertain commit outcomes

If the connection drops around commit: the client re-sends with the same `Idempotency-Key`; the server resolves by querying `idempotency_keys` (and the unique posting identity) — `completed` → replay; `in_progress` older than `api.idempotency.stale_after_seconds` with no committed movement → treated as failed and re-executed; otherwise `409 IDEMPOTENT_IN_PROGRESS`. `GET /api/v1/operations/{idempotency_key}` exposes the outcome for clients. Never a second movement because a request timed out.

## 9.11 Rollback vs. reversal

- *Database rollback* undoes an uncommitted transaction.
- *Business reversal* records a new compensating posting (Chapter 07 §7.11) with original references, reason, actor, approval, timestamp, valuation effects and eligibility checks. Originals stay immutable and visible.
- Transfers after dispatch cannot be "un-dispatched"; corrections are receipts, returns, loss adjustments or data-correction reversals through discrepancy workflow.
- Database restore is disaster recovery only (Chapter 16), never a correction tool.

## 9.12 Tests (mandatory, PostgreSQL)

Failure injection at each of the eight points (after state validation, after locks, after first ledger line, after one projection, after reservation change, after audit, after outbox, before commit) proves zero partial state and safe retry; commit-ambiguity tests (committed/response lost; not committed); concurrent issue 2×70 on 100 (plain, with reservations, lot-specific); concurrent dispatches; duplicate receipt; deadlock classification and bounded retry without duplicated external effects; lock-timeout response; idempotency replay/mismatch/in-progress; outbox not present on rollback; outbox relay idempotent under duplicate delivery.
