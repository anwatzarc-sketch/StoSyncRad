# 08 — OPERATIONAL WORKFLOWS (DIAGRAM FLOW, END TO END)

Every workflow below: documents are drafted/submitted/approved as data; **stock changes only on posting** through Chapter 07 actions inside Chapter 09 transaction rules; every status is a `DocumentStatus`-family enum with code-defined transitions; every approval respects Chapter 04 SoD and approval policies; every document has a configurable number (§8.13).

Common document header fields: `id`, `document_number`, `document_type`, `status`, `branch_id`, `warehouse_id` (or source/destination), `document_date`, `reference`, `notes`, `priority` enum `Priority` (`low`, `normal`, `high`, `urgent`), `created_by`, `submitted_by/at`, `approved_by/at`, `posted_by/at`, `cancelled_by/at`, `cancellation_reason_lookup_id`, `version` (optimistic), attachments, approval records, audit trail, outbox events.

## 8.1 Demand forecasting & planning (HQ)

- **Demand history** is derived on demand from the ledger (issues, sales, transfers out) per item/warehouse/period — no stored forecast tables.
- **Forecast inputs** (`planning_forecast_inputs`, editable): method enum `ForecastMethod` (`moving_average`, `weighted_moving_average`, `seasonal_naive`, `manual`), horizon days, window, seasonality months, manual overrides per item/warehouse/period with reason.
- **Replenishment suggestions** (`ReplenishmentSuggestionQuery`): for each item/warehouse reorder rule compute projected stock = available + eligible inbound − committed outbound; if below reorder point propose `purchase` (preferred supplier, qty = max(reorder_qty, max − projected) rounded to `multiple_of`) or `transfer` (from preferred source warehouse with surplus). Suggestions show every included quantity. Unapproved requests and unconfirmed inbound are excluded.
- **Allocation plan** (`allocation_plans` + lines): HQ allocates available central stock across branches per period using policy enum `AllocationPolicy` (`manual`, `proportional_to_forecast`, `fill_to_max`, `priority_order`), with per-branch caps. Approval generates `transfer_orders` (one per destination) in `approved` state. The plan never moves stock.
- **Performance monitoring → re-planning:** fill rate, stockout days, transfer lead time, discrepancy rate and forecast error are reporting datasets; planning screens link to them.

## 8.2 Procurement

**Purchase request** (`purchase_requests`): requester, destination warehouse, required-by, justification, lines (item/variant, qty, UoM, est. cost), status enum `PurchaseRequestStatus` (`draft`, `submitted`, `approved`, `partially_ordered`, `ordered`, `rejected`, `cancelled`).

**Purchase order** (`purchase_orders`): supplier, destination warehouse, order/expected dates, currency + exchange rate snapshot, incoterm, payment terms, lines (item/variant, ordered qty, UoM + factor snapshot, unit cost, tax/charge snapshot, expected date, received qty projection), totals computed server-side, status enum `PurchaseOrderStatus` (`draft`, `submitted`, `approved`, `sent`, `partially_received`, `fully_received`, `closed`, `cancelled`), revisions (`purchase_order_revisions` snapshot on material change after approval, requiring re-approval per policy).

Controls: creator ≠ approver; approval tiers by value; over-receipt tolerance per PO or global; cancellation blocked with outstanding receipts; PO with receipts never deleted; supplier status `on_hold`/`blacklisted` blocks new POs.

## 8.3 Central receiving, QC and lot assignment

**Goods receipt** (`goods_receipts`): PO reference (or authorized direct receipt with permission), warehouse, supplier delivery note, received date, carrier/vehicle, lines (`goods_receipt_lines`: PO line, item/variant, expected qty, received qty, UoM + factor, lot (new or existing), manufacture/expiry/best-before, serials list, destination storage location (put-away suggestion), receiving condition enum `ReceivingCondition` (`good`, `damaged`, `quarantine`, `rejected`), accepted qty, rejected qty, rejection reason, inspection notes, unit cost snapshot, landed cost allocation), status enum `GoodsReceiptStatus` (`draft`, `in_inspection`, `pending_approval`, `approved`, `posted`, `cancelled`).

Guided steps (mobile): open PO → verify warehouse → scan item → qty + UoM → lot/expiry/serials → QC checklist → location → review accepted/rejected → submit → post (if permitted) → confirmation shows committed GRN number.

Posting (`PostGoodsReceiptAction`): per line, movement `supplier → destination location` with condition from receiving condition / item QC policy (quarantine-on-receipt forces `quarantine`); rejected qty is not received (recorded only; optional `supplier_return` draft auto-created); lots created/updated with QC status; serials registered; valuation updated; PO received qty projection updated; PO status derived; outbox `receiving.goods_receipt.posted`.

**QC release** (`ReleaseFromQuarantineAction`): checklist outcome, release qty to `available`, fail qty to `damaged`/`rejected`, lot `qc_status` set; permission `receiving.qc.release`; evidence attachments.

**Put-away**: optional `putaway_tasks` from receiving location to suggested bins, posted as `relocation` movements (no condition change).

**Supplier return** (`supplier_returns`): against receipt lines/lots/serials; returnable qty = qty still in position; reason; approval; posting moves `location → supplier` at original cost; links to GRN.

## 8.4 Allocation & distribution → inter-branch transfers

**Transfer order** (`transfer_orders`): source warehouse, destination warehouse (must differ unless `relocation` workflow), requester, reason, priority, allocation plan ref, carrier, lines (item/variant, requested qty, approved qty, dispatched qty / received qty / discrepancy qty projections, UoM + factor), status enum `TransferStatus` (`draft`, `submitted`, `approved`, `picking`, `partially_dispatched`, `dispatched`, `in_transit`, `partially_received`, `received`, `discrepancy_review`, `closed`, `cancelled`) — derived from events, never set by client.

Authorization: create/approve requires source or destination per `transfers.transfer.*` scope policy (configurable which side approves); dispatch requires source; receive requires destination; view requires either.

**Picking** (`pick_lists` + lines, shared with issues/sales): system suggests positions per removal strategy (FEFO for expiry items), reserves on approval or on pick per policy, operator confirms lot/serial/qty, short-pick reasons.

**Dispatch** (`transfer_dispatches` + lines; `PostTransferDispatchAction`): lock source positions → revalidate availability and lot eligibility → movement `source location → in_transit location` per lot/serial → lots `locked_in_transit`, serials `in_transit` → consume reservations → dispatch note number, carrier, seal/vehicle, expected arrival → status derived. Multiple dispatches allowed; each is its own posting event.

**In transit**: visible company-wide; elapsed time, overdue alerts (`stock.transfer.overdue_days`).

**Receipt** (`transfer_receipts` + lines; `PostTransferReceiptAction`): lock transfer and in-transit/destination positions → **batch verify**: scanned lot/serial must match dispatched lot/serial for that dispatch line (`409 LOT_MISMATCH`) → accepted qty ≤ outstanding dispatched qty → movement `in_transit → destination location` (condition `available` or `damaged`/`quarantine` per receiving condition) → damaged qty to damaged location → shortage/excess recorded as `transfer_discrepancies` (never alters dispatch) → unlock received lots → status derived. Partial receipts keep remainder in transit.

**Discrepancy resolution** (`transfer_discrepancies`: type enum `DiscrepancyType` (`shortage`, `excess`, `damage`, `lot_mismatch`, `serial_mismatch`), qty, evidence, status enum `DiscrepancyStatus` (`open`, `investigating`, `resolved`, `accepted`), resolution enum `DiscrepancyResolution` (`receive_accepted`, `keep_in_transit`, `record_damage`, `loss_adjustment`, `return_to_source`, `data_correction_reversal`)): each resolution posts the matching traceable movement (loss → `in_transit → inventory_loss` adjustment with approval; return → new reverse transfer). Closure requires conservation: dispatched = received + remaining in transit + resolved.

Cancellation: before dispatch releases reservations; after dispatch impossible — use receipt/return/discrepancy.

## 8.5 Branch receiving & local stock (retail store / hub)

Transfer receipt at a branch (above) with batch verify; put-away to bins; local stock view per bin; cycle counts by counting frequency; local reorder rules may raise `transfer` replenishment suggestions to HQ/depot.

## 8.6 Internal stock requests, picking and issues

**Stock request** (`stock_requests`): requesting account/recipient (department/project/branch), source warehouse, required date, priority, purpose, lines (item/variant, requested, approved, fulfilled qty), status enum `StockRequestStatus` (`draft`, `submitted`, `approved`, `partially_fulfilled`, `fulfilled`, `rejected`, `cancelled`). Approval reserves per policy.

**Stock issue** (`stock_issues` + lines; `PostStockIssueAction`): from request or direct (permission), pick list, lot/serial selection, recipient verification, approval for `requires_approval_for_issue` items, posting `location → customer(internal recipient)` consuming reservations; partial fulfillment per policy; issue note printable; request status derived.

**Internal return** (`internal_returns`; `PostInternalReturnAction`): against issue lines, inspection → condition bucket (`returned_pending_inspection` by default), approval, posting `customer → returns location`.

## 8.7 Production store (optional capability)

Issue to production / receipt from production use `production` virtual location via `PostStockIssueAction` (type `production_issue`) and `PostGoodsReceiptAction` (type `production_receipt`) — no BOM/MRP.

## 8.8 Retail sale to customer (stock-side)

Feature `feature.retail_sales`. **Sales order** (`sales_orders` + lines): channel enum `SalesChannel` (`retail_counter`, `ecommerce`, `wholesale`, `internal_transfer_sale_disabled`), customer partner (or walk-in placeholder), branch/warehouse, lines (item/variant, qty, UoM, unit price reference, lot/serial if customer-visible), status enum `SalesOrderStatus` (`draft`, `confirmed`, `reserved`, `picking`, `packed`, `partially_delivered`, `delivered`, `cancelled`, `returned`). Confirmation reserves (policy), counter sale may be `confirm → deliver` in one guided step but still two server actions (confirm, then `PostSaleDeliveryAction` posting `location → customer`). Receipt/invoice printing uses templates; payment status is an external reference field only.

## 8.9 E-commerce fulfillment hub

Feature `feature.ecommerce_fulfillment`. Order intake via API (`POST /api/v1/sales-orders` with channel `ecommerce`, external order id unique per integration) or import; validation; hub warehouse routing; wave/batch picking (`pick_waves` grouping pick lists, FEFO lot logic, bin path optimization by location sort order); packing (`shipments` + `shipment_packages`: package type, weight, dimensions, items/lots/serials, tracking number); carrier handoff; `PostSaleDeliveryAction` posts on ship (or on delivery confirmation per `fulfillment.post_on` config); delivery status updates via carrier webhooks (`ShipmentStatus` enum: `packed`, `handed_over`, `in_transit`, `delivered`, `failed`, `returned`); customer notifications via outbox. Backorders per `out_of_stock_behavior`.

**Customer return** (`customer_returns`; `PostCustomerReturnAction`): RMA number, original order/shipment lines, reason, inspection → condition, restock to returns location, link to sale, optional refund reference (external).

## 8.10 Stocktaking (full, cycle, blind)

`stocktakes`: warehouse, scope (locations/categories/items/ABC class), type enum `StocktakeType` (`full`, `cycle`, `spot`), blind flag, freeze policy enum `StocktakeFreezePolicy` (`freeze_positions`, `snapshot_and_reconcile`), snapshot_at, counters, status enum `StocktakeStatus` (`draft`, `scheduled`, `counting`, `recount_required`, `review`, `pending_approval`, `approved`, `posted`, `cancelled`). Lines: position, snapshot qty, count 1, count 2 (recount), final qty, variance, variance value, status enum `CountLineStatus`. Freeze blocks postings on frozen positions (`409 POSITION_FROZEN`); snapshot mode reconciles intervening movements before variance. Thresholds trigger recount/supervisor/approval. Approval generates a stock adjustment; stocktake posts once.

## 8.11 Stock adjustments

`stock_adjustments` + lines: type enum `AdjustmentType` (`count_variance`, `damage`, `loss`, `expiry_writeoff`, `found`, `data_correction`, `opening_correction`, `scrap`, `other`), reason lookup + text, lines (position, qty ±, unit cost for positive, evidence), approval (creator ≠ approver; tiers), posting `location ↔ adjustment/inventory_loss/scrap` virtual; negative never exceeds eligible qty; positive requires origin justification.

## 8.12 Relocation and condition changes

Within-warehouse moves (`relocations`) and condition changes are documents with lines, permissions `inventory.relocation.post` / `inventory.condition.change`, posted as ledger movements; bin-level accuracy depends on them.

## 8.13 Document numbering

`document_sequences`: per `DocumentType` (and per scope), pattern tokens, next number; allocation via `SELECT … FOR UPDATE` on the sequence row inside the posting/creation transaction (gaps acceptable; duplicates impossible — unique index on `document_number` per type). Patterns configurable (Chapter 02 step 10).

## 8.14 Closure & monitoring

Documents reach terminal states only when quantity conservation holds (transfers), all lines are accounted (receipts/sales), discrepancies resolved, reservations released/consumed. Dashboards (Chapter 12) show open work per role; reporting datasets expose cycle times, fill rates and discrepancy rates for re-planning.

## 8.15 Tests (mandatory)

Lifecycle/transition matrices per document; PO over-receipt rejection; partial receipts; QC quarantine → release; lot/serial capture mandatory; dispatch conserves qty and locks lots; receipt batch verify mismatch; partial receipt remainder; discrepancy resolutions each post a traceable movement; closure conservation equation; request→reservation→issue→return chain; counter sale and e-commerce ship/deliver paths; customer return restock; stocktake freeze/snapshot variance; adjustment SoD and limits; numbering under concurrency; feature flags gate sales/e-commerce endpoints.
