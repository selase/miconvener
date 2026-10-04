/** In-memory store with the same interface as the IndexedDB one; used in tests. */
export function createMemoryStore() {
    const map = new Map();

    return {
        get: async (key) => structuredClone(map.get(key)),
        set: async (key, value) => {
            map.set(key, structuredClone(value));
        },
        clear: async () => {
            map.clear();
        },
    };
}

/**
 * One IndexedDB database per staff link. A guest list for a large event runs
 * to a megabyte or more, past what localStorage reliably holds on a phone.
 */
export function createIdbStore(name) {
    let database = null;

    const open = () => {
        if (database) return database;
        database = new Promise((resolve, reject) => {
            const request = indexedDB.open(name, 1);
            request.onupgradeneeded = () => request.result.createObjectStore('kv');
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
        return database;
    };

    const run = async (mode, action) => {
        const db = await open();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('kv', mode);
            const request = action(transaction.objectStore('kv'));
            transaction.oncomplete = () => resolve(request?.result);
            transaction.onerror = () => reject(transaction.error);
        });
    };

    return {
        get: (key) => run('readonly', (store) => store.get(key)),
        set: (key, value) => run('readwrite', (store) => store.put(value, key)),
        clear: () => run('readwrite', (store) => store.clear()),
    };
}
