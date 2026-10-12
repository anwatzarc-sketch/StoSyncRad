import { uuidv7 } from './uuid';

/**
 * One stable Idempotency-Key per logical attempt (Chapter 12 §12.7): the same key is reused when
 * the person retries after a network failure or timeout, so the server answers with the original
 * outcome instead of posting twice. Cleared once the server confirms the result.
 */
const PREFIX = 'idem:';

function store(): Storage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

export function attemptKey(scope: string): string {
    const s = store();
    const existing = s?.getItem(PREFIX + scope);
    if (existing) {
        return existing;
    }
    const key = uuidv7();
    s?.setItem(PREFIX + scope, key);

    return key;
}

export function completeAttempt(scope: string): void {
    store()?.removeItem(PREFIX + scope);
}
