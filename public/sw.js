// SOPHIX Field service worker — installable PWA shell + offline-tolerant reads.
const SHELL = 'sophix-shell-v1';
const API = 'sophix-api-v1';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(SHELL).then((c) => c.addAll(['/m'])));
    self.skipWaiting();
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => ![SHELL, API].includes(k)).map((k) => caches.delete(k)))),
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Read APIs: network-first, fall back to cache so cached jobs/orders show offline.
    if (url.pathname.startsWith('/api/') && request.method === 'GET') {
        event.respondWith(
            fetch(request)
                .then((res) => { const copy = res.clone(); caches.open(API).then((c) => c.put(request, copy)); return res; })
                .catch(() => caches.match(request)),
        );
        return;
    }

    // App navigation + built assets: cache-first shell fallback.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/m')));
        return;
    }
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(caches.match(request).then((c) => c || fetch(request).then((res) => { const copy = res.clone(); caches.open(SHELL).then((cc) => cc.put(request, copy)); return res; })));
    }
});
