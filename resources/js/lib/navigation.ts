import { router } from '@inertiajs/vue3';

/**
 * Where "Home" and "Back" lead from any page (every page offers both). Back goes to the page's
 * logical parent — the list a record belongs to, the place a tab belongs to — so it works the same
 * after a refresh, from a link or in a new tab. Top-level pages have no parent but Home: there Back
 * returns to the previous page of this visit when there is one. Pages that know better pass their own.
 */
export interface NavTarget {
    href: string;
    label: string;
}

const PORTAL_SIGNED_IN = /^\/portal\/(dashboard|catalog|cart|quotations|orders|invoices|payments|account|team)(\/|$)/;

const humanize = (slug: string) => {
    const words = slug.replace(/[-_]+/g, ' ').trim();

    return words.charAt(0).toUpperCase() + words.slice(1);
};

/** The home page of the area a path belongs to (staff app, customer portal, its public pages, setup). */
export function homeFor(path: string): NavTarget {
    if (path.startsWith('/portal')) {
        return PORTAL_SIGNED_IN.test(path) ? { href: '/portal/dashboard', label: 'Home' } : { href: '/portal', label: 'Portal home' };
    }
    if (path.startsWith('/setup')) {
        return { href: '/setup', label: 'Setup' };
    }

    return { href: '/', label: 'Home' };
}

/** Logical parent of a path; null for the home page itself. */
export function parentOf(path: string): NavTarget | null {
    const p = path.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
    const home = homeFor(p);
    if (p === home.href) {
        return null;
    }
    const rules: Array<[RegExp, (m: RegExpMatchArray) => NavTarget]> = [
        [/^\/r\/([a-z-]+)\/([^/]+)\/edit$/, (m) => ({ href: `/r/${m[1]}/${m[2]}`, label: 'Record' })],
        [/^\/r\/([a-z-]+)\/[^/]+$/, (m) => ({ href: `/r/${m[1]}`, label: humanize(m[1]) })],
        [/^\/stock\/items\/[^/]+$/, () => ({ href: '/stock', label: 'Stock' })],
        [/^\/(scan|trace|valuation)$/, () => ({ href: '/stock', label: 'Stock' })],
        [/^\/(carriers|imports)$/, () => ({ href: '/admin', label: 'Administration' })],
        [/^\/planning\/replenishment$/, () => ({ href: '/work/purchasing', label: 'Purchasing' })],
        [/^\/tasks\/receiving\/[^/]+$/, () => ({ href: '/tasks/receiving', label: 'Receive goods' })],
        [/^\/tasks\/(receiving|putaway)$/, () => ({ href: '/work/receiving', label: 'Receive' })],
        [/^\/work\/[a-z-]+$/, () => ({ href: '/work', label: 'Work' })],
        [/^\/reports\/.+$/, () => ({ href: '/reports', label: 'Reports' })],
        [/^\/admin\/.+$/, () => ({ href: '/admin', label: 'Administration' })],
        [/^\/password\/reset$/, () => ({ href: '/login', label: 'Sign in' })],
        [/^\/portal\/password\/reset$/, () => ({ href: '/portal/login', label: 'Sign in' })],
        [/^\/portal\/verify-email\/resend$/, () => ({ href: '/portal/verify-email', label: 'Verify e-mail' })],
        [/^\/portal\/(catalog|quotations|orders|invoices|payments)\/[^/]+$/, (m) => ({ href: `/portal/${m[1]}`, label: m[1] === 'catalog' ? 'Products' : humanize(m[1]) })],
    ];
    for (const [pattern, target] of rules) {
        const m = p.match(pattern);
        if (m) {
            return target(m);
        }
    }

    return home;
}

let visits = 0;

/** Count in-app page visits so Back can return to the previous page of this visit (set up once in app.ts). */
export function trackVisits(): void {
    router.on('navigate', (event) => {
        if (event.detail.type === 'visit') {
            visits++;
        }
    });
}

/** True once this tab has moved between pages of the app (a Back through history stays in the app). */
export const hasInAppHistory = (): boolean => visits > 0;

/** A same-origin, in-app path (`/…`, not `//host` or a scheme), safe to use as a link from a query parameter. */
export function isAppPath(value: unknown): value is string {
    return typeof value === 'string' && /^\/(?![/\\])[^\s]*$/.test(value);
}
