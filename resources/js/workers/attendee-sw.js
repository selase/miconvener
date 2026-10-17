const CACHE_NAME = 'miconvener-attendee-v3-__BUILD_ID__';
const SHELL = '/my/offline-shell';
const STATIC = /^\/build\/assets\/[a-zA-Z0-9_-]+\.(js|css)$/;
const APP_PAGE = /^\/my\/?$|^\/my\/events\/[a-f0-9-]{36}\/?$/i;
self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHE_NAME);
            try {
                const response = await fetch(
                    new Request(SHELL, { credentials: 'omit', cache: 'no-store' })
                );
                if (response.ok && response.headers.get('content-type')?.includes('text/html'))
                    await cache.put(SHELL, response);
                const manifest = await fetch(
                    new Request('/my/offline-assets', { credentials: 'omit', cache: 'no-store' })
                );
                const data = manifest.ok ? await manifest.json() : { assets: [] };
                await Promise.all(
                    data.assets
                        .filter((path) => STATIC.test(path))
                        .map(async (path) => {
                            try {
                                const asset = await fetch(
                                    new Request(path, { credentials: 'omit' })
                                );
                                if (asset.ok) await cache.put(path, asset);
                            } catch {
                                /* Retry public assets next time online. */
                            }
                        })
                );
            } catch {
                /* Offline installation cannot create an anonymous shell. */
            }
            await self.skipWaiting();
        })()
    );
});
self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            await Promise.all(
                (await caches.keys())
                    .filter((key) => key.startsWith('miconvener-attendee-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            );
            await self.clients.claim();
        })()
    );
});
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (STATIC.test(url.pathname)) {
        event.respondWith(
            (async () => {
                const cached = await caches.match(url.pathname);
                return cached || fetch(request);
            })()
        );
        return;
    }
    if (
        request.mode !== 'navigate' ||
        !APP_PAGE.test(url.pathname) ||
        request.headers.get('X-Inertia') ||
        request.headers.get('Accept')?.includes('application/json')
    )
        return;
    event.respondWith(
        fetch(request).catch(
            async () =>
                (await caches.match(SHELL)) ||
                new Response('Connect to open MiConvener for the first time.', {
                    status: 503,
                    headers: { 'Content-Type': 'text/plain' },
                })
        )
    );
});
