/// <reference lib="webworker" />
import { cleanupOutdatedCaches, precacheAndRoute } from 'workbox-precaching';

/*
 * First-party service worker (Chapter 12 §12.9). It caches ONLY versioned build assets, the brand
 * theme/icons (by URL, refreshed in the background) and the generic offline page. Navigations,
 * API calls, authentication, downloads and reports are always network-only: nothing authenticated
 * is cached and nothing is queued for later replay.
 */
declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: Array<{ url: string; revision: string | null }> };

const SHELL = 'shell-v1';
const BRAND = 'brand-v1';

cleanupOutdatedCaches();
precacheAndRoute(self.__WB_MANIFEST);

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(SHELL).then((cache) => cache.addAll(['/offline'])));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('shell-') && k !== SHELL || k.startsWith('brand-') && k !== BRAND)
            .map((k) => caches.delete(k)))).then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        void self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(async () => (await caches.match('/offline')) ?? Response.error()));

        return;
    }
    if (url.pathname === '/brand/theme.css' || (url.pathname.startsWith('/brand/') && /\.(png|svg|webp|jpe?g|woff2)$/.test(url.pathname))
        || url.pathname === '/manifest.webmanifest') {
        event.respondWith(caches.open(BRAND).then(async (cache) => {
            const cached = await cache.match(request);
            const network = fetch(request).then((response) => {
                if (response.ok) {
                    void cache.put(request, response.clone());
                }

                return response;
            });

            return cached ?? network;
        }));
    }
    // Everything else (API, auth, downloads): default network handling, never cached.
});
