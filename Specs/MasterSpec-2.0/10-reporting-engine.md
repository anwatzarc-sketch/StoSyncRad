# 10 — CUSTOM REPORTING ENGINE (100% ON-DEMAND, WEB / PDF / EXCEL)

## 10.1 Architecture

`app/Domains/ReportingEngine/` is a separately governed, read-only subsystem: dataset catalog, report definitions (versioned), filter/expression validator, query compiler, authorization injector, executor (dedicated `pgsql_reporting` connection, read-only role), run tracker, renderers (web grid, PDF, XLSX, CSV), limits, reporting audit. It owns no operational tables and holds no write privilege. It may run in the same Laravel deployment with its own worker group.

**100% custom:** every report a user sees is a definition composed from the catalog — including the shipped "standard" reports (Spec 1.0 §16 families), which are seeded definitions marked `is_system_seed = true`, read-only but cloneable.

**100% on demand:** results exist only in the HTTP response (web), or in a private, expiring export file produced by a user-initiated run. Prohibited: result tables, materialized views, scheduled runs/snapshots, refreshed datasets, persistent result caches, serving earlier results as current.

## 10.2 Dataset catalog

`report_datasets` (synced from code definitions `app/Domains/ReportingEngine/Datasets/*Dataset.php`): `code`, `label`, `description`, `source_view` (a PostgreSQL view in schema `reporting` granted to the reporting role), `primary_key`, `warehouse_scope_column(s)`, `branch_scope_column`, `classification` enum `DataClassification` (`public_internal`, `operational`, `cost_sensitive`, `personal_data`, `restricted`), `required_permission`, `feature_flag`, `fields[]` (code, label, type enum `ReportFieldType` (`uuid`, `string`, `enum`, `integer`, `decimal`, `money`, `quantity`, `date`, `datetime`, `boolean`, `json`), enum class, classification, aggregatable, groupable, filterable, sortable, `required_permission` for cost/personal fields, drill-down target), `relationships[]` (code, target dataset, join keys, cardinality, allowed), `default_sort`, `is_active`.

Initial datasets: items, item_variants, item_categories (path), units_of_measure, branches, warehouses, storage_locations, stock_positions (current balances with all quantity buckets), stock_conditions, reservations, stock_movements, stock_movement_lines, lots (with expiry horizon bucket), serials, serial_history, goods_receipts(+lines), quality_checks, purchase_requests, purchase_orders(+lines, outstanding), suppliers, supplier_returns, allocation_plans, transfer_orders(+lines), transfer_dispatches, transfer_receipts, transfer_discrepancies, in_transit_positions, stock_requests(+lines), stock_issues(+lines), internal_returns, sales_orders(+lines), shipments, customer_returns, stocktakes(+lines), stock_adjustments(+lines), valuation_layers / valuation_by_item_warehouse, reorder_status (projected vs rules), replenishment_suggestions, demand_history, lot_trace (forward), document_trace (backward), approval_records, audit_event_summaries (non-sensitive), report_runs (meta), users_access_review (restricted).

Every family from Spec 1.0 §16.1–16.3 must be expressible from these datasets; a seed test executes each shipped definition against fixture data.

## 10.3 Report definitions

`report_definitions`: `id`, `code`, `name`, `description`, `owner_account_id`, `visibility` enum `ReportVisibility` (`private`, `shared_roles`, `shared_company`), `shared_role_ids[]`, `dataset_code`, `definition jsonb` (declarative: fields, joins, filters (AND/OR groups), parameters (prompted at run), group_by, aggregates (`count`, `count_distinct`, `sum`, `avg`, `min`, `max`), calculated fields (expression AST), sort, limit, pivot (optional single-level), column formats, conditional formatting, page layout for PDF (orientation, size, header/footer template, title, logo via brand kit), chart spec (optional, web only)), `definition_version`, `schema_version`, `is_system_seed`, `is_active`, `tags`, `created_by`, timestamps; `report_definition_versions` append-only history.

Expressions use a controlled grammar (arithmetic, comparison, `coalesce`, `case`, date functions, string functions, allowed aggregates) parsed to an AST, type-checked against field types; any reference to a field requiring a permission the runner lacks rejects the run (`403 REPORT_FIELD_FORBIDDEN`) — aggregates cannot launder restricted fields.

## 10.4 Query compiler

1. Validate dataset active + permitted + feature-enabled.
2. Validate every field, relationship (≤ `reporting.max_joins`, default 6), operator per type, expression AST, aggregation rules (every non-aggregated select is grouped).
3. **Inject authorization constraints**: warehouse/branch scope predicates derived from the runner's effective assignments (per dataset scope columns, including through joins — each joined dataset gets its own scope predicate), classification/field permissions, record-level predicates (`own_records`).
4. Bind all values as parameters; allow-list identifiers from catalog metadata only; never concatenate client strings.
5. Apply limits: row cap (`reporting.max_sync_rows` 10,000 web; export cap `reporting.max_export_rows` configurable), `statement_timeout` (interactive 15 s; export 120 s per attempt), estimated cost gate via `EXPLAIN` (configurable threshold → `422 REPORT_TOO_EXPENSIVE` with guidance).
6. Emit SQL for the `reporting` schema views only.

## 10.5 Execution

- Connection `pgsql_reporting` (read-only role; `SET TRANSACTION READ ONLY`; `default_transaction_read_only = on` at role level), separate pool budget (`reporting.max_connections`), short `lock_timeout`.
- Web runs: single statement, consistent snapshot by statement; paginated server-side with keyset on the definition's sort + PK; each page is a new on-demand query (results show execution timestamp per page; no cross-page consistency claim).
- Exports (PDF/XLSX/CSV): user-initiated job on the `reporting` queue; `REPEATABLE READ READ ONLY` transaction for multi-query consistency; streamed row cursors (`DECLARE … CURSOR`) and streaming writers (OpenSpout for XLSX/CSV; no full-result arrays in PHP); cancellation (`pg_cancel_backend` via run token); time-limited snapshot monitored.
- Concurrency caps: interactive (`reporting.max_concurrent_interactive`) and export workers (`reporting.max_export_workers`), enforced with a DB semaphore table; excess returns `429 REPORT_CAPACITY` with retry-after.
- Stock-posting latency SLO is measured with reports running concurrently (Chapter 14).

## 10.6 Outputs

- **Web view:** data grid with column formats, grouping bands, totals, pagination, drill-down links to authorized records, chart (optional), run metadata (definition version, filters, timestamp, row count/truncation warning, duration).
- **PDF:** renderer enum `PdfRenderer` (`dompdf` default — shared-hosting friendly; `chromium` optional via Browsershot when available); layout templates `report.pdf.layout.*` with brand letterhead, page numbers, run metadata footer, repeating headers, landscape/portrait, configurable page size; row cap for PDF (`reporting.max_pdf_rows`) with clear truncation notice; large PDFs always via export job.
- **Excel (XLSX):** mandatory; streaming writer; typed cells (numbers as numbers, dates as dates, enums as labels with optional raw code column), frozen header, auto filter, column widths, number formats from locale config, a metadata sheet (report name, definition version, filters, run timestamp, runner, row count, truncation flag), optional grouping subtotals sheet.
- **CSV:** streaming, UTF-8 BOM optional (config), delimiter configurable.
- All exports stored on the private disk `reports/{run_id}/…`, downloadable only via authorized signed route with re-authorization at download time; retention `reporting.export_retention_hours` (default 24); purge job; download events audited.

## 10.7 Runs and audit

`report_runs`: `id`, `definition_id`, `definition_version`, `runner_account_id`, `output_format` enum `ReportOutputFormat` (`web`, `pdf`, `xlsx`, `csv`), `parameters jsonb` (sensitive values minimized), `authorized_scope_snapshot jsonb`, `status` enum `ReportRunStatus` (`queued`, `running`, `completed`, `failed`, `cancelled`, `rejected`, `expired`), `started_at`, `finished_at`, `duration_ms`, `row_count`, `truncated`, `file_path`, `file_size`, `file_expires_at`, `error_code`, `correlation_id`. Runs store metadata only — never results. Reporting audit covers definition CRUD/sharing, runs, cancellations, rejections, downloads.

## 10.8 Builder UX requirements

Guided mobile flow (dataset → fields → filters → group/aggregate → sort → validate → run → view → export → save) and desktop workspace (config panel + live preview on a 200-row sample run); field picker shows classification badges and disabled restricted fields with reason; filter builder with type-aware operators; expression editor with validation feedback; saved reports list (mine / shared / system); run history with status polling (`202 Accepted` → poll `GET /report-runs/{id}`); never auto re-submit while polling.

## 10.9 Isolation guarantees (tested)

Reporting role cannot write any operational table (probe tests); warehouse scope cannot be bypassed via joins, calculated fields, grouping, exports, saved definitions or parameter tampering; restricted fields never leak via aggregates/expressions; injection strings are values; invalid joins/expressions rejected; timeouts cancel queries and never affect postings; exports only on request; each run queries live data (test: post a movement, re-run, see it); workers cannot exhaust the connection budget.
