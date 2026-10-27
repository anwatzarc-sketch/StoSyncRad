import type { NavSection, NextAction } from '@/types/shared';

/**
 * Going live, in order (Chapter 16): who the company is, how it looks, where stock lives, how
 * documents are numbered and printed, the catalog and partners, who may do what, then the opening
 * stock and a health check. Administration pages offer the following step this person can open.
 */
export const ADMIN_FLOW: Array<{ href: string; label: string; why: string }> = [
    { href: '/admin/company', label: 'Company profile', why: 'Legal name, currency and tax details appear on every document.' },
    { href: '/admin/brand', label: 'Brand', why: 'Colours and logo for the app, documents and e-mails.' },
    { href: '/r/branches', label: 'Branches', why: 'Each branch groups the warehouses it runs.' },
    { href: '/r/warehouses', label: 'Warehouses', why: 'Stock is always held in a warehouse.' },
    { href: '/r/storage-locations', label: 'Storage locations', why: 'Bins and shelves inside each warehouse.' },
    { href: '/admin/config', label: 'Configuration', why: 'Approval, costing and stock rules.' },
    { href: '/admin/numbering', label: 'Document numbering', why: 'How purchase orders, transfers and other documents are numbered.' },
    { href: '/admin/templates', label: 'Document templates', why: 'What printed documents and e-mails look like.' },
    { href: '/admin/lookups', label: 'Lookups', why: 'Reasons, payment terms and other pick lists.' },
    { href: '/admin/translations', label: 'Translations', why: 'Your own wording for labels and messages.' },
    { href: '/r/item-categories', label: 'Item categories', why: 'Categories carry tracking, expiry and storage rules for their items.' },
    { href: '/r/items', label: 'Items', why: 'Everything you stock.' },
    { href: '/r/partners', label: 'Partners', why: 'Suppliers, customers and carriers.' },
    { href: '/r/roles', label: 'Roles', why: 'What each job may do.' },
    { href: '/r/accounts', label: 'Accounts', why: 'Sign-ins for people, with their roles.' },
    { href: '/imports', label: 'Imports', why: 'Load items, partners and stock from spreadsheets.' },
    { href: '/r/opening-balances', label: 'Opening balances', why: 'The stock on hand on the day you go live.' },
    { href: '/admin/health', label: 'System health', why: 'Check everything is running before people start.' },
];

/** The set-up step after `href` that this person can open (from the server-filtered Administration navigation). */
export function nextSetupStep(href: string, nav: NavSection[]): NextAction | null {
    const visible = new Set(nav.filter((s) => s.area === 'admin').flatMap((s) => s.items.map((i) => i.href)));
    const at = ADMIN_FLOW.findIndex((s) => s.href === href);
    if (at < 0) {
        return null;
    }
    const step = ADMIN_FLOW.slice(at + 1).find((s) => visible.has(s.href));

    return step ? { label: `Next: ${step.label}`, icon: 'arrow-right', href: step.href, hint: step.why } : null;
}

/** The first set-up step this person can open. */
export function firstSetupStep(nav: NavSection[]): NextAction | null {
    const visible = new Set(nav.filter((s) => s.area === 'admin').flatMap((s) => s.items.map((i) => i.href)));
    const step = ADMIN_FLOW.find((s) => visible.has(s.href));

    return step ? { label: `Start with ${step.label.toLowerCase()}`, icon: 'arrow-right', href: step.href, hint: `Going live? Work through set-up in order. ${step.why}` } : null;
}
