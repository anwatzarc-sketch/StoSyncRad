import { enumLabel, humanize, propertyLabel, type SchemaProperty } from './meta';
import { formatDate, formatDateTime, formatMoney, formatQuantity } from './format';
import type { Column } from '@/components/ui/table';

/** Text for one value of a record, by its schema property (enum labels, dates, exact decimals). */
export function displayValue(name: string, p: SchemaProperty | undefined, row: Record<string, any>): string {
    const value = row[name];
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (row[`${name}_label`]) {
        return String(row[`${name}_label`]);
    }
    switch (p?.type) {
        case 'enum':
            return enumLabel(p.enum, value);
        case 'boolean':
            return value ? 'Yes' : 'No';
        case 'date':
            return formatDate(value);
        case 'datetime':
            return formatDateTime(value);
        case 'quantity':
        case 'decimal':
        case 'percentage':
            return formatQuantity(value);
        case 'money':
            return formatMoney(value, row.currency ?? null);
        case 'array':
            return Array.isArray(value) ? (p.item_enum ? value.map((v) => enumLabel(p.item_enum, v)).join(', ') : value.length ? `${value.length} item(s)` : '—') : String(value);
        case 'json':
            return typeof value === 'object' ? Object.entries(value).map(([k, v]) => `${humanize(k)}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join('; ') : String(value);
        default:
            if (typeof value === 'boolean') {
                return value ? 'Yes' : 'No';
            }
            if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T/.test(value)) {
                return formatDateTime(value);
            }

            return typeof value === 'object' ? JSON.stringify(value) : String(value);
    }
}

const IDENTITY = ['document_number', 'movement_number', 'sku', 'code', 'lot_number', 'serial_number', 'username', 'name', 'full_name', 'legal_name'];
const PREFERRED = ['status', 'name', 'warehouse_id', 'document_date', 'expected_date', 'category_id', 'item_type', 'tracking_mode', 'partner_types', 'kind',
    'movement_type', 'posted_at', 'business_date', 'expiry_date', 'quantity', 'priority', 'channel', 'account_status', 'email', 'created_at'];

/** List columns for a resource: identity first, then status and the most useful scalar fields. */
export function columnsFor(properties: Record<string, SchemaProperty>, sample?: Record<string, any>): Column[] {
    const keys = Object.keys(properties).length ? Object.keys(properties) : Object.keys(sample ?? {});
    const prop = (k: string) => properties[k];
    const simple = (k: string) => !['json', 'array', 'text', 'any'].includes(prop(k)?.type ?? '') && !prop(k)?.sensitive && !/(_id|^id|_hash|password|secret)$/.test(k);
    const identity = IDENTITY.find((k) => keys.includes(k));
    const chosen: string[] = identity ? [identity] : [];
    for (const k of [...PREFERRED, ...keys]) {
        if (chosen.length >= 6) {
            break;
        }
        if (keys.includes(k) && !chosen.includes(k) && (simple(k) || PREFERRED.includes(k) && !k.endsWith('_id'))) {
            chosen.push(k);
        }
    }

    return chosen.map((k, i) => ({
        key: k,
        label: propertyLabel(k, prop(k)),
        align: ['quantity', 'money', 'decimal', 'integer'].includes(prop(k)?.type ?? '') && i > 0 ? 'right' : 'left',
        format: (_: unknown, row: Record<string, any>) => displayValue(k, prop(k), row),
    }));
}

/** Map a resource to its printable document type (DocumentPrintService::TYPES). */
export const PRINT_TYPES: Record<string, string> = {
    'purchase-orders': 'purchase_order',
    'goods-receipts': 'goods_receipt',
    'stock-issues': 'stock_issue',
    'pick-lists': 'pick_list',
    shipments: 'delivery_note',
    stocktakes: 'stocktake',
    'stock-adjustments': 'stock_adjustment',
    quotations: 'quotation',
    invoices: 'invoice',
};
