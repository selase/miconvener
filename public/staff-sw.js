// Keeps a staff link openable with no signal: the page itself (network first,
// the cached copy when offline) and the built assets (cache first). Nothing
// else is cached -- the phone's door library decides what to do with scans,
// searches and requests when the server cannot be reached.
const CACHE = 'miconvener-staff-v3';

const BUILD_ASSET = /\/build\/[^"'\s)]+/g;

/**
 * After a deploy the cached pages point at new built files; the old ones
 * are dropped once no cached staff page refers to them.
 */
async function pruneBuildAssets(cache) {
    const requests = await cache.keys();
    const pages = requests.filter((request) => new URL(request.url).pathname.startsWith('/staff/'));
    const used = new Set();
    for (const request of pages) {
        const response = await cache.match(request.url);
        const html = response ? await response.text() : '';
        for (const asset of html.match(BUILD_ASSET) ?? []) used.add(new URL(asset, request.url).href);
    }
    await Promise.all(
        requests
            .filter((request) => new URL(request.url).pathname.startsWith('/build/') && !used.has(request.url))
            .map((request) => cache.delete(request.url))
    );
}

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
                        event.waitUntil(
                            caches.open(CACHE).then(async (cache) => {
                                await cache.put(key, copy);
                                await pruneBuildAssets(cache);
                            })
                        );
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
                            event.waitUntil(caches.open(CACHE).then((cache) => cache.put(request, copy)));
                        }
                        return response;
                    })
            )
        );
    }
});
