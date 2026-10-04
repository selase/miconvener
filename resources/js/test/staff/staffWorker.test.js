import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import path from 'node:path';

/**
 * Runs public/staff-sw.js against fake caches: the worker is a plain script,
 * so it is evaluated with its own `self` and `caches`.
 */
function loadWorker(initial) {
    const stores = Object.fromEntries(
        Object.entries(initial).map(([name, entries]) => [name, new Map(entries)])
    );
    const caches = {
        keys: async () => Object.keys(stores),
        delete: async (name) => delete stores[name],
        open: async (name) => {
            stores[name] ??= new Map();
            const store = stores[name];
            const key = (request) => (typeof request === 'string' ? request : request.url);
            return {
                put: async (request, response) => store.set(key(request), response),
                delete: async (request) => store.delete(key(request)),
                keys: async () => [...store.keys()].map((url) => ({ url })),
                match: async (request) => store.get(key(request)),
            };
        },
        match: async () => undefined,
    };
    const listeners = {};
    const self = {
        location: { origin: 'https://x.test' },
        addEventListener: (type, fn) => (listeners[type] = fn),
        skipWaiting() {},
        clients: { claim: async () => {} },
    };
    const source = readFileSync(path.resolve('public/staff-sw.js'), 'utf8');
    new Function('self', 'caches', 'fetch', source)(self, caches, globalThis.fetchForWorker);
    return { stores, listeners };
}

function page(html, state = 'ready') {
    return {
        ok: true,
        headers: { get: (name) => (name === 'X-Staff-State' ? state : null) },
        clone() {
            return { ...this, text: async () => html };
        },
        text: async () => html,
    };
}

async function navigate(listeners, url) {
    const pending = [];
    let responded;
    listeners.fetch({
        request: { method: 'GET', url, mode: 'navigate' },
        respondWith: (promise) => (responded = promise),
        waitUntil: (promise) => pending.push(promise),
    });
    await responded;
    await Promise.all(pending);
}

describe('staff offline worker', () => {
    it('drops built files no cached staff page uses any more', async () => {
        globalThis.fetchForWorker = async () =>
            page('<script src="/build/assets/app-NEW.js"></script>');
        const { stores, listeners } = loadWorker({
            'miconvener-staff-v3': [
                ['https://x.test/build/assets/app-OLD.js', 'old'],
                ['https://x.test/build/assets/app-NEW.js', 'new'],
            ],
        });

        await navigate(listeners, 'https://x.test/staff/abc');

        const cached = [...stores['miconvener-staff-v3'].keys()];
        expect(cached).toContain('https://x.test/build/assets/app-NEW.js');
        expect(cached).toContain('https://x.test/staff/abc');
        expect(cached).not.toContain('https://x.test/build/assets/app-OLD.js');
    });

    it('does not cache a PIN page', async () => {
        globalThis.fetchForWorker = async () => page('<p>PIN</p>', 'pin');
        const { stores, listeners } = loadWorker({ 'miconvener-staff-v3': [] });

        await navigate(listeners, 'https://x.test/staff/abc');

        expect([...stores['miconvener-staff-v3'].keys()]).toEqual([]);
    });
});
