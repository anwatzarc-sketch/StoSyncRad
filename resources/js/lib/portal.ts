import { reactive, ref } from 'vue';
import { get } from './api';

/**
 * Customer Portal client helpers: the signed-in customer's profile and the cart. The cart is a
 * convenience kept in this browser only (product ids and quantities); prices, availability and the
 * order itself are always decided by the server when the customer submits.
 */

export interface PortalProfile {
    account: { name: string; email: string; mfa_enrolled: boolean; last_login_at: string | null };
    customer: { name: string; code: string; status: string };
    permissions: string[];
}

export interface CartLine {
    item_id: string;
    name: string;
    sku: string;
    unit: string | null;
    price: string | null;
    currency: string | null;
    quantity: number;
}

/** A product as the customer sees it (no costs, no stock locations). */
export interface PortalItem {
    id: string;
    sku: string;
    name: string;
    description: string | null;
    category: string | null;
    unit: string | null;
    unit_name: string | null;
    price: string | null;
    currency: string | null;
    availability: { band: string; quantity?: string } | null;
    min_order_qty: string | null;
    order_multiple: string | null;
    image_url: string | null;
    orderable: boolean;
}

export const profile = ref<PortalProfile | null>(null);
let loading: Promise<PortalProfile> | null = null;

export function loadProfile(force = false): Promise<PortalProfile> {
    if (profile.value && !force) {
        return Promise.resolve(profile.value);
    }
    loading ??= get<{ data: PortalProfile }>('/api/v1/portal/me').then((r) => (profile.value = r.data)).finally(() => (loading = null));

    return loading;
}

export const canDo = (permission: string) => !!profile.value?.permissions.includes(permission);

// ---- Cart -------------------------------------------------------------------------------------------

const CART_KEY = 'portal:cart';

function readCart(): CartLine[] {
    try {
        const raw = window.localStorage.getItem(CART_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed) ? parsed.filter((l) => typeof l?.item_id === 'string' && Number(l.quantity) > 0) : [];
    } catch {
        return [];
    }
}

export const cart = reactive<{ lines: CartLine[] }>({ lines: readCart() });

function saveCart(): void {
    try {
        window.localStorage.setItem(CART_KEY, JSON.stringify(cart.lines));
    } catch {
        // storage unavailable (private window): the cart lives for this page only
    }
}

export function addToCart(line: Omit<CartLine, 'quantity'>, quantity: number): void {
    const existing = cart.lines.find((l) => l.item_id === line.item_id);
    if (existing) {
        existing.quantity += quantity;
    } else {
        cart.lines.push({ ...line, quantity });
    }
    saveCart();
}

export function setQuantity(itemId: string, quantity: number): void {
    const line = cart.lines.find((l) => l.item_id === itemId);
    if (line) {
        line.quantity = Math.max(0, quantity);
        cart.lines = cart.lines.filter((l) => l.quantity > 0);
        saveCart();
    }
}

export function clearCart(): void {
    cart.lines = [];
    saveCart();
}

export const cartCount = () => cart.lines.length;

/** Plain-language tone for a status badge in the portal. */
export function statusTone(status: string): 'ok' | 'wait' | 'bad' | 'info' {
    if (['delivered', 'paid', 'verified', 'accepted', 'approved', 'recorded', 'resolved', 'received_in_full'].includes(status)) {
        return 'ok';
    }
    if (['cancelled', 'declined', 'rejected', 'void', 'expired', 'failed', 'returned', 'withdrawn'].includes(status)) {
        return 'bad';
    }
    if (['sent', 'info_requested', 'issued', 'partially_paid', 'overdue'].includes(status)) {
        return 'wait';
    }

    return 'info';
}
