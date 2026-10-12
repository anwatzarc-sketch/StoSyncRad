import { beforeEach, describe, expect, it } from 'vitest';
import { configureFormatting, formatDate, formatDecimal, formatMoney, formatPattern, formatQuantity, roundDecimal } from './format';

describe('exact decimal formatting', () => {
    beforeEach(() => configureFormatting({
        current: 'en', enabled: null, timezone: 'Africa/Addis_Ababa', date_format: 'd/m/Y', time_format: 'H:i', datetime_format: 'd/m/Y H:i',
        decimal_separator: ',', grouping_separator: '.', quantity_precision: 3, currency: 'ETB', currency_precision: 2,
    }));

    it('rounds half-up on strings without floating point drift', () => {
        expect(roundDecimal('1.005', 2)).toBe('1.01');
        expect(roundDecimal('0.1', 3)).toBe('0.100');
        expect(roundDecimal('999.995', 2)).toBe('1000.00');
        expect(roundDecimal('-2.345', 2)).toBe('-2.35');
        expect(roundDecimal('-0.0004', 3)).toBe('0.000');
        expect(roundDecimal('12345678901234567890.123456', 4)).toBe('12345678901234567890.1235');
    });

    it('uses the configured separators and precision', () => {
        expect(formatDecimal('1234567.5', 2)).toBe('1.234.567,50');
        expect(formatQuantity('12.500000')).toBe('12,5');
        expect(formatQuantity('3')).toBe('3');
        expect(formatMoney('1999.999')).toBe('2.000,00 ETB');
        expect(formatDecimal(null, 2)).toBe('');
    });

    it('formats calendar dates without a time-zone shift and datetimes in the configured zone', () => {
        expect(formatDate('2026-01-31')).toBe('31/01/2026');
        expect(formatPattern(new Date('2026-03-01T21:30:00Z'), 'Y-m-d H:i', 'Africa/Addis_Ababa')).toBe('2026-03-02 00:30');
        expect(formatPattern(new Date('2026-03-01T09:05:00Z'), 'D, j M y g:i A', 'UTC')).toBe('Sun, 1 Mar 26 9:05 AM');
    });
});
