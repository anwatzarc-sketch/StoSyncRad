import type { LocaleSettings } from '@/types/shared';

/**
 * One shared formatter fed by the deployment's `locale.*` configuration (Chapter 03 §3.7).
 * Quantities and money arrive as exact decimal strings and are formatted without floating point.
 */
let settings: LocaleSettings = {
    current: 'en', enabled: null, timezone: null, date_format: 'Y-m-d', time_format: 'H:i', datetime_format: 'Y-m-d H:i',
    decimal_separator: '.', grouping_separator: ',', quantity_precision: 3, currency: null, currency_precision: 2,
};

export function configureFormatting(locale: LocaleSettings | undefined): void {
    if (locale) {
        settings = { ...settings, ...Object.fromEntries(Object.entries(locale).filter(([, v]) => v !== null && v !== undefined)) };
    }
}

/** Round a decimal string half-up to `scale` places, exactly. */
export function roundDecimal(value: string, scale: number): string {
    const negative = value.trim().startsWith('-');
    const [intRaw, fracRaw = ''] = value.trim().replace(/^[-+]/, '').split('.');
    const intPart = intRaw.replace(/^0+(?=\d)/, '') || '0';
    if (fracRaw.length <= scale) {
        const frac = fracRaw.padEnd(scale, '0');
        const out = scale > 0 ? `${intPart}.${frac}` : intPart;

        return negative && /[1-9]/.test(out) ? `-${out}` : out;
    }
    const digits = (intPart + fracRaw.slice(0, scale)).split('').map(Number);
    if (Number(fracRaw[scale]) >= 5) {
        let i = digits.length - 1;
        while (i >= 0) {
            if (digits[i] === 9) {
                digits[i] = 0;
                i--;
            } else {
                digits[i]++;
                break;
            }
        }
        if (i < 0) {
            digits.unshift(1);
        }
    }
    const all = digits.join('');
    const cut = all.length - scale;
    const out = scale > 0 ? `${all.slice(0, cut) || '0'}.${all.slice(cut)}` : all;

    return negative && /[1-9]/.test(out) ? `-${out}` : out;
}

export function formatDecimal(value: unknown, scale: number, trimZeros = false): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    let text = roundDecimal(String(value), scale);
    if (trimZeros && text.includes('.')) {
        text = text.replace(/\.?0+$/, '');
    }
    const negative = text.startsWith('-');
    const [int, frac] = text.replace('-', '').split('.');
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, settings.grouping_separator ?? ',');

    return (negative ? '-' : '') + grouped + (frac !== undefined ? (settings.decimal_separator ?? '.') + frac : '');
}

export const formatQuantity = (value: unknown) => formatDecimal(value, settings.quantity_precision ?? 3, true);

export function formatMoney(value: unknown, currency?: string | null): string {
    const text = formatDecimal(value, settings.currency_precision ?? 2);
    const code = currency ?? settings.currency;

    return text === '' ? '' : code ? `${text} ${code}` : text;
}

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

interface Parts { Y: number; m: number; d: number; H: number; i: number; s: number; w: number }

function partsIn(date: Date, timeZone: string | null): Parts {
    const f = new Intl.DateTimeFormat('en-US', {
        timeZone: timeZone ?? undefined, year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric',
        weekday: 'short', hourCycle: 'h23',
    });
    const p = Object.fromEntries(f.formatToParts(date).map((x) => [x.type, x.value]));

    return { Y: +p.year, m: +p.month, d: +p.day, H: +p.hour % 24, i: +p.minute, s: +p.second, w: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(p.weekday) };
}

/** PHP-style date pattern (the same tokens the configuration uses: d j m n M F Y y H G h g i s a A D l). */
export function formatPattern(date: Date, pattern: string, timeZone: string | null): string {
    const p = partsIn(date, timeZone);
    const pad = (n: number) => String(n).padStart(2, '0');
    const map: Record<string, string> = {
        d: pad(p.d), j: String(p.d), m: pad(p.m), n: String(p.m), M: MONTHS[p.m - 1].slice(0, 3), F: MONTHS[p.m - 1], Y: String(p.Y), y: String(p.Y).slice(-2),
        H: pad(p.H), G: String(p.H), h: pad(p.H % 12 || 12), g: String(p.H % 12 || 12), i: pad(p.i), s: pad(p.s), a: p.H < 12 ? 'am' : 'pm', A: p.H < 12 ? 'AM' : 'PM',
        D: DAYS[p.w]?.slice(0, 3) ?? '', l: DAYS[p.w] ?? '',
    };
    let out = '';
    for (let k = 0; k < pattern.length; k++) {
        const ch = pattern[k];
        if (ch === '\\' && k + 1 < pattern.length) {
            out += pattern[++k];
        } else {
            out += map[ch] ?? ch;
        }
    }

    return out;
}

/** Dates (`YYYY-MM-DD`) are calendar days: formatted without a time-zone shift. */
export function formatDate(value: unknown): string {
    if (!value) {
        return '';
    }
    const text = String(value);
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        const [y, m, d] = text.split('-').map(Number);

        return formatPattern(new Date(Date.UTC(y, m - 1, d, 12)), settings.date_format ?? 'Y-m-d', 'UTC');
    }
    const date = new Date(text);

    return Number.isNaN(date.getTime()) ? text : formatPattern(date, settings.date_format ?? 'Y-m-d', settings.timezone);
}

export function formatDateTime(value: unknown): string {
    if (!value) {
        return '';
    }
    const date = new Date(String(value));

    return Number.isNaN(date.getTime()) ? String(value) : formatPattern(date, settings.datetime_format ?? 'Y-m-d H:i', settings.timezone);
}

export function relativeTime(value: unknown): string {
    if (!value) {
        return '';
    }
    const diff = (Date.now() - new Date(String(value)).getTime()) / 1000;
    const rtf = new Intl.RelativeTimeFormat(settings.current ?? 'en', { numeric: 'auto' });
    if (Math.abs(diff) < 60) {
        return rtf.format(-Math.round(diff), 'second');
    }
    if (Math.abs(diff) < 3600) {
        return rtf.format(-Math.round(diff / 60), 'minute');
    }
    if (Math.abs(diff) < 86400) {
        return rtf.format(-Math.round(diff / 3600), 'hour');
    }

    return rtf.format(-Math.round(diff / 86400), 'day');
}
