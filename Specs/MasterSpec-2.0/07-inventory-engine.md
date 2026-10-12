# 07 — INVENTORY ENGINE (UNIFIED LEDGER)

## 7.1 Stock quantities (never interchangeable)

| Quantity | Definition |
|---|---|
| On hand | Physical quantity in a stock position (any condition) |
| Reserved | Portion of eligible on-hand committed to an approved request, pick, sales order or transfer |
| Available | Eligible on-hand − active reservations − removal-window blocked (configurable) |
| Quarantined | Awaiting QC release |
| Damaged | Recorded damaged; not issuable |
| Expired | Past expiry; blocked by default |
| Blocked | Administratively prohibited |
| In transit | Dispatched from source, not yet received at destination; lot-locked |
| Inbound (forecast) | Outstanding PO + approved undispatched/dispatched transfers to this warehouse |
| Outbound (forecast) | Approved unfulfilled requests/sales/transfers from this warehouse |
| Forecasted | On hand + inbound − outbound − reserved |

Eligibility for availability = condition `available` AND (no expiry OR expiry > today + removal offset unless policy allows) AND lot status `released` AND not in transit AND serial status `in_stock`.

## 7.2 Stock conditions

Enum `StockCondition`: `available`, `quarantine`, `damaged`, `expired`, `blocked`, `returned_pending_inspection`. Condition is a dimension of the stock position; moving between conditions is a ledger movement (same inventory location, different condition) through an authorized action (`ReleaseFromQuarantineAction`, `MarkStockDamagedAction`, `BlockStockAction`, `ExpireStockAction` — the last may be system-initiated by the expiry monitor, audited as system actor).

## 7.3 Stock position identity

`(item_id, item_variant_id, inventory_location_id, inventory_lot_id, stock_condition, owner_partner_id, package_id)` — the last two dimensions are feature-flagged (consignment, packages) and default to a sentinel UUID `00000000-0000-7000-8000-000000000000` rather than NULL so that the unique constraint is total. `inventory_lot_id` uses the same sentinel for untracked items. Unique constraint `inventory_balances_position_uq`.

## 7.4 Ledger

`stock_movements` (header, append-only): `id`, `movement_number` (unique), `movement_type` enum `MovementType` (`receipt`, `supplier_return`, `transfer_dispatch`, `transfer_receipt`, `issue`, `sale_delivery`, `customer_return`, `internal_return`, `adjustment_in`, `adjustment_out`, `condition_change`, `relocation`, `opening_balance`, `scrap`, `production_issue`, `production_receipt`, `reversal`, `cross_dock`), `source_document_type` enum `DocumentType`, `source_document_id`, `source_document_number`, `posting_event_id` (unique; identifies a partial posting such as the nth dispatch), `idempotency_key_id`, `status` enum `MovementStatus` (`posted`, `reversed`, `partially_reversed`), `posting_actor_id`, `posted_at`, `business_date`, `reversal_of_movement_id`, `reason_lookup_id`, `reason_text`, `correlation_id`, `created_at`.

`stock_movement_lines` (append-only): `id`, `stock_movement_id`, `line_no`, `item_id`, `item_variant_id`, `source_inventory_location_id`, `destination_inventory_location_id`, `source_condition`, `destination_condition`, `inventory_lot_id`, `item_serial_id`, `quantity` (> 0, base UoM), `base_uom_id`, `document_uom_id`, `document_quantity`, `conversion_factor_snapshot`, `unit_cost_snapshot`, `total_cost_snapshot`, `currency`, `source_line_type`, `source_line_id`, `reversed_by_line_id`, `created_at`.

Rules: positive quantity; source ≠ destination unless condition differs; serialized lines have quantity 1 and a serial; lot-tracked items require a lot; costs snapshotted; no UPDATE/DELETE (DB trigger + privilege, Chapter 13). `posting_event_id` + unique index prevents double posting of the same event; partial workflows use distinct events.

## 7.5 Balance projection

`inventory_balances` per position: `quantity_on_hand`, `quantity_reserved`, `quantity_in_transit_outbound` (for source lanes), `unit_cost_projection`, `total_value_projection`, `last_movement_at`, `last_counted_at`, `version` (optimistic counter), `updated_at`. Check `quantity_on_hand >= 0` and `quantity_reserved <= quantity_on_hand` unless the warehouse negative policy permits (enforced by action + partial check via trigger reading config snapshot).

Updated in the same transaction as the ledger (Chapter 09). `inventory:reconcile` recomputes balances from the ledger (chunked SQL, per item/location) and reports differences to `reconciliation_runs` / `reconciliation_findings`; `inventory:rebuild-projection --position=` repairs with a lock, audit and reason — never touching the ledger.

## 7.6 Reservations

`stock_reservations`: `id`, `reference_type` enum `ReservationReferenceType` (`stock_request`, `sales_order`, `transfer_order`, `pick_list`, `manual`), `reference_id`, `reference_line_id`, `item_id`, `item_variant_id`, `inventory_location_id`, `inventory_lot_id`, `item_serial_id`, `quantity_reserved`, `quantity_consumed`, `status` enum `ReservationStatus` (`active`, `partially_consumed`, `consumed`, `released`, `expired`), `expires_at`, `created_by`, `released_by`, `release_reason`, timestamps. Unique active reservation per `(reference_line_id, position)`.

Actions `ReserveStockAction`, `AdjustReservationAction`, `ConsumeReservationAction`, `ReleaseReservationAction` lock the position and reservation rows in global order; reservation cannot exceed available; consumption cannot exceed reserved; release is idempotent; cancellation of the referencing document releases outstanding reservations in the same transaction; expiry monitor releases stale reservations per `stock.reservation.auto_release_days` (audited, system actor).

## 7.7 Lots, expiry and QC

`inventory_lots`: `id`, `item_id`, `item_variant_id`, `lot_number`, `supplier_id`, `supplier_lot_reference`, `manufacture_date`, `expiry_date`, `best_before_date`, `removal_date` (computed), `alert_date` (computed), `origin_document_type/id` (first receipt), `qc_status` enum `LotQcStatus` (`pending`, `released`, `rejected`, `conditional`), `qc_released_by/at`, `lock_status` enum `LotLockStatus` (`unlocked`, `locked_in_transit`, `locked_investigation`, `locked_recall`), `status` enum `LotStatus` (`active`, `depleted`, `expired`, `recalled`, `archived`), `attributes jsonb`, timestamps. Unique `(item_id, lot_number)` (policy `catalog.lot_uniqueness_scope` may widen to `(item_id, supplier_id, lot_number)`).

- Lot assignment at receipt (QC step) is mandatory for lot/serial items; generated per pattern if `generated`.
- Expiry monitor (scheduler, idempotent) transitions conditions to `expired` at expiry and raises alerts at configured horizons; `removal_date` blocks sale/issue (configurable strictness).
- Quality checks: `quality_checks` (document line, checklist template, results jsonb, outcome enum `QcOutcome` (`pass`, `fail`, `conditional`), inspector, timestamps, evidence attachments). A failed check routes quantity to `damaged`/`quarantine` per policy; release requires `receiving.qc.release`.

## 7.8 Serials

`item_serials`: `id`, `item_id`, `item_variant_id`, `serial_number` (unique per item; config may enforce global uniqueness), `inventory_lot_id`, `lifecycle_status` enum `SerialStatus` (`registered`, `in_stock`, `reserved`, `in_transit`, `issued`, `sold`, `returned_pending_inspection`, `scrapped`, `lost`), `current_inventory_location_id`, `current_condition`, `warranty_until`, `attributes`, timestamps. Current position is a projection updated with the ledger; `serial_events` is derived from ledger lines. A serial cannot be in two positions; transitions validated by the state enum.

## 7.9 Valuation

- Company policy `inventory.costing_method` default `moving_average`; per-category/item override allowed only before first posting of that item.
- Moving average: receipt recomputes average per item (company level, configurable `per_warehouse`); transfers carry cost; issues use current average; reversals use original snapshots.
- FIFO: `valuation_layers` (item, receipt line, qty remaining, unit cost, created_at); issues consume layers in order; transfers move layer quantities; adjustments create layers.
- Standard: issues at standard; purchase variance recorded in `valuation_variances` for reporting.
- `inventory_valuation_snapshots` are **not** stored; valuation at a date is computed on demand from layers/ledger (reporting). Only the current projection (`total_value_projection`) is maintained operationally.
- Landed-cost allocation (feature-flag) distributes additional costs over receipt lines, creating cost-adjustment layers; never changes quantities.

## 7.10 Batch traceability (company-wide)

- Forward trace (lot → every position, movement, document, branch, customer) and backward trace (document/serial → lots → receipts → suppliers) are first-class queries over the ledger (`TraceLotQuery`, `TraceSerialQuery`, `TraceDocumentQuery`), exposed as datasets and as a Trace screen with timeline and graph.
- Recall workflow: `recalls` (lot ids, reason, initiated_by, status enum `RecallStatus`) locks lots (`locked_recall`), blocks issue/sale/transfer, lists affected branches and customers (from sales/issues), and tracks returns and disposals.
- In transit lots are `locked_in_transit`: no issue, sale, adjustment, condition change or second dispatch; unlocked by transfer receipt (or discrepancy resolution).

## 7.11 Reversals

`ReverseStockMovementAction` (permission `inventory.movement.reverse`, critical): validates the original is `posted`/`partially_reversed`, checks the inventory still exists in the required positions (lot/serial/condition), checks downstream dependencies (issued/transferred/sold quantities) and rejects with `409 REVERSAL_BLOCKED_DOWNSTREAM` listing dependents, requires reason + approval policy, posts a `reversal` movement with mirrored lines referencing originals, updates valuation per method, marks the original `reversed`/`partially_reversed`. Idempotent per original line.

## 7.12 Opening balances

`opening_balance_documents` + lines (warehouse, location, item, variant, lot/serial, qty, unit cost, cutoff date, evidence). Drafted by import or UI, approved, posted via `PostOpeningBalanceAction` from the `opening_balance` virtual location. One posting per document; backdating limited by window; audited.

## 7.13 Actions (posting boundary)

All stock changes go through `app/Domains/Inventory/Actions/Post*Action` or workflow actions that delegate to `InventoryPoster` (the single ledger writer): `PostGoodsReceiptAction`, `ReleaseFromQuarantineAction`, `PostSupplierReturnAction`, `PostTransferDispatchAction`, `PostTransferReceiptAction`, `PostStockIssueAction`, `PostSaleDeliveryAction`, `PostCustomerReturnAction`, `PostInternalReturnAction`, `PostStockAdjustmentAction`, `PostRelocationAction`, `PostConditionChangeAction`, `PostScrapAction`, `PostOpeningBalanceAction`, `ReverseStockMovementAction`, `ReserveStockAction` family, `RebuildInventoryProjectionAction`. Controllers never touch quantities; generic update endpoints cannot alter posted quantities (schema `server_controlled`).

## 7.14 Tests (mandatory)

Position uniqueness with sentinels; positive-only lines; serial qty = 1; lot required; balance = ledger after every workflow; reservation invariants; lot lock in transit blocks all actions; expiry transitions; FIFO/average/standard valuation golden cases; reversal blocked by downstream; trace forward/backward completeness; projection rebuild never touches ledger (row count + checksum unchanged).
