import type { PermissionCode } from './generated';

export interface BrandTheme {
    colors: Record<string, string>;
    dark?: Record<string, string>;
    font: string;
    radius: string;
    density: string;
}

/** The product identity — identical for every customer (config/product.php). */
export interface Product {
    name: string;
    short_name: string;
    /** Two-tone wordmark: [primary part, accent part]. */
    wordmark: string[];
    tagline: string | null;
    vendor: string | null;
    vendor_url: string | null;
    /** Sales contact on the product website. */
    vendor_email: string | null;
    vendor_phone: string | null;
    colors: Record<string, string>;
    assets: Record<string, string>;
}

export interface Brand {
    id: string | null;
    /** Always the product name. */
    app_name: string;
    app_short_name: string;
    product: Product;
    /** The customer using the product (company profile trading name). */
    company_name: string | null;
    tagline: string | null;
    legal_footer: string | null;
    support_email: string | null;
    support_phone: string | null;
    support_url: string | null;
    version: number;
    checksum: string;
    theme: BrandTheme;
    assets: Record<string, string>;
}

export interface NavItem {
    label: string;
    href: string;
    icon: string;
    /** Other URLs that belong to this entry (a workflow hub's documents, a place's tabs). */
    match?: string[];
}

/** `work` = the daily sidebar; `stock` / `reports` = tab strips of those places; `admin` = the control plane. */
export type NavArea = 'work' | 'stock' | 'reports' | 'admin';

export interface NavSection {
    key: string;
    label: string;
    icon: string;
    area: NavArea;
    items: NavItem[];
}

export interface AuthAccount {
    id: string;
    username: string;
    display_name: string;
    email: string | null;
    is_super_admin: boolean;
    mfa_status: string | null;
}

export interface Auth {
    account: AuthAccount;
    permissions: PermissionCode[] | string[];
    scopes: unknown;
}

export interface LocaleSettings {
    current: string;
    enabled: string[] | null;
    timezone: string | null;
    date_format: string | null;
    time_format: string | null;
    datetime_format: string | null;
    decimal_separator: string | null;
    grouping_separator: string | null;
    quantity_precision: number | null;
    currency: string | null;
    currency_precision: number | null;
}

export interface SharedProps {
    auth: Auth | null;
    nav: NavSection[];
    brand: Brand;
    locale: LocaleSettings;
    features: Record<string, boolean>;
    setup: { state: string };
    flash: { success: string | null; error: string | null };
    correlation_id: string;
    [key: string]: unknown;
}

/** One API action of a resource, derived server-side from the permission-bound routes. */
export interface ResourceAction {
    name: string;
    label: string;
    method: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    uri: string;
    parameter: string;
    permission: string;
    schema: string | null;
    no_body: boolean;
    idempotent: boolean;
    /** Nested actions: URL placeholder → record field that fills it. */
    params?: Record<string, string>;
}

/** How a document is worked (WorkflowCatalog): its happy path and when each action applies. */
export interface WorkflowMeta {
    status_enum: string;
    steps: string[];
    editable: string[];
    actions: Record<string, { from: string[]; primary: boolean; task: string | null }>;
}

export interface ResourceMeta {
    key: string;
    label: string;
    /** Singular, lower-case name of one record ("purchase order"). */
    noun: string;
    schema: string | null;
    api: string;
    /** Dedicated create endpoint and its schema, when records are created outside the generic writer. */
    create_schema: string | null;
    api_create: string | null;
    can_create: boolean;
    can_update: boolean;
    relations: string[];
    actions: ResourceAction[];
    workflow: WorkflowMeta | null;
}

/** "My work" (GET /api/v1/work). */
export interface WorkQueue {
    key: string;
    label: string;
    kind: 'approve' | 'act' | 'watch';
    resource: string;
    statuses: string[];
    /** The resource's property schema (null for read-only records). */
    schema: string | null;
    hub: string | null;
    count: number;
    href: string;
    own: boolean;
}

export interface WorkLink {
    label: string;
    href: string;
    icon: string;
}

export interface WorkHub {
    key: string;
    label: string;
    icon: string;
    description: string;
    href: string;
    start: WorkLink[];
    queues: WorkQueue[];
    lists: Array<{ resource: string; label: string; href: string }>;
}

export interface WorkSummary {
    hubs: WorkHub[];
    waiting: WorkQueue[];
    start: WorkLink[];
    alerts: Array<WorkLink & { key: string; count: number; href: string | null }>;
}

/** One configuration key as declared in the registry (Chapter 03 §3.2). */
export interface ConfigKey {
    key: string;
    group: string;
    type: string;
    default: unknown;
    label: string;
    help: string;
    scopes: string[];
    enum: string | null;
    enum_values: string[] | null;
    constraints: Record<string, any>;
    sensitive: boolean;
    restart_required: boolean;
    permission: string;
}

/** Editable brand kit (Chapter 03 §3.5). */
export interface BrandForm {
    app_name: string;
    app_short_name: string;
    tagline: string | null;
    legal_footer: string | null;
    support_email: string | null;
    support_phone: string | null;
    support_url: string | null;
    theme: { colors: Record<string, string>; font: string; radius: string; density: string };
    assets: Record<string, string>;
    contrast_override_reason: string | null;
}

/** Organization drafts edited in Platform Setup step 7. */
export interface WarehouseDraft { code: string; name: string; location_pattern: string }
export interface BranchDraft { code: string; name: string; kind: string | null; warehouses: WarehouseDraft[] }
