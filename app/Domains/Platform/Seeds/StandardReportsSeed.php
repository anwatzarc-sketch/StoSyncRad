<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Domains\ReportingEngine\Query\QueryCompiler;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shipped "standard" reports (Chapter 10 §10.1): ordinary report definitions marked
 * `is_system_seed` — read-only, cloneable, and runnable by anyone holding the dataset and field
 * permissions. Idempotent; a system report edited by a client (version > 1) is left untouched.
 */
final class StandardReportsSeed extends Seed
{
    public function name(): string
    {
        return 'standard_reports';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    /** @return array<int, array{code: string, name: string, dataset: string, description: string, definition: array, tags: array<int, string>}> */
    public static function data(): array
    {
        $sum = fn (string $f, ?string $as = null) => ['fn' => 'sum', 'field' => $f, 'as' => $as ?? $f];
        $f = fn (string $field, string $op, mixed $value = null) => ['field' => $field, 'operator' => $op] + ($value === null ? [] : ['value' => $value]);
        $and = fn (array ...$c) => ['op' => 'and', 'conditions' => $c];
        $dateRange = [['code' => 'from', 'type' => 'date', 'label' => 'From', 'required' => true], ['code' => 'to', 'type' => 'date', 'label' => 'To', 'required' => true]];

        return [
            ['code' => 'std_stock_on_hand', 'name' => 'Stock on hand by warehouse', 'dataset' => 'stock_positions', 'tags' => ['stock'],
                'description' => 'Current quantities per warehouse, item and condition.',
                'definition' => ['group_by' => ['warehouse_code', 'item_sku', 'item_name', 'stock_condition'],
                    'aggregates' => [$sum('quantity_on_hand', 'on_hand'), $sum('quantity_reserved', 'reserved'), $sum('quantity_available', 'available')],
                    'sort' => [['field' => 'warehouse_code'], ['field' => 'item_sku']]]],
            ['code' => 'std_stock_by_location', 'name' => 'Stock by bin location', 'dataset' => 'stock_positions', 'tags' => ['stock'],
                'description' => 'Every position with lot, expiry and condition.',
                'definition' => ['fields' => ['warehouse_code', 'location_path', 'item_sku', 'item_name', 'lot_number', 'expiry_date', 'stock_condition',
                    'quantity_on_hand', 'quantity_reserved', 'quantity_available'], 'sort' => [['field' => 'warehouse_code'], ['field' => 'location_path']]]],
            ['code' => 'std_valuation_summary', 'name' => 'Inventory valuation by warehouse', 'dataset' => 'valuation_by_item_warehouse', 'tags' => ['valuation'],
                'description' => 'Quantity and value per valuation bucket.',
                'definition' => ['group_by' => ['warehouse_code'], 'aggregates' => [$sum('quantity'), $sum('total_value')], 'sort' => [['field' => 'total_value', 'dir' => 'desc']]]],
            ['code' => 'std_expiring_stock', 'name' => 'Stock expiring in the next 30 days', 'dataset' => 'stock_positions', 'tags' => ['expiry', 'lots'],
                'description' => 'Positions whose lot expires within 30 days (FEFO follow-up).',
                'definition' => ['fields' => ['warehouse_code', 'location_path', 'item_sku', 'item_name', 'lot_number', 'expiry_date', 'quantity_on_hand'],
                    'filters' => $and($f('expiry_date', 'relative_date', 'next_30_days'), $f('quantity_on_hand', 'gt', '0')), 'sort' => [['field' => 'expiry_date']]]],
            ['code' => 'std_expired_stock', 'name' => 'Expired and blocked stock', 'dataset' => 'stock_positions', 'tags' => ['expiry'],
                'description' => 'Stock in expired, quarantine, damaged or blocked condition.',
                'definition' => ['fields' => ['warehouse_code', 'location_path', 'item_sku', 'item_name', 'lot_number', 'expiry_date', 'stock_condition', 'quantity_on_hand'],
                    'filters' => $and($f('stock_condition', 'in', ['expired', 'quarantine', 'damaged', 'blocked']))]],
            ['code' => 'std_lots_by_expiry', 'name' => 'Lots by expiry horizon', 'dataset' => 'lots', 'tags' => ['lots'],
                'description' => 'Number of lots per expiry horizon and QC status.',
                'definition' => ['group_by' => ['expiry_bucket', 'qc_status'], 'aggregates' => [['fn' => 'count', 'as' => 'lots']]]],
            ['code' => 'std_movement_history', 'name' => 'Stock movement history', 'dataset' => 'stock_movement_lines', 'tags' => ['ledger'],
                'description' => 'Ledger lines in a date range.', 'definition' => ['parameters' => $dateRange,
                    'fields' => ['posted_at', 'movement_number', 'movement_type', 'source_document_number', 'item_sku', 'item_name', 'lot_number',
                        'source_warehouse_code', 'source_location_path', 'destination_warehouse_code', 'destination_location_path', 'quantity'],
                    'filters' => $and($f('business_date', 'gte', ['param' => 'from']), $f('business_date', 'lte', ['param' => 'to'])),
                    'sort' => [['field' => 'posted_at', 'dir' => 'desc']]]],
            ['code' => 'std_stock_card', 'name' => 'Stock card (item movements)', 'dataset' => 'stock_movement_lines', 'tags' => ['ledger'],
                'description' => 'Every movement of one item.', 'definition' => ['parameters' => [['code' => 'sku', 'type' => 'string', 'label' => 'SKU', 'required' => true]],
                    'fields' => ['posted_at', 'movement_number', 'movement_type', 'source_document_number', 'lot_number', 'source_location_path', 'destination_location_path', 'quantity'],
                    'filters' => $and($f('item_sku', 'eq', ['param' => 'sku'])), 'sort' => [['field' => 'posted_at']]]],
            ['code' => 'std_po_outstanding', 'name' => 'Outstanding purchase order lines', 'dataset' => 'purchase_order_lines', 'tags' => ['procurement'],
                'description' => 'Ordered quantities still to be received.',
                'definition' => ['fields' => ['document_number', 'supplier_name', 'warehouse_code', 'item_sku', 'item_name', 'ordered_quantity', 'received_quantity',
                    'outstanding_quantity', 'expected_date'],
                    'filters' => $and($f('outstanding_quantity', 'gt', '0'), $f('order_status', 'in', ['approved', 'sent', 'partially_received'])),
                    'sort' => [['field' => 'expected_date']]]],
            ['code' => 'std_po_overdue', 'name' => 'Overdue purchase orders', 'dataset' => 'purchase_orders', 'tags' => ['procurement'],
                'description' => 'Approved or sent orders past their expected delivery date.',
                'definition' => ['fields' => ['document_number', 'supplier_name', 'warehouse_code', 'expected_delivery_date', 'status'],
                    'filters' => $and($f('is_overdue', 'eq', true)), 'sort' => [['field' => 'expected_delivery_date']]]],
            ['code' => 'std_receipts_by_supplier', 'name' => 'Receipts by supplier', 'dataset' => 'goods_receipts', 'tags' => ['receiving'],
                'description' => 'Posted receipts per supplier in a period.', 'definition' => ['parameters' => $dateRange,
                    'group_by' => ['supplier_name'], 'aggregates' => [['fn' => 'count', 'as' => 'receipts']],
                    'filters' => $and($f('status', 'eq', 'posted'), $f('document_date', 'between', [['param' => 'from'], ['param' => 'to']]))]],
            ['code' => 'std_qc_failures', 'name' => 'Quality check failures', 'dataset' => 'quality_checks', 'tags' => ['receiving', 'quality'],
                'description' => 'Failed and conditional inspections.',
                'definition' => ['fields' => ['inspected_at', 'warehouse_code', 'item_sku', 'item_name', 'lot_number', 'outcome', 'inspected_quantity', 'failed_quantity'],
                    'filters' => $and($f('outcome', 'in', ['fail', 'conditional'])), 'sort' => [['field' => 'inspected_at', 'dir' => 'desc']]]],
            ['code' => 'std_transfer_fill_rate', 'name' => 'Transfer fill rate by destination', 'dataset' => 'transfer_order_lines', 'tags' => ['transfers', 'performance'],
                'description' => 'Approved vs received quantities per destination.',
                'definition' => ['group_by' => ['destination_warehouse_code'], 'aggregates' => [$sum('approved_quantity', 'approved'), $sum('received_quantity', 'received'),
                    $sum('in_transit_quantity', 'in_transit')]]],
            ['code' => 'std_in_transit_aging', 'name' => 'In-transit aging', 'dataset' => 'in_transit_positions', 'tags' => ['transfers'],
                'description' => 'Stock in transit, oldest first.',
                'definition' => ['fields' => ['source_warehouse_code', 'destination_warehouse_code', 'item_sku', 'item_name', 'lot_number', 'quantity', 'days_in_transit'],
                    'sort' => [['field' => 'days_in_transit', 'dir' => 'desc']]]],
            ['code' => 'std_open_discrepancies', 'name' => 'Open transfer discrepancies', 'dataset' => 'transfer_discrepancies', 'tags' => ['transfers'],
                'description' => 'Shortages, excess and damage awaiting resolution.',
                'definition' => ['fields' => ['document_number', 'transfer_number', 'item_sku', 'lot_number', 'discrepancy_type', 'quantity', 'resolved_quantity', 'status', 'created_at'],
                    'filters' => $and($f('status', 'in', ['open', 'investigating'])), 'sort' => [['field' => 'created_at']]]],
            ['code' => 'std_request_fill_rate', 'name' => 'Stock request fill rate by warehouse', 'dataset' => 'stock_request_lines', 'tags' => ['fulfillment', 'performance'],
                'description' => 'Approved vs issued quantities for internal requests.',
                'definition' => ['group_by' => ['warehouse_code'], 'aggregates' => [$sum('approved_quantity', 'approved'), $sum('fulfilled_quantity', 'fulfilled')]]],
            ['code' => 'std_sales_by_channel', 'name' => 'Sales by channel', 'dataset' => 'sales_order_lines', 'tags' => ['sales'],
                'description' => 'Ordered and delivered quantities and value per channel and warehouse.',
                'definition' => ['group_by' => ['channel', 'warehouse_code'], 'aggregates' => [$sum('quantity', 'ordered'), $sum('delivered_quantity', 'delivered'), $sum('line_total', 'value')]]],
            ['code' => 'std_delivery_times', 'name' => 'Shipment delivery times by carrier', 'dataset' => 'shipments', 'tags' => ['sales', 'performance'],
                'description' => 'Average hours from handover to delivery.',
                'definition' => ['group_by' => ['carrier_name'], 'aggregates' => [['fn' => 'count', 'as' => 'shipments'], ['fn' => 'avg', 'field' => 'delivery_hours', 'as' => 'avg_hours']],
                    'filters' => $and($f('status', 'eq', 'delivered'))]],
            ['code' => 'std_customer_returns', 'name' => 'Customer returns', 'dataset' => 'customer_returns', 'tags' => ['sales', 'returns'],
                'description' => 'RMAs with status and returned quantity.',
                'definition' => ['fields' => ['document_number', 'order_number', 'customer_name', 'status', 'reason', 'returned_quantity', 'posted_at']]],
            ['code' => 'std_stocktake_variances', 'name' => 'Stocktake variances', 'dataset' => 'stocktake_lines', 'tags' => ['stocktaking'],
                'description' => 'Counted lines with a variance.',
                'definition' => ['fields' => ['document_number', 'warehouse_code', 'location_path', 'item_sku', 'item_name', 'lot_number', 'snapshot_quantity',
                    'movement_since_snapshot', 'final_quantity', 'variance_quantity'],
                    'filters' => $and($f('variance_quantity', 'neq', '0')), 'sort' => [['field' => 'document_number', 'dir' => 'desc']]]],
            ['code' => 'std_adjustments_by_type', 'name' => 'Adjustments by type', 'dataset' => 'stock_adjustments', 'tags' => ['stocktaking'],
                'description' => 'Posted adjustment count per type and warehouse.',
                'definition' => ['group_by' => ['adjustment_type', 'warehouse_code'], 'aggregates' => [['fn' => 'count', 'as' => 'adjustments']],
                    'filters' => $and($f('status', 'eq', 'posted'))]],
            ['code' => 'std_below_reorder_point', 'name' => 'Items below reorder point', 'dataset' => 'reorder_status', 'tags' => ['planning'],
                'description' => 'Available stock at or below the reorder point.',
                'definition' => ['fields' => ['warehouse_code', 'item_sku', 'item_name', 'available_quantity', 'reorder_point', 'max_qty', 'replenishment_method',
                    'preferred_supplier_name', 'preferred_source_warehouse_code'], 'filters' => $and($f('is_below_reorder_point', 'eq', true))]],
            ['code' => 'std_demand_by_month', 'name' => 'Demand by month', 'dataset' => 'demand_history', 'tags' => ['planning'],
                'description' => 'Issued, sold and transferred-out quantities per month.',
                'definition' => ['group_by' => ['period_month'], 'aggregates' => [$sum('issued_quantity', 'issued'), $sum('sold_quantity', 'sold'),
                    $sum('transferred_out_quantity', 'transferred_out')], 'sort' => [['field' => 'period_month', 'dir' => 'desc']]]],
            ['code' => 'std_serial_status', 'name' => 'Serial numbers by status', 'dataset' => 'serials', 'tags' => ['serials'],
                'description' => 'Count of serials per lifecycle status.',
                'definition' => ['group_by' => ['lifecycle_status'], 'aggregates' => [['fn' => 'count', 'as' => 'serials']]]],
            ['code' => 'std_failed_attempts', 'name' => 'Failed and rejected operations', 'dataset' => 'audit_event_summaries', 'tags' => ['audit'],
                'description' => 'Failed posting attempts and denied operations.',
                'definition' => ['fields' => ['occurred_at', 'event_type', 'actor_username', 'subject_type', 'reason'],
                    'filters' => $and($f('is_failed_attempt', 'eq', true)), 'sort' => [['field' => 'occurred_at', 'dir' => 'desc']]]],
            ['code' => 'std_access_review', 'name' => 'Access review', 'dataset' => 'users_access_review', 'tags' => ['security'],
                'description' => 'Accounts with their roles and scopes (restricted).',
                'definition' => ['fields' => ['username', 'full_name', 'account_status', 'role_name', 'scope_type', 'scope_code', 'last_login_at']]],
        ];
    }

    public function run(array $options = []): array
    {
        // Validate every definition against the catalog as an all-powerful (transient) account.
        $validator = new UserAccount;
        $validator->forceFill(['id' => (string) Str::uuid7(), 'is_super_admin' => true, 'account_status' => AccountStatus::ACTIVE]);
        $compiler = app(QueryCompiler::class);
        $inserted = 0;
        $skipped = 0;
        foreach (self::data() as $r) {
            $compiler->compile($r['dataset'], $r['definition'], $validator, self::sampleParams($r['definition']), 1, structuralOnly: true);
            $existing = DB::table('report_definitions')->where('code', $r['code'])->first(['id', 'definition_version']);
            if ($existing !== null && (int) $existing->definition_version > 1) {
                $skipped++;   // a client changed it — client edits win

                continue;
            }
            $row = ['name' => $r['name'], 'description' => $r['description'], 'dataset_code' => $r['dataset'],
                'definition' => json_encode($r['definition'], JSON_THROW_ON_ERROR), 'visibility' => ReportVisibility::SHARED_COMPANY->value,
                'is_system_seed' => true, 'is_active' => true, 'tags' => '{'.implode(',', $r['tags']).'}', 'updated_at' => now()];
            if ($existing === null) {
                $id = (string) Str::uuid7();
                DB::table('report_definitions')->insert($row + ['id' => $id, 'code' => $r['code'], 'definition_version' => 1, 'created_at' => now()]);
                DB::table('report_definition_versions')->insert(['id' => (string) Str::uuid7(), 'report_definition_id' => $id, 'definition_version' => 1,
                    'name' => $r['name'], 'definition' => $row['definition'], 'change_note' => 'Standard report', 'created_at' => now()]);
                $inserted++;
            } else {
                DB::table('report_definitions')->where('id', $existing->id)->update($row);
            }
        }

        return ['inserted' => $inserted, 'skipped_client_edited' => $skipped, 'total' => count(self::data())];
    }

    /** @return array<string, mixed> */
    public static function sampleParams(array $definition): array
    {
        $out = [];
        foreach ((array) ($definition['parameters'] ?? []) as $p) {
            $out[$p['code']] = match ($p['type']) { 'date' => now()->toDateString(), 'integer', 'decimal' => '0', default => 'X' };
        }

        return $out;
    }
}
