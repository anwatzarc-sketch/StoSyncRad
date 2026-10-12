# 06 — ORGANIZATION, CATALOG (ODOO-LEVEL ITEM MASTER), CATEGORIES, UOM, PARTNERS

## 6.1 Company profile

Single row `company_profile` (not a tenant registry): legal name, trading name, registration no., tax id, addresses (jsonb, structured), contacts, website, fiscal year start, base currency, enabled currencies, industry lookup, logo (from brand kit), notes. Edited under `platform.company.manage`; audited.

## 6.2 Branches

`branches`: `id`, `code` (unique, immutable), `name`, `kind` enum `BranchKind` (`headquarters`, `retail_store`, `distribution_depot`, `ecommerce_fulfillment_hub`, `production_store`, `service_center`, `transit_hub`, `other`), `parent_branch_id` (optional hierarchy, e.g. depot → stores it serves), `address jsonb`, `contact jsonb`, `timezone` (nullable override), `manager_person_id`, `status` enum `BranchStatus` (`active`, `inactive`, `closed`), `opened_on`, `closed_on`, `metadata jsonb`.

Kind seeds default warehouse capabilities (editable per warehouse):

| Kind | Default capabilities |
|---|---|
| headquarters | receive_from_supplier, quality_control, allocate, dispatch_transfers, receive_transfers, issue_internal |
| distribution_depot | receive_from_supplier, receive_transfers, dispatch_transfers, cross_dock, issue_internal |
| retail_store | receive_transfers, sell_retail, customer_returns, issue_internal, dispatch_transfers (reverse) |
| ecommerce_fulfillment_hub | receive_transfers, receive_from_supplier, fulfill_ecommerce, pack_and_ship, customer_returns |
| production_store | receive_transfers, issue_to_production, receive_from_production |
| service_center | receive_transfers, issue_internal, customer_returns |
| transit_hub | receive_transfers, dispatch_transfers, cross_dock |

## 6.3 Warehouses

`warehouses`: `id`, `branch_id`, `code` (unique, immutable), `name`, `kind` enum `WarehouseKind` (`main`, `satellite`, `cold_storage`, `bonded`, `quarantine`, `returns`, `virtual_transit`), `capabilities` (array of enum `WarehouseCapability` — the set above plus `stocktake`, `adjust`, `reserve`), `address`, `contact`, `manager_person_id`, `default_receiving_location_id`, `default_dispatch_location_id`, `default_quarantine_location_id`, `default_damaged_location_id`, `default_scrap_location_id`, `default_returns_location_id`, `default_packing_location_id`, `negative_stock_policy_override`, `status` enum `WarehouseStatus` (`active`, `inactive`, `closing`, `closed`), `operational_calendar jsonb`, `metadata`.

- Warehouse with posted inventory cannot be deleted; `closing` blocks new inbound; `closed` requires zero on-hand, zero reservations, zero in-transit to/from.
- Every warehouse-scoped permission check resolves against `warehouses.id`; branch-scoped assignments expand to the branch's warehouses.

## 6.4 Storage locations

`storage_locations`: `id`, `warehouse_id`, `parent_id`, `code` (unique within warehouse), `full_path` (materialized `WH-001/ZONE-A/A02/R05/B03`, unique), `name`, `level` enum `StorageLocationLevel` (`zone`, `aisle`, `rack`, `shelf`, `bin`, `area`), `usage` enum `StorageLocationUsage` (`storage`, `receiving`, `dispatch`, `quarantine`, `damaged`, `scrap`, `returns`, `packing`, `cross_dock`, `staging`), `storage_category_id` (lookup: capacity/handling constraints, e.g. cold, hazardous, bulky), `capacity jsonb` (max weight/volume/pallets, optional), `temperature_range jsonb`, `is_pickable`, `is_putaway_target`, `counting_frequency_days`, `last_counted_at`, `barcode`, `status` enum `StorageLocationStatus` (`active`, `blocked`, `archived`), `sort_order`.

- A location belongs to exactly one warehouse; FK + check prevents cross-warehouse use.
- Every warehouse has at least one `storage` location and its system `usage` locations auto-created.
- Put-away rules (`putaway_rules`): `warehouse_id`, `applies_to` (`item` | `category` | `storage_category`), `target_id`, `source_usage`, `destination_location_id`, `priority`, `is_active`. Suggests destination at receipt/transfer receipt; never overrides authorization.

## 6.5 Inventory locations (physical + virtual)

`inventory_locations`: `id`, `location_type` enum `InventoryLocationType` (`physical`, `in_transit`, `supplier`, `customer`, `adjustment`, `opening_balance`, `scrap`, `production`, `return_inspection`, `inventory_loss`, `cross_dock_virtual`), `warehouse_id` (null for company-level virtual), `storage_location_id` (one-to-one for `physical`), `code`, `name`, `status`.

- Every physical storage location has exactly one inventory location (created with it).
- Virtual locations are seeded by setup; users cannot create virtual locations (admin may add typed ones under `platform.inventory_locations.manage` with audit).
- `in_transit` locations are per transfer lane (`source_warehouse_id`, `destination_warehouse_id`) or one company-wide, configurable (`stock.in_transit_location_mode`).

## 6.6 Item master — Odoo-level detail

The item master mirrors the depth of Odoo's product form, organized in tabs. Full field catalog in Appendix D. Summary:

### 6.6.1 Identity & general
`items`: `id`, `sku` (unique, pattern from config, immutable after first posting), `name`, `display_name` (computed with variant values), `item_type` enum `ItemType` (`stockable`, `consumable`, `service`, `kit`), `is_sellable`, `is_purchasable`, `is_transferable`, `category_id` (must be a **leaf**), `tags[]`, `brand_lookup_id`, `manufacturer_partner_id`, `model_number`, `description_short`, `description_long` (sanitized rich text), `internal_notes`, `images[]` (ordered, primary flag), `status` enum `ItemStatus` (`draft`, `active`, `inactive`, `archived`), `responsible_person_id`, `created_by`, timestamps.

### 6.6.2 Identification & barcodes
`item_barcodes`: `item_id`, `item_variant_id` (nullable), `packaging_id` (nullable), `barcode` (unique globally), `symbology` enum `BarcodeSymbology` (`ean13`, `ean8`, `upc_a`, `code128`, `code39`, `qr`, `datamatrix`, `itf14`, `custom`), `is_primary`. Supplier product codes live in vendor pricelists.

### 6.6.3 Units & packaging
`base_uom_id`, `purchase_uom_id`, `sales_uom_id`, `weight` + `weight_uom_id`, `volume` + `volume_uom_id`, dimensions (`length`, `width`, `height`, `dimension_uom_id`), `item_uom_conversions` (uom, factor, rounding), `item_packagings` (name, contained_qty in base, barcode, packaging_type lookup, weight, dimensions, is_default_purchase/sales/transfer).

### 6.6.4 Inventory & logistics
`tracking_mode` enum `TrackingMode` (`none`, `lot`, `serial`), `lot_number_policy` (`supplier_provided` | `generated` | `either`), `expiry_policy` enum `ExpiryPolicy` (`not_tracked`, `tracked_optional`, `tracked_required`), `shelf_life_days`, `best_before_offset_days`, `removal_offset_days` (days before expiry stock should be removed from sale), `alert_offset_days`, `removal_strategy` enum `RemovalStrategy` (`fefo`, `fifo`, `lifo`, `closest_location`, `inherit_category`), `quality_control_policy` enum `QcPolicy` (`none`, `inspect_on_receipt`, `quarantine_on_receipt`, `inherit_category`), `qc_checklist_template_id`, `storage_category_id`, `preferred_storage_conditions jsonb` (temperature, humidity, hazard class, stacking), `is_hazardous`, `is_fragile`, `is_perishable`, `is_critical`, `requires_approval_for_issue`, `abc_class` enum `AbcClass` (`a`, `b`, `c`, `unclassified`) + `abc_override`, `counting_frequency_days`, `hs_code`, `country_of_origin`, `customs_description`, `supplier_lead_time_days` (default), `customer_lead_time_days`, `receipt_description`, `delivery_description`, `transfer_description` (printed on documents), `allow_negative_stock_override` (bool, requires permission), `default_putaway_location_by_warehouse` (via put-away rules).

### 6.6.5 Replenishment (per item and per warehouse)
`item_reorder_rules`: `item_id`, `warehouse_id` (null = company default), `min_qty`, `max_qty`, `reorder_point`, `reorder_qty`, `safety_stock`, `multiple_of_qty`, `preferred_supplier_id`, `preferred_source_warehouse_id` (replenish by transfer vs purchase), `replenishment_method` enum `ReplenishmentMethod` (`purchase`, `transfer`, `manual`), `lead_time_days`, `review_period_days`, `is_active`. Warehouse rule overrides company rule; precedence shown in UI.

### 6.6.6 Purchasing
`item_vendor_pricelists`: `item_id`, `supplier_id`, `vendor_product_code`, `vendor_product_name`, `min_qty`, `unit_price`, `currency`, `uom_id`, `valid_from`, `valid_to`, `lead_time_days`, `is_preferred`, `last_purchased_at`, `last_purchase_price`. `purchase_description`, `purchase_warning` (lookup-driven blocking/warning note), `receipt_control_policy` (`ordered_qty` | `received_qty`).

### 6.6.7 Sales (stock-side)
`list_price` + currency (reference only; pricing engines out of scope), `sales_description`, `sales_warning`, `out_of_stock_behavior` enum (`block`, `allow_backorder`, `show_message`), `optional_item_ids[]`, `is_available_online` (feature-flagged), `min_order_qty`, `max_order_qty`.

### 6.6.8 Costing & valuation
`costing_method` enum `CostingMethod` (`moving_average`, `fifo`, `standard`, `inherit_category`), `standard_cost` (+ currency, valid_from), `current_average_cost` (projection), `last_purchase_cost`, `valuation_account_ref` / `expense_account_ref` (free-text references for future accounting integration), `cost_visibility_classification` enum `DataClassification`.

### 6.6.9 Variants & attributes
`item_attributes` (name, display_type enum `AttributeDisplayType` (`radio`, `select`, `color`, `text`), `create_variant` enum (`always`, `dynamic`, `never`)), `item_attribute_values`, `item_attribute_lines` (item ↔ attribute ↔ allowed values), `item_variants` (item_id, combination hash, `sku_suffix`, own barcodes, own weight/dimension overrides, `status`). Stock is always held per variant when variants exist; the ledger references `item_variant_id` (nullable when no variants). Variant creation on save is deterministic; combinations referenced by stock cannot be removed.

### 6.6.10 Kits (optional, feature-flagged)
`item_kit_components` (kit_item_id, component_item_id, qty). Kits explode at issue/sale; no manufacturing.

### 6.6.11 Documents & attachments
Datasheets, MSDS, certificates, images via `document_attachments` (private disk) with classification.

### 6.6.12 Item lifecycle rules
- `draft → active → inactive → archived`; items with posted history are never deleted.
- Changing `tracking_mode`, `base_uom_id`, `costing_method` is blocked after the first posted movement unless an audited conversion procedure (`ConvertItemTrackingModeAction`) is executed with zero on-hand or explicit lot assignment plan.
- SKU immutable after first posting; barcodes unique across items/variants/packagings.

### 6.6.13 Item detail screen (Odoo-parity "smart buttons")
On hand (per warehouse/location/lot/condition), Forecasted (on hand + inbound − outbound − reserved), In/Out moves (ledger history), Reservations, Lots/Serials, Reorder rules, Vendors, Put-away rules, Variants, Packagings, Quality checks, Valuation (if permitted), Attachments, Audit. Each button is a permission-filtered drill-down into the reporting datasets or operational lists.

## 6.7 Categories and sub-categories

`item_categories`: `id`, `parent_id`, `code` (unique), `name`, `full_path` (materialized, unique; e.g. `Electrical / Cables / Low Voltage`), `depth`, `sort_order`, `description`, `is_leaf` (computed), `status` enum `CategoryStatus` (`active`, `inactive`, `archived`), policy defaults (inheritable, each nullable = inherit): `default_tracking_mode`, `default_expiry_policy`, `default_removal_strategy`, `default_qc_policy`, `default_costing_method`, `default_uom_id`, `default_counting_frequency_days`, `default_storage_category_id`, `default_abc_class`, `requires_approval_for_issue`, `cost_visibility_classification`, `metadata`.

Rules:
- Unlimited depth; a category with children cannot hold items directly (items attach to leaves) — configurable `catalog.items_on_non_leaf_categories` default `false`.
- Effective policy = nearest ancestor with a non-null value (resolver exposed in UI and API).
- Moving a category/item re-materializes paths transactionally and records history (`item_category_moves`).
- Archive only when no active items; historical references preserved.
- **Complete coverage:** setup imports the full taxonomy in Appendix E (24 top-level groups, each with sub-categories and a reserved `Other` leaf), ensuring any item in common industries has a precise leaf. Clients may prune, rename, extend. Per-locale labels supported.
- Cross-cutting classification uses tags (lookup) — never duplicate categories.

## 6.8 Units of measure

`uom_categories` (`unit`, `weight`, `volume`, `length`, `area`, `time`, `packaging`, `custom`), `units_of_measure` (`category_id`, `code`, `name`, `symbol`, `ratio_to_reference`, `rounding_precision`, `is_reference`, `status`). Conversions between categories are only through item-specific conversions (e.g. piece ↔ kg for a specific item). Seeded standard set; editable.

## 6.9 Partners

- `partners`: `id`, `partner_type[]` enum `PartnerType` (`supplier`, `customer`, `carrier`, `internal_recipient`, `manufacturer`), `code` (unique), `name`, `legal_name`, `tax_id`, `addresses jsonb`, `contacts` (`partner_contacts` table), `default_currency`, `payment_terms_lookup_id`, `incoterm_lookup_id`, `lead_time_days`, `rating`, `is_internal` (department/branch/project recipients), `status` enum `PartnerStatus` (`active`, `on_hold`, `blacklisted`, `archived`), `notes`.
- Internal recipients may reference a branch or department; movement to an internal recipient is an issue to the `customer`-type virtual location with `recipient_partner_id` recorded; never a sale between legal entities.
- Carriers carry service levels (lookup) and tracking URL templates.

## 6.10 Tests (mandatory)

- Branch/warehouse/location code uniqueness and immutability; cross-warehouse location rejection; closing/closed state rules.
- Leaf-only item placement; path materialization on move; policy inheritance resolver.
- Item lifecycle; tracking-mode/UoM/costing immutability after posting; barcode uniqueness across tables.
- Variant generation determinism; referenced combinations cannot be removed.
- Reorder rule precedence; vendor pricelist validity windows.
- Full taxonomy seed imports idempotently with unique paths.
