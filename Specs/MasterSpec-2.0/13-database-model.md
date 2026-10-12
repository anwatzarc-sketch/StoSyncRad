# 13 — DATABASE MODEL (POSTGRESQL 16+)

All tables: `id uuid PK` (v7), `created_at/updated_at timestamptz`, enum columns `varchar(64)` + generated CHECK, FKs `ON DELETE RESTRICT`, `NUMERIC` for quantities/costs. Columns listed are the minimum; Chapter 05 schemas are the authoritative detail. Reporting views live in schema `reporting`; operational tables in `public`.

## 13.1 Platform & configuration
- `platform_setup` (single row; state, steps, token hash, lock)
- `seed_runs` (name, version, checksum, status, started/finished, log)
- `configuration_entries` (key, scope_type, scope_id, value jsonb, value_type, version, effective_from, updated_by) — UQ `(key, scope_type, scope_id)`
- `configuration_entry_versions` (append-only history)
- `brand_kits` (fields §3.5, is_active partial UQ `WHERE is_active`)
- `brand_assets` (brand_kit_id, kind enum, path, checksum, mime, width, height)
- `templates`, `template_versions`
- `lookups`, `lookup_values` (UQ `(lookup_id, code)`)
- `enum_labels` (enum_class, case, locale, label, description) UQ
- `translation_overrides` (locale, key, value) UQ
- `document_sequences` (document_type, scope_type, scope_id, prefix, pattern, next_number, reset_policy) UQ

## 13.2 Identity & access
- `persons`, `user_accounts` (UQ username ci, UQ email ci; partial UQ single-account index §4.1)
- `account_credentials_history` (password hash history), `mfa_credentials`, `mfa_recovery_codes`, `trusted_devices`, `sessions`, `api_tokens` (Sanctum, abilities[])
- `permissions` (code UQ, module, resource, action, scope_kind, sensitivity, is_virtual, is_deprecated)
- `permission_implications` (permission_id, implies_permission_id)
- `permission_endpoint_bindings` (permission_id, method, route_name, uri)
- `roles` (code UQ), `role_permissions` (UQ, granted_via, constraints jsonb)
- `role_assignments` (UQ account/role/scope; effective window)
- `sod_rules`, `approval_policies`, `approval_records` (document_type, document_id, action enum, actor, comment, limit snapshot, acted_at)
- `security_events` (append-only)

## 13.3 Organization
- `company_profile` (single row), `branches` (code UQ), `warehouses` (code UQ, capabilities varchar[] with CHECK via trigger), `storage_locations` (UQ `(warehouse_id, code)`, UQ full_path), `storage_categories` (lookup-like), `putaway_rules`, `inventory_locations` (UQ code; one-to-one `storage_location_id` UQ where not null; CHECK type/warehouse consistency)

## 13.4 Catalog
- `uom_categories`, `units_of_measure` (UQ code)
- `item_categories` (UQ code, UQ full_path, parent FK, policy defaults)
- `item_category_moves`
- `items` (UQ sku), `item_images`, `item_barcodes` (UQ barcode), `item_uom_conversions` (UQ item/uom), `item_packagings`, `item_attributes`, `item_attribute_values`, `item_attribute_lines`, `item_variants` (UQ `(item_id, combination_hash)`), `item_vendor_pricelists`, `item_reorder_rules` (UQ `(item_id, item_variant_id, warehouse_id)` with sentinel), `item_kit_components`, `tags`, `item_tags`
- `qc_checklist_templates`, `qc_checklist_items`

## 13.5 Partners
- `partners` (UQ code), `partner_contacts`, `partner_addresses`, `carrier_services`

## 13.6 Inventory core (append-only marked ★)
- ★ `stock_movements` (UQ movement_number, UQ posting_event_id, UQ `(source_document_type, source_document_id, posting_event_id)`)
- ★ `stock_movement_lines` (CHECK quantity > 0; CHECK serial ⇒ quantity = 1; CHECK source ≠ destination OR source_condition ≠ destination_condition)
- `inventory_balances` (UQ position 7-tuple with sentinels; CHECK on_hand ≥ 0 unless negative flag; CHECK reserved ≥ 0; `version`)
- `stock_reservations` (partial UQ active per `(reference_line_id, position)`)
- `inventory_lots` (UQ `(item_id, lot_number)` or policy scope), `item_serials` (UQ `(item_id, serial_number)`), `quality_checks`, `quality_check_results`
- `valuation_layers`, `valuation_variances`, `landed_cost_allocations`
- `reconciliation_runs`, `reconciliation_findings`
- `recalls`, `recall_lots`
- `opening_balance_documents`, `opening_balance_lines`
- `relocations`, `relocation_lines`, `condition_changes`, `condition_change_lines`, `scrap_documents`, `scrap_lines`

## 13.7 Workflows
- Planning: `planning_forecast_inputs`, `allocation_plans`, `allocation_plan_lines`
- Procurement: `purchase_requests(+_lines)`, `purchase_orders(+_lines)`, `purchase_order_revisions`, `supplier_returns(+_lines)`, `advance_shipment_notices(+_lines)`
- Receiving: `goods_receipts(+_lines)`, `goods_receipt_serials`, `putaway_tasks(+_lines)`
- Transfers: `transfer_orders(+_lines)`, `transfer_dispatches(+_lines)`, `transfer_dispatch_serials`, `transfer_receipts(+_lines)`, `transfer_discrepancies`, `transfer_discrepancy_resolutions`
- Fulfillment: `stock_requests(+_lines)`, `pick_waves`, `pick_lists(+_lines)`, `stock_issues(+_lines)`, `internal_returns(+_lines)`, `sales_orders(+_lines)` (UQ `(external_source, external_order_id)`), `shipments`, `shipment_packages`, `shipment_package_lines`, `shipment_events`, `customer_returns(+_lines)`
- Stocktaking: `stocktakes(+_lines)`, `stocktake_counts`, `stock_adjustments(+_lines)`
- Every document: UQ `document_number` per type; `version int`; status enum CHECK; one-shot postings UQ `(document_type, document_id)` in `document_postings`

## 13.8 Reporting, messaging, audit, exchange
- `report_datasets`, `report_dataset_fields`, `report_dataset_relationships` (synced), `report_definitions`, `report_definition_versions`, `report_runs`, `report_execution_slots` (semaphore)
- `outbox_events` (index on `(status, available_at)`), `notifications`, `notification_preferences`, `webhook_endpoints`, `webhook_deliveries`
- ★ `audit_events` (actor_account_id nullable for system, event_type, subject_type, subject_id, before jsonb, after jsonb, reason, correlation_id, ip, user_agent, occurred_at) — partition by month optional
- `document_attachments` (private path, classification, checksum)
- `import_batches`, `import_rows`, `export_files`
- `idempotency_keys` (UQ scope/key; TTL purge)
- `jobs`, `failed_jobs`, `job_batches` (Laravel DB queue), `cache`, `cache_locks`

## 13.9 Append-only and privilege safeguards
- Runtime role: `SELECT, INSERT` on ★ tables; **no** `UPDATE/DELETE/TRUNCATE`. Exception: `stock_movements.status` and `stock_movement_lines.reversed_by_line_id` are updated through SECURITY DEFINER function `inventory.mark_reversed(movement_id, line_map)` owned by the deployment role, callable by runtime, which validates referential rules.
- Triggers `BEFORE UPDATE OR DELETE` on ★ tables raise `P0001 APPEND_ONLY_VIOLATION` even for superusers in application context (`current_setting('app.bypass_append_only', true) <> 'on'`); the bypass GUC is settable only by the deployment role for approved migrations and is logged.
- Reporting role: `SELECT` on `reporting.*` views only; `default_transaction_read_only = on`, `statement_timeout`, `lock_timeout` set at role level.
- Backup role: `pg_read_all_data` only.
- `inventory_balances` negative guard trigger consults `stock_negative_policy_snapshot` table maintained by config changes (so the DB does not read application config).

## 13.10 Indexing (minimum, verified with EXPLAIN on the reference dataset)
- Items: `sku`, `name` trigram (`pg_trgm`), `category_id`, `status`; barcodes `barcode`
- Balances: position UQ; `(inventory_location_id, item_id)`; `(item_id, stock_condition)`; partial `WHERE quantity_on_hand > 0`
- Movement lines: `(item_id, created_at)`, `(stock_movement_id)`, `(source_inventory_location_id, created_at)`, `(destination_inventory_location_id, created_at)`, `(inventory_lot_id)`, `(item_serial_id)`
- Movements: `(source_document_type, source_document_id)`, `(posted_at)`, `(movement_type, posted_at)`
- Lots: `(item_id, expiry_date)`, `(lock_status)`; serials `(serial_number)`, `(current_inventory_location_id)`
- Reservations: `(reference_type, reference_id)`, partial active
- Documents: `(status, warehouse_id)`, `(expected_delivery_date)`, `(source_warehouse_id, status)`, `(destination_warehouse_id, status)`, `document_number`
- Audit: `(subject_type, subject_id, occurred_at)`, `(actor_account_id, occurred_at)`, `(event_type, occurred_at)`, `correlation_id`
- Outbox: `(status, available_at)`; idempotency `(expires_at)`; report runs `(runner_account_id, started_at)`
- Config: `(key, scope_type, scope_id)`; permissions `code`; assignments `(user_account_id)`, `(scope_type, scope_id)`

## 13.11 Reporting views
One view per dataset in `reporting` schema, selecting only reportable columns, joining labels, exposing scope columns (`warehouse_id`, `branch_id`), and excluding sensitive personal/credential data. Views are created by migrations owned by the deployment role; `GRANT SELECT` to the reporting role only.

## 13.12 Tests
UUID PK introspection; enum CHECK parity; FK delete behaviour (no cascade on protected tables); append-only triggers; privilege probes for each role; negative guard; index presence; EXPLAIN plan gate for critical queries on the reference dataset (no seq scan on ledger/balance hot paths).
