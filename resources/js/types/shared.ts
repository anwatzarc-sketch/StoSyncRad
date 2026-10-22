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
}

export interface NavSection {
    key: string;
    label: string;
    icon: string;
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
}

export interface ResourceMeta {
    key: string;
    label: string;
    schema: string | null;
    api: string;
    /** Dedicated create endpoint and its schema, when records are created outside the generic writer. */
    create_schema: string | null;
    api_create: string | null;
    can_create: boolean;
    can_update: boolean;
    relations: string[];
    actions: ResourceAction[];
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
