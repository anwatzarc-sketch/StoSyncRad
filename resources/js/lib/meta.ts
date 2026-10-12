import { shallowRef } from 'vue';
import { get } from './api';

/** Server metadata (Chapter 12 §12.3): enum labels (locale-aware, admin-editable) and table schemas. */

export interface EnumCase {
    value: string;
    label: string;
    description: string | null;
}

export interface SchemaProperty {
    type: 'uuid' | 'string' | 'text' | 'integer' | 'decimal' | 'boolean' | 'date' | 'datetime' | 'time' | 'enum' | 'json' | 'money' | 'quantity'
        | 'percentage' | 'email' | 'phone' | 'url' | 'barcode' | 'file_ref' | 'array' | 'any';
    nullable?: boolean;
    default?: unknown;
    required_on?: 'always' | 'create' | 'update';
    max_length?: number;
    precision?: number;
    scale?: number;
    min?: string;
    max?: string;
    pattern?: string;
    enum?: string;
    enum_values?: string[];
    references?: string;
    writable_by?: string;
    immutable_after?: string[];
    server_controlled?: boolean;
    sensitive?: boolean;
    reportable?: boolean;
    classification?: string;
    searchable?: boolean;
    sortable?: boolean;
    filterable?: boolean;
    label?: string;
    help?: string;
    after?: string;
    before?: string;
    items?: string;
    item_type?: string;
    item_enum?: string;
    item_enum_values?: string[];
    unique?: boolean;
    virtual?: boolean;
}

export interface TableSchema {
    name: string;
    table: string;
    state_enum: string | null;
    properties: Record<string, SchemaProperty>;
}

/** During Platform Setup the wizard reads metadata through its setup session. */
const metaBase = () => (typeof window !== 'undefined' && window.location.pathname.startsWith('/setup') ? '/setup/meta' : '/api/v1/meta');

const enums = shallowRef<Record<string, EnumCase[]>>({});
let enumsPromise: Promise<Record<string, EnumCase[]>> | null = null;

export function loadEnums(): Promise<Record<string, EnumCase[]>> {
    enumsPromise ??= get<{ data: Array<{ code: string; cases: EnumCase[] }> }>(`${metaBase()}/enums`).then((r) => {
        enums.value = Object.fromEntries(r.data.map((e) => [e.code, e.cases]));

        return enums.value;
    }).catch((e) => {
        enumsPromise = null;
        throw e;
    });

    return enumsPromise;
}

export function enumCases(code: string): EnumCase[] {
    return enums.value[code] ?? [];
}

/** Label for an enum value; never shows a raw code when a label is known. */
export function enumLabel(code: string | undefined, value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const found = code ? enums.value[code]?.find((c) => c.value === String(value)) : undefined;

    return found?.label ?? humanize(String(value));
}

const schemas = new Map<string, Promise<TableSchema>>();

export function loadSchema(name: string): Promise<TableSchema> {
    if (!schemas.has(name)) {
        schemas.set(name, get<{ data: TableSchema }>(`${metaBase()}/schemas/${encodeURIComponent(name)}`).then((r) => r.data).catch((e) => {
            schemas.delete(name);
            throw e;
        }));
    }

    return schemas.get(name)!;
}

export function humanize(value: string): string {
    const text = value.replace(/_id$/, '').replace(/[_.-]+/g, ' ').trim();

    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function propertyLabel(name: string, p?: SchemaProperty): string {
    return p?.label ?? humanize(name);
}

/** Whether a property is required for the given operation. */
export function isRequired(p: SchemaProperty, operation: 'create' | 'update'): boolean {
    return p.required_on === 'always' || p.required_on === operation;
}
