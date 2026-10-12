# 11 — API CONTRACT AND INTEGRATION

## 11.1 Principles

REST, resource-oriented, versioned (`/api/v1`), JSON, UUID identifiers, enum codes (labels available via meta endpoints), server-controlled state, idempotent mutations, uniform error envelope, one permission per endpoint, OpenAPI generated from schemas and route metadata (`api:docs:generate`, served at `/api/docs` to permitted accounts).

Same-origin Inertia pages use the same actions through web controllers; business logic lives in Actions, never duplicated between web and API controllers.

## 11.2 Resource and operation conventions

- `GET /api/v1/{resources}` list (filters, sort, keyset pagination `cursor`/`limit`, field selection `fields=`), `GET /{id}` show (with `permitted_actions[]` computed server-side), `POST` create, `PATCH /{id}` edit permitted properties on editable states (optimistic `version` required), `DELETE /{id}` only for drafts whose lifecycle allows.
- Business operations are sub-resources or operation resources, never status patches:
  - `POST /purchase-orders/{id}/submissions`, `/approvals`, `/rejections`, `/cancellations`, `/revisions`
  - `POST /goods-receipts/{id}/postings` (posting event), `/goods-receipts/{id}/qc-releases`
  - `POST /transfers/{id}/dispatches`, `/transfers/{id}/receipts`, `/transfers/{id}/discrepancies/{d}/resolutions`
  - `POST /stock-requests/{id}/approvals`, `POST /stock-issues/{id}/postings`
  - `POST /sales-orders`, `/sales-orders/{id}/confirmations`, `/pick-lists`, `/shipments`, `/shipments/{id}/handovers`, `/sales-orders/{id}/deliveries`
  - `POST /stocktakes/{id}/counts`, `/recounts`, `/approvals`; `POST /stock-adjustments/{id}/postings`
  - `POST /stock-movements/{id}/reversals`
  - `POST /report-runs`, `GET /report-runs/{id}`, `POST /report-runs/{id}/cancellations`, `GET /report-runs/{id}/download`
  - `GET /operations/{idempotency_key}` outcome lookup
  - `GET /meta/enums`, `/meta/permissions`, `/meta/schemas/{model}`, `/meta/config/effective?keys=`, `/meta/brand`
- Status codes: `200`, `201`, `202` (async accepted), `204`, `400` (malformed), `401`, `403` (`PERMISSION_DENIED`, `FEATURE_DISABLED`, `PROPERTY_FORBIDDEN`), `404`, `409` (state/concurrency/idempotency), `413`, `415`, `422` (validation/business), `423` (position frozen / lot locked may use 409 with code), `429`, `500`, `503` (`SETUP_REQUIRED`, maintenance), `504` (`OPERATION_TIMEOUT`).

## 11.3 Headers

`Idempotency-Key` (required on every posting/operation POST; optional on creates), `X-Correlation-Id` (echoed; generated if absent), `X-Request-Version` (optimistic version on PATCH alternative to body field), `Accept-Language`, `Idempotent-Replay: true` on replays, `Retry-After` on 429/503, `Cache-Control: no-store` on every authenticated response.

## 11.4 Error envelope

```json
{
  "code": "VALIDATION_FAILED",
  "message": "The request contains invalid properties.",
  "correlation_id": "uuid",
  "errors": [
    { "property": "lines.2.stock_condition", "code": "ENUM_INVALID",
      "message": "`stock_condition` must be one of: available, quarantine, damaged, expired, blocked. Received: `ok`.",
      "allowed_values": ["available","quarantine","damaged","expired","blocked"], "received": "ok" },
    { "property": "quantity", "code": "RANGE_INVALID", "message": "...", "min": "0.000001" }
  ],
  "recovery": { "hint": "Correct the listed properties and resubmit with the same Idempotency-Key." }
}
```

Business errors use the same envelope with `code` such as `INSUFFICIENT_STOCK` (+ `available_quantity`), `INVALID_STATE_TRANSITION` (+ `from`, `to`, `allowed`), `SOD_VIOLATION`, `LOT_MISMATCH`, `LOT_LOCKED`, `POSITION_FROZEN`, `ALREADY_POSTED`, `STALE_VERSION`, `REVERSAL_BLOCKED_DOWNSTREAM` (+ `dependents[]`), `APPROVAL_REQUIRED`, `APPROVAL_LIMIT_EXCEEDED`, `IDEMPOTENT_IN_PROGRESS`, `IDEMPOTENCY_PAYLOAD_MISMATCH`, `REPORT_TOO_EXPENSIVE`, `REPORT_CAPACITY`. Never SQL, stack traces or internal paths.

## 11.5 Responses

Resource responses expose business fields, enum codes, computed quantities with explicit buckets, `permitted_actions` (server-computed from permission + state + scope), `links` for related resources, `version`, timestamps in UTC ISO-8601, money as `{ amount: "123.4500", currency: "ETB" }`, quantities as decimal strings (never JSON floats). Cost fields are omitted (not nulled) when the caller lacks `inventory.cost.view`.

## 11.6 Lists

Keyset pagination default (`cursor`), optional offset for small admin lists; max `limit` 200; filters are allow-listed per endpoint from the schema (`searchable`/`filterable`); sort allow-listed; warehouse scope always injected server-side; `include=` for approved relationships only; N+1 prevented by tests (`assertQueryCountLessThan`).

## 11.7 Webhooks and outbox publishing

`webhook_endpoints` (url, secret, events[], status, retry policy) fed by outbox events; HMAC-SHA256 signature header; retries with backoff; dead-letter view; event payloads minimal with resource links; receivers must GET for details with their own credentials. Published events include posting events, approvals, status changes, low-stock, expiry alerts, report run completion, setup completion, config changes (non-sensitive).

## 11.8 Inbound integrations

- E-commerce/POS order intake: `POST /sales-orders` with `external_source`, `external_order_id` (unique per source), channel, lines by SKU/barcode; mapping errors returned per line.
- Carrier status webhooks: `POST /integrations/carriers/{carrier}/events` with signature validation → shipment status updates (never stock changes without the fulfillment action).
- Supplier ASN (feature-flag): `POST /purchase-orders/{id}/advance-shipment-notices` pre-fills receipts.
- All inbound integrations authenticate as service accounts with scoped tokens and are rate-limited and audited.

## 11.9 Imports (CSV/XLSX)

Supported: items (full Odoo-level columns), variants, categories, UoM, barcodes, packagings, vendor pricelists, reorder rules, branches, warehouses, storage locations, partners, lots (registration), opening balances, roles (permission codes), configuration bundles. Flow: upload → structure validation → row/field validation via the same property schemas (errors by row/field/code) → preview → confirm → background job in chunks with progress → result file (accepted/rejected rows) → audit with import batch id. Imports never post stock; opening balances create drafts. Re-upload of the same batch checksum is idempotent.

## 11.10 Exports

Operational list exports (CSV/XLSX) run through the reporting engine datasets so classification, scope and limits apply uniformly.

## 11.11 Rate limiting and quotas

Per account and per token: `api.rate_limit.*`; posting endpoints lower limits; report runs capped per account per hour; limits configurable; `429` with `Retry-After`.

## 11.12 Tests

Contract tests from OpenAPI (schemathesis-style fuzz on enums/types); permission-per-route audit; status code matrix; idempotency header required on postings; envelope shape; cost field omission; keyset pagination stability; webhook signature/retry; import validation parity with API validation.
