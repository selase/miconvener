const CACHE_NAME = 'miconvener-attendee-v1';
const PRECACHE_ASSETS = [
    '/my',
    '/my/manifest.json',
    '/assets/img/brand/miconvener.png',
    '/assets/img/brand/miconvener-light.png',
    '/assets/img/brand/mark-180.png',
    '/assets/img/brand/mark-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS).catch(() => {
                // Precache failures should not abort installation
            });
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME && key.startsWith('miconvener-attendee-')) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);

    // Only control requests on the platform /my scope
    if (!url.pathname.startsWith('/my')) {
        return;
    }

    // Authenticated API endpoints are never stored indiscriminately in the HTTP cache
    if (event.request.headers.get('Accept')?.includes('application/json')) {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request).then((cachedResponse) => {
                if (cachedResponse) {
                    return cachedResponse;
                }
                // If it's a page navigation request under /my, return the precached shell
                if (event.request.mode === 'navigate' || event.request.headers.get('Accept')?.includes('text/html')) {
                    return caches.match('/my');
                }
            });
        })
    );
});

