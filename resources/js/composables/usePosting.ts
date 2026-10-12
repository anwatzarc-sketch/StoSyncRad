import { ref } from 'vue';
import { ApiError, api, type ApiOptions } from '@/lib/api';
import { attemptKey, completeAttempt } from '@/lib/idempotency';
import { whilePosting } from '@/lib/pwa';

/**
 * One posting action (Chapter 12 §12.7): duplicate-click guard, the same Idempotency-Key across
 * retries of this logical attempt, "Posting…" until the server answers, and the key released once
 * the outcome is known (success or a definitive refusal).
 */
export function usePosting(scope: string) {
    const busy = ref(false);
    const error = ref<unknown>(null);

    async function post<T = any>(path: string, body: unknown, opts: Omit<ApiOptions, 'method' | 'body' | 'idempotencyKey'> = {}): Promise<T | null> {
        if (busy.value) {
            return null;
        }
        busy.value = true;
        error.value = null;
        try {
            const result = await whilePosting(() => api<T>(path, { ...opts, method: 'POST', body, idempotencyKey: attemptKey(scope) }));
            completeAttempt(scope);

            return result;
        } catch (e) {
            error.value = e;
            // Validation / state / conflict errors are final for this attempt; network errors keep the key for a safe retry.
            if (e instanceof ApiError && e.status >= 400 && e.status < 500 && e.code !== 'IDEMPOTENT_IN_PROGRESS') {
                completeAttempt(scope);
            }

            return null;
        } finally {
            busy.value = false;
        }
    }

    return { busy, error, post };
}
