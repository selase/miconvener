const CACHE_NAME = 'miconvener-attendee-v2';
const PRECACHE_ASSETS = [
    '/my/manifest.json',
    '/assets/img/brand/miconvener.png',
    '/assets/img/brand/miconvener-light.png',
    '/assets/img/brand/mark-180.png',
    '/assets/img/brand/mark-512.png',
];

// The shell is fetched WITHOUT credentials on purpose. /my answers a signed-in
// visitor with their whole cross-tenant history inline in the page, and Cache
// Storage outlives signing out -- so a credentialed copy would hand the next
// person on a shared device the last person's events. Anonymous, the same URL
// returns the empty shell, which is all an offline boot needs: the saved ticket
// is read from local storage once the app is running.
const SHELL_REQUEST = new Request('/my', { credentials: 'omit' });

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return Promise.all([
                cache.addAll(PRECACHE_ASSETS).catch(() => {
                    // Precache failures should not abort installation
                }),
                fetch(SHELL_REQUEST)
                    .then((response) => (response.ok ? cache.put(SHELL_REQUEST, response) : undefined))
                    .catch(() => {
                        // An offline install simply has no shell to fall back on
                    }),
            ]);
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
                    return caches.match(SHELL_REQUEST);
                }
            });
        })
    );
});

