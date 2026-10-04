import { describe, expect, it } from 'vitest';
import { forgetOfflinePage } from '@/lib/staffDoor/registerStaffWorker';

function fakeCaches(entries) {
    const stores = Object.fromEntries(
        Object.entries(entries).map(([name, urls]) => [name, new Set(urls)])
    );
    return {
        stores,
        keys: async () => Object.keys(stores),
        open: async (name) => ({
            delete: async (url) => stores[name].delete(url),
        }),
    };
}

describe('forgetting a finished staff page', () => {
    it("removes this link's cached page and nothing else", async () => {
        const caches = fakeCaches({
            'miconvener-staff-v2': [
                'https://x.test/staff/abc',
                'https://x.test/staff/other',
                'https://x.test/build/app.js',
            ],
            'unrelated-cache': ['https://x.test/staff/abc'],
        });

        await forgetOfflinePage('https://x.test', 'abc', caches);

        expect([...caches.stores['miconvener-staff-v2']]).toEqual([
            'https://x.test/staff/other',
            'https://x.test/build/app.js',
        ]);
        expect([...caches.stores['unrelated-cache']]).toEqual(['https://x.test/staff/abc']);
    });
});
