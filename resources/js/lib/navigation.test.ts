import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn() } }));

const { homeFor, isAppPath, parentOf } = await import('./navigation');

describe('home and back targets', () => {
    it('sends each area home to its own start page', () => {
        expect(homeFor('/stock').href).toBe('/');
        expect(homeFor('/portal/orders/1').href).toBe('/portal/dashboard');
        expect(homeFor('/portal/register').href).toBe('/portal');
        expect(parentOf('/')).toBeNull();
        expect(parentOf('/portal/dashboard')).toBeNull();
    });

    it('goes back from a record to its list and from an edit to the record', () => {
        expect(parentOf('/r/purchase-orders/0190-abc')).toEqual({ href: '/r/purchase-orders', label: 'Purchase orders' });
        expect(parentOf('/r/purchase-orders/new?via=x')).toEqual({ href: '/r/purchase-orders', label: 'Purchase orders' });
        expect(parentOf('/r/items/0190-abc/edit')?.href).toBe('/r/items/0190-abc');
        expect(parentOf('/portal/orders/0190-abc')).toEqual({ href: '/portal/orders', label: 'Orders' });
    });

    it('goes back from a tab to its place and from a top-level page to home', () => {
        expect(parentOf('/stock/items/1')?.href).toBe('/stock');
        expect(parentOf('/trace')?.href).toBe('/stock');
        expect(parentOf('/admin/brand')?.href).toBe('/admin');
        expect(parentOf('/reports/runs')?.href).toBe('/reports');
        expect(parentOf('/work/receiving')?.href).toBe('/work');
        expect(parentOf('/notifications')).toEqual({ href: '/', label: 'Home' });
        expect(parentOf('/password/reset')?.href).toBe('/login');
    });

    it('accepts only in-app paths as link targets from the address bar', () => {
        expect(isAppPath('/work/sales?queue=pick-lists.pick')).toBe(true);
        expect(['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', '', null].map(isAppPath)).toEqual([false, false, false, false, false, false]);
    });
});
