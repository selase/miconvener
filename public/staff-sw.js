// Keeps a staff link openable with no signal: the page itself (network first,
// the cached copy when offline) and the built assets (cache first). Nothing
// else is cached -- the phone's door library decides what to do with scans,
// searches and requests when the server cannot be reached.
const CACHE = 'miconvener-staff-v2';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key.startsWith('miconvener-staff-') && key !== CACHE)
                        .map((key) => caches.delete(key))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate' && url.pathname.startsWith('/staff/')) {
        const key = url.origin + url.pathname;
        event.respondWith(
            fetch(request)
                .then((response) => {
                    // Only a page ready to scan is kept: never a PIN screen it
                    // could not unlock offline, nor a closed link.
                    if (response.ok && response.headers.get('X-Staff-State') === 'ready') {
                        const copy = response.clone();
                        event.waitUntil(caches.open(CACHE).then((cache) => cache.put(key, copy)));
                    }
                    return response;
                })
                .catch(() => caches.match(key))
        );
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ||
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }
                        return response;
                    })
            )
        );
    }
});
