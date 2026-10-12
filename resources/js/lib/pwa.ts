import { reactive } from 'vue';

/**
 * Service-worker lifecycle (Chapter 12 §12.9): register at the root scope, surface "update
 * available", and activate the new worker only when the person chooses — never during a posting.
 */
export const pwaState = reactive({ updateReady: false, online: typeof navigator === 'undefined' ? true : navigator.onLine, busy: 0 });

let waiting: ServiceWorker | null = null;

export function registerServiceWorker(): void {
    window.addEventListener('online', () => (pwaState.online = true));
    window.addEventListener('offline', () => (pwaState.online = false));
    if (!('serviceWorker' in navigator) || import.meta.env.DEV || window.location.protocol !== 'https:' && window.location.hostname !== 'localhost') {
        return;
    }
    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            const track = (worker: ServiceWorker | null) => {
                worker?.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        waiting = worker;
                        pwaState.updateReady = true;
                    }
                });
            };
            if (registration.waiting && navigator.serviceWorker.controller) {
                waiting = registration.waiting;
                pwaState.updateReady = true;
            }
            registration.addEventListener('updatefound', () => track(registration.installing));
            let reloading = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (!reloading) {
                    reloading = true;
                    window.location.reload();
                }
            });
        } catch {
            // The app works without the worker; installability is a progressive enhancement.
        }
    });
}

/** Apply a downloaded update (refused while a posting is in flight). */
export function applyUpdate(): boolean {
    if (pwaState.busy > 0 || !waiting) {
        return false;
    }
    waiting.postMessage({ type: 'SKIP_WAITING' });

    return true;
}

/** Mark a posting in flight so updates wait (Chapter 12 §12.9 "never reload during a posting"). */
export async function whilePosting<T>(work: () => Promise<T>): Promise<T> {
    pwaState.busy++;
    try {
        return await work();
    } finally {
        pwaState.busy--;
    }
}
