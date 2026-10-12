/** RFC 9562 UUID version 7 (time-ordered) — used for idempotency keys (Chapter 12 §12.7). */
export function uuidv7(now: number = Date.now()): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    let ms = now;
    for (let i = 5; i >= 0; i--) {
        bytes[i] = ms % 256;
        ms = Math.floor(ms / 256);
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x70;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function isUuid(value: unknown): value is string {
    return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}
