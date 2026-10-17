import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { it, expect, vi } from 'vitest';
const source = readFileSync('resources/js/workers/attendee-sw.js', 'utf8');
function worker() {
    const listeners = {};
    const fetch = vi.fn(async () => ({
        ok: true,
        headers: { get: () => 'text/html' },
        json: async () => ({ assets: [] }),
    }));
    const cache = { put: vi.fn() };
    const caches = {
        open: async () => cache,
        keys: async () => [],
        match: vi.fn(),
        delete: vi.fn(),
    };
    runInNewContext(source, {
        self: {
            location: { origin: 'https://app.test' },
            addEventListener: (key, fn) => {
                listeners[key] = fn;
            },
            skipWaiting: async () => {},
            clients: { claim: async () => {} },
        },
        fetch,
        caches,
        Request: class {
            constructor(url, options) {
                this.url = url;
                Object.assign(this, options);
            }
        },
        URL,
        Response,
    });
    return { listeners, fetch, cache, caches };
}
it('never intercepts private JSON, PDFs, finance, material or off-origin requests', () => {
    const { listeners } = worker();
    for (const path of [
        '/my/money',
        '/my/inbox',
        '/my/communities/pilot/inbox',
        '/my/communities/pilot/inbox/item',
        '/my/communities/pilot/inbox/item/read',
        '/my/communities/pilot/inbox/read-all',
        '/my/identity',
        '/my/preferences',
        '/my/communities',
        '/my/contributions/one/receipt',
        '/my/events/one/materials/id/download',
    ]) {
        const respondWith = vi.fn();
        listeners.fetch({
            request: {
                url: `https://app.test${path}`,
                method: 'GET',
                mode: 'navigate',
                headers: { get: () => null },
            },
            respondWith,
        });
        expect(respondWith).not.toHaveBeenCalled();
    }
});
it('installs only an anonymous shell and vetted public bundle paths', async () => {
    const { listeners, fetch, cache } = worker();
    let promise;
    listeners.install({
        waitUntil: (value) => {
            promise = value;
        },
    });
    await promise;
    expect(fetch.mock.calls[0][0].credentials).toBe('omit');
    expect(fetch.mock.calls[0][0].url).toBe('/my/offline-shell');
    expect(cache.put).toHaveBeenCalledTimes(1);
});
it('returns an anonymous shell only after a navigation network failure, never after 403', async () => {
    const { listeners, fetch, caches } = worker();
    const request = {
        url: 'https://app.test/my',
        method: 'GET',
        mode: 'navigate',
        headers: { get: () => null },
    };
    let promise;
    fetch.mockResolvedValueOnce({ ok: false, status: 403 });
    listeners.fetch({
        request,
        respondWith: (value) => {
            promise = value;
        },
    });
    expect((await promise).status).toBe(403);
    expect(caches.match).not.toHaveBeenCalled();
    fetch.mockRejectedValueOnce(new Error('offline'));
    caches.match.mockResolvedValue('anonymous-shell');
    listeners.fetch({
        request,
        respondWith: (value) => {
            promise = value;
        },
    });
    expect(await promise).toBe('anonymous-shell');
});
