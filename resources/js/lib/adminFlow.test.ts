import { describe, expect, it } from 'vitest';
import { firstSetupStep, nextSetupStep } from './adminFlow';
import type { NavSection } from '@/types/shared';

const nav = (hrefs: string[]): NavSection[] => [{ key: 'platform', label: 'Platform', icon: 'settings', area: 'admin', items: hrefs.map((href) => ({ label: href, href, icon: 'x' })) }];

describe('going-live set-up order', () => {
    it('offers the next step the person can open, skipping the ones they cannot', () => {
        expect(nextSetupStep('/admin/company', nav(['/admin/company', '/r/warehouses']))).toMatchObject({ href: '/r/warehouses', label: 'Next: Warehouses' });
        expect(nextSetupStep('/admin/health', nav(['/admin/health']))).toBeNull();
        expect(nextSetupStep('/admin/audit', nav(['/admin/audit', '/admin/health']))).toBeNull();
    });

    it('starts at the first visible step', () => {
        expect(firstSetupStep(nav(['/r/items', '/admin/brand']))?.href).toBe('/admin/brand');
        expect(firstSetupStep(nav([]))).toBeNull();
    });
});
