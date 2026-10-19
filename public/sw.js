/* FlowSync service worker (F12): keeps the app shell and built assets available offline.
   It never touches /api, so no tenant data is ever cached here. */
const SHELL = 'flowsync-shell-v1';
const ASSETS = 'flowsync-assets-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => ![SHELL, ASSETS].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/') || url.pathname.startsWith('/broadcasting/')) {
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const hit = await cache.match(request);
                if (hit) return hit;
                const res = await fetch(request);
                if (res.ok) cache.put(request, res.clone());
                return res;
            }),
        );
        return;
    }

    if (request.mode === 'navigate' && url.pathname.startsWith('/app')) {
        event.respondWith(
            fetch(request)
                .then((res) => {
                    if (res.ok) caches.open(SHELL).then((c) => c.put('/app/', res.clone()));
                    return res;
                })
                .catch(() => caches.match('/app/').then((r) => r || Response.error())),
        );
    }
});
