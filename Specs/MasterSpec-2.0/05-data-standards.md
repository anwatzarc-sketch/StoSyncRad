# 05 — DATA STANDARDS: UUID KEYS, ENUMS, SCHEMA REGISTRY, GLOBAL VALIDATION

## 5.1 Primary keys: UUID everywhere

- Every table's primary key is `id uuid NOT NULL PRIMARY KEY`. No auto-increment integers anywhere, including pivot, audit, outbox, sequence and configuration tables.
- Generation: **UUID v7** (time-ordered) generated application-side (`Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids` / `Str::uuid7()`), so B-tree index locality is preserved for high-volume tables (ledger, audit, outbox). Never v4 for primary keys.
- Foreign keys are `uuid` with explicit `ON DELETE RESTRICT` (default) — cascading deletes are forbidden on any table that references posted documents, ledger, audit or configuration history.
- Human-readable identifiers (document numbers, SKU, warehouse code) are separate, unique, non-key columns.
- URLs and API payloads expose UUIDs only; sequential ids are never leaked. Route model binding validates UUID format before querying (`422 INVALID_IDENTIFIER` on malformed, `404` on unknown).
- Base model `App\Support\Eloquent\BaseModel` applies the UUID trait, `$keyType = 'string'`, `$incrementing = false`, strict mode (`preventLazyLoading`, `preventSilentlyDiscardingAttributes`, `preventAccessingMissingAttributes`).
- A test (`UuidPrimaryKeyTest`) introspects `information_schema` and fails if any table has a non-uuid PK or a serial column.

## 5.2 Enums: typed, validated at every layer

### Definition
- Every property whose value set is a known list or range is a **PHP 8.3 backed enum** (`string` backing, `snake_case` cases) under `app/Domains/<Module>/Enums/` (cross-cutting enums under `app/Support/Enums/`).
- Each enum implements `App\Support\Enums\SystemEnum` interface: `label()`, `description()`, `values(): array`, `tryFromInput(mixed)`, optional `transitions(): array` for state enums and `isTerminal()`.
- Enum **semantics** are fixed in code; **labels** are configurable per locale (Chapter 03 §3.7). Lists whose values carry no code semantics are lookups, not enums.

### Database layer
- Enum columns are `VARCHAR(64)` with a named `CHECK` constraint `chk_<table>_<column>_enum` whose allowed values are generated from the PHP enum by the migration helper `$table->enumColumn('status', DocumentStatus::class)`.
- Changing an enum's cases requires a migration that updates the CHECK constraint; CI test `EnumConstraintParityTest` compares every enum-bound column's CHECK values to the PHP enum cases and fails on drift.
- State enums have an additional transition table in code; invalid transitions are rejected by actions with `409 INVALID_STATE_TRANSITION`.

### Application layer
- Eloquent casts use the enum class (`'status' => DocumentStatus::class`); assigning a non-case value throws before persistence.
- Validation of enum inputs uses `EnumRule(Enum::class)`; the error message is explicit: *"`status` must be one of: draft, submitted, approved. Received: `foo`."* with machine payload `{ code: ENUM_INVALID, allowed_values: [...], received: "foo" }`.
- Ranges (numeric or date bounds) are declared in the property schema (§5.3) and validated with the same messaging (`RANGE_INVALID`, `min`, `max`).

### Frontend and API
- `GET /api/v1/meta/enums` returns all enums with cases, labels (current locale) and descriptions.
- `php artisan enums:export-ts` generates `resources/js/types/enums.generated.ts` (string-literal unions + label maps). CI fails if the generated file is stale.
- Appendix A is generated from the same source (`php artisan enums:export-md`).

## 5.3 Table property schemas

Every model/table has a declarative **schema** (`app/Domains/<Module>/Schemas/<Model>Schema.php`) listing each property:

```
'status' => Property::enum(DocumentStatus::class)->default('draft')->immutableAfter('posted')
'quantity' => Property::decimal(20, 6)->min('0.000001')->required()
'expiry_date' => Property::date()->nullable()->after('manufacture_date')
'name' => Property::string(max: 200)->required()->trim()
'warehouse_id' => Property::uuid()->references(Warehouse::class)->required()->scopeKey()
'metadata' => Property::json()->schema(MetadataSchema::class)
```

Property attributes: `type` enum `PropertyType` (`uuid`, `string`, `text`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `time`, `enum`, `json`, `money`, `quantity`, `percentage`, `email`, `phone`, `url`, `barcode`, `file_ref`), nullability, default, `required_on` (`create` | `update` | `always`), length/precision/scale, min/max, pattern, enum class, FK target + existence rule, `writable_by` (permission required to set), `immutable_after` (state), `server_controlled` (never accepted from clients), `sensitive` (masked in audit/logs), `reportable` (exposed to the reporting catalog with classification), `searchable`, `sortable`, `label`, `help`.

The schema is the single source for:
1. Validation rules (global middleware + form requests)
2. Mass-assignment allow-list (`$fillable` is derived; `server_controlled` properties are never fillable)
3. API documentation (OpenAPI components generated by `api:docs:generate`)
4. TypeScript types (`types:export-ts`)
5. Reporting catalog field metadata (Chapter 10)
6. Migration parity test (`SchemaMigrationParityTest`: column type/nullability/precision match the schema)

## 5.4 Global validation middleware

Middleware `ValidateAgainstSchema` is registered in the `api` and `web` middleware groups for all unsafe methods (`POST`, `PUT`, `PATCH`, `DELETE` with body). Route metadata declares the target schema and operation: `->schema(ItemSchema::class, op: 'create')`; nested line schemas are declared as `lines: ItemLineSchema::class`.

Pipeline for every write request:

1. **Content-type & size** — JSON (or multipart for uploads) ≤ `api.max_body_kb`; otherwise `413 PAYLOAD_TOO_LARGE` / `415`.
2. **Unknown properties** — any key not in the schema → `422 UNKNOWN_PROPERTY` (strict by default; `api.allow_unknown_properties=false`).
3. **Server-controlled properties present** → `422 SERVER_CONTROLLED_PROPERTY` (e.g. `posted_at`, `approved_by`, `status` on generic updates, `unit_cost_snapshot`).
4. **Type coercion & validation** per property: type (`TYPE_MISMATCH`), required (`REQUIRED`), null (`NULL_NOT_ALLOWED`), length (`LENGTH_EXCEEDED`), enum (`ENUM_INVALID` with allowed values), range (`RANGE_INVALID`), precision/scale (`PRECISION_EXCEEDED` — quantities beyond scale are rejected, never silently rounded), pattern (`FORMAT_INVALID`), date ordering (`DATE_ORDER_INVALID`), UUID format (`INVALID_IDENTIFIER`), FK existence and active status (`REFERENCE_NOT_FOUND` / `REFERENCE_INACTIVE`), nested lines (indexed errors `lines.3.quantity`).
5. **Writable-by** — property requires permission the actor lacks → `403 PROPERTY_FORBIDDEN` listing the property.
6. **Immutable-after** — property changes on a document whose state forbids it → `409 PROPERTY_IMMUTABLE`.
7. Hands a typed, validated DTO to the route-specific FormRequest/Action, which adds **business** rules (stock availability, SoD, approvals). Business validation never re-implements type/enum checks.

All failures use the standard error envelope (Chapter 11 §11.4) and are logged with correlation id (payload values are masked for `sensitive` properties).

`php artisan schema:audit-routes` fails CI when a write route lacks a schema declaration.

## 5.5 Numeric precision and units

- Quantities: `NUMERIC(20,6)`; unit-cost snapshots: `NUMERIC(20,8)`; monetary document totals: `NUMERIC(20,4)` with currency code; percentages: `NUMERIC(9,6)`.
- PHP uses `bcmath`/`Brick\Math` decimal types — never floats — for quantities, costs, conversions, valuation.
- Rounding occurs only at defined boundaries (document total, valuation layer close, display) using `RoundingMode` configured per boundary (`half_even` default for money, `half_up` for display).
- Serialized items: whole units only (`CHECK (quantity = trunc(quantity))` enforced when `tracking_mode = serial`).
- Unit conversions store `conversion_factor_snapshot NUMERIC(20,10)` on every document line.

## 5.6 Time

- All timestamps `timestamptz` in UTC; display via configured timezone (`locale.timezone`), branch override allowed for display only.
- Business-effective dates (`document_date`, `received_on`) are `date` columns distinct from system `posted_at`.
- Backdating is permitted only within `stock.backdating_window_days` and never bypasses current stock checks; the posting timestamp is always "now".

## 5.7 Archival and deletion

- Master data uses `status` enums + `archived_at`; no hard deletes once referenced.
- Posted documents, ledger, audit, outbox, configuration history: no `DELETE` privilege for the runtime role; append-only triggers (Chapter 13 §13.9).
- Soft-delete (`deleted_at`) is permitted only for unreferenced drafts and user preferences.

## 5.8 Naming conventions

- Tables: plural snake_case; columns snake_case; enum cases snake_case; permission codes `module.resource.action`; config keys `domain.group.key`; events `module.subject.verb_past`.
- Every table has `created_at`, `updated_at` (`timestamptz`), and where user-authored `created_by`, `updated_by` (uuid FK to `user_accounts`).

## 5.9 Tests (mandatory)

- UUID PK introspection; v7 ordering sanity.
- Enum/CHECK parity for every enum-bound column; invalid enum value rejected at API (`ENUM_INVALID` with allowed list), at model cast, and at DB (constraint violation caught in integration test).
- Unknown/server-controlled/immutable property rejections with correct codes.
- Precision overflow rejected (no silent rounding).
- Generated TS enums/types and OpenAPI are current (CI diff).
- Every write route declares a schema.
