import { describe, expect, it } from 'vitest';
import { attemptKey, completeAttempt } from './idempotency';
import { isUuid, uuidv7 } from './uuid';

describe('uuidv7', () => {
    it('produces RFC 9562 version-7 ids that sort by time', () => {
        const a = uuidv7(1_700_000_000_000);
        const b = uuidv7(1_700_000_000_001);
        expect(isUuid(a)).toBe(true);
        expect(a[14]).toBe('7');
        expect(['8', '9', 'a', 'b']).toContain(a[19]);
        expect(a < b).toBe(true);
        expect(a.slice(0, 8)).toBe((1_700_000_000_000).toString(16).padStart(12, '0').slice(0, 8));
    });
});

describe('idempotency keys', () => {
    it('reuses one key per logical attempt until it completes', () => {
        const first = attemptKey('POST:/api/v1/transfers/x/dispatch');
        expect(attemptKey('POST:/api/v1/transfers/x/dispatch')).toBe(first);
        expect(attemptKey('POST:/api/v1/transfers/y/dispatch')).not.toBe(first);
        completeAttempt('POST:/api/v1/transfers/x/dispatch');
        expect(attemptKey('POST:/api/v1/transfers/x/dispatch')).not.toBe(first);
    });
});
