# APPENDIX D — ITEM MASTER FIELD CATALOG (ODOO-PARITY)

Organized by UI tab. Columns: field · type · required · notes/validation. All enum fields validated per Chapter 05. Cost-classified fields require `catalog.item.view_cost` to read and `catalog.item.manage_cost` to write.

## D.1 General Information
| Field | Type | Req | Notes |
|---|---|---|---|
| sku | string(64) | ✓ | pattern `catalog.sku.pattern`; unique; immutable after posting |
| name | string(200) | ✓ | |
| display_name | computed | | name + variant values |
| item_type | enum ItemType | ✓ | stockable default |
| is_sellable / is_purchasable / is_transferable | boolean | ✓ | defaults true/true/true |
| category_id | uuid → item_categories (leaf) | ✓ | |
| tags[] | uuid[] → tags | | |
| brand_lookup_id | uuid → lookup_values(brand) | | |
| manufacturer_partner_id | uuid → partners(manufacturer) | | |
| model_number | string(100) | | |
| description_short | string(500) | | |
| description_long | rich text (sanitized) | | |
| internal_notes | text | | never printed |
| images[] | file_ref[] | | primary flag; ≤ 10 |
| status | enum ItemStatus | ✓ | server-controlled transitions |
| responsible_person_id | uuid → persons | | |
| is_critical | boolean | | |
| requires_approval_for_issue | boolean | | inherits category |

## D.2 Identification & Barcodes
| barcodes[] | {barcode, symbology enum, item_variant_id?, packaging_id?, is_primary} | | barcode unique across items/variants/packagings; symbology checksum validated |
| hs_code | string(20) | | |
| country_of_origin | string(2) ISO | | |
| customs_description | string(255) | | |
| supplier_reference (legacy) | string | | migrated to vendor pricelists |

## D.3 Units & Packaging
| base_uom_id | uuid → units_of_measure | ✓ | immutable after posting |
| purchase_uom_id / sales_uom_id / transfer_uom_id | uuid | | same UoM category or item conversion |
| uom_conversions[] | {uom_id, factor NUMERIC(20,10), rounding} | | factor > 0 |
| weight / weight_uom_id | decimal(12,4)/uuid | | |
| volume / volume_uom_id | decimal(12,6)/uuid | | |
| length / width / height / dimension_uom_id | decimal | | |
| packagings[] | {name, contained_qty, barcode, packaging_type_lookup_id, weight, dims, is_default_purchase/sales/transfer} | | contained_qty > 0 |

## D.4 Inventory & Logistics
| tracking_mode | enum TrackingMode | ✓ | immutable after posting (conversion action) |
| lot_number_policy | enum LotNumberPolicy | | |
| expiry_policy | enum ExpiryPolicy | | |
| shelf_life_days / best_before_offset_days / removal_offset_days / alert_offset_days | integer ≥ 0 | | removal ≤ shelf life |
| removal_strategy | enum RemovalStrategy | | inherit_category default |
| quality_control_policy | enum QcPolicy | | |
| qc_checklist_template_id | uuid | | |
| storage_category_id | uuid → storage_categories | | |
| preferred_storage_conditions | json {temp_min,temp_max,humidity_max,hazard_class,stacking_limit} | | |
| is_hazardous / is_fragile / is_perishable | boolean | | |
| abc_class / abc_override | enum AbcClass / boolean | | computed unless override |
| counting_frequency_days | integer | | inherits category |
| supplier_lead_time_days / customer_lead_time_days | integer | | |
| receipt_description / delivery_description / transfer_description | string(500) | | printed on documents |
| allow_negative_stock_override | boolean | | requires permission to set |
| min_order_qty / max_order_qty / order_multiple | decimal | | |

## D.5 Replenishment (per warehouse rows)
| warehouse_id | uuid (null = company default) | | |
| min_qty / max_qty / reorder_point / reorder_qty / safety_stock / multiple_of_qty | decimal(20,6) | | min ≤ reorder_point ≤ max |
| replenishment_method | enum ReplenishmentMethod | ✓ | |
| preferred_supplier_id / preferred_source_warehouse_id | uuid | | per method |
| lead_time_days / review_period_days | integer | | |
| is_active | boolean | | |

## D.6 Purchasing
| vendor_pricelists[] | {supplier_id, vendor_product_code, vendor_product_name, min_qty, unit_price (cost-classified), currency, uom_id, valid_from, valid_to, lead_time_days, is_preferred} | | valid_from ≤ valid_to |
| purchase_description | string(500) | | |
| purchase_warning_lookup_id / purchase_warning_mode | uuid / enum(`none`,`warn`,`block`) | | |
| receipt_control_policy | enum ReceiptControlPolicy | | |
| last_purchase_cost / last_purchased_at | decimal / datetime | | projection; cost-classified |

## D.7 Sales (stock-side)
| list_price / list_price_currency | decimal(20,4)/string | | reference only |
| sales_description | string(500) | | |
| sales_warning_lookup_id / sales_warning_mode | uuid / enum | | |
| out_of_stock_behavior | enum OutOfStockBehavior | | |
| optional_item_ids[] | uuid[] | | |
| is_available_online | boolean | | feature-flagged |

## D.8 Costing & Valuation (cost-classified)
| costing_method | enum CostingMethod | | immutable after posting |
| standard_cost / standard_cost_currency / standard_cost_valid_from | decimal(20,8) | | required when method = standard |
| current_average_cost | decimal(20,8) | | projection, read-only |
| valuation_account_ref / expense_account_ref / price_difference_account_ref | string(64) | | integration references |
| cost_visibility_classification | enum DataClassification | | default cost_sensitive |

## D.9 Attributes & Variants
| attribute_lines[] | {attribute_id, value_ids[]} | | |
| variants[] | {combination, sku_suffix, barcodes[], weight/dims overrides, status} | | generated; referenced combos immutable |

## D.10 Kits (feature `feature.kits`)
| kit_components[] | {component_item_id, quantity, uom_id} | | no self-reference; depth 1 |

## D.11 Attachments
| attachments[] | {kind enum(`datasheet`,`msds`,`certificate`,`image`,`other`), file, classification} | | private disk |

## D.12 Smart buttons (read-only projections/queries)
On hand · Forecasted · In/Out moves · Reservations · Lots/Serials · Reorder rules · Vendors · Put-away rules · Variants · Packagings · Quality checks · Valuation · Attachments · Audit — each a permission-filtered drill-down (Chapter 06 §6.6.13).
