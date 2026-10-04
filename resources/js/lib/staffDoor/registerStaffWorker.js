/**
 * Lets a staff link open with no signal after it has been opened once online.
 * Without the worker the page still works online, and offline while it stays
 * open.
 */
export function registerStaffWorker() {
    if (typeof window === 'undefined' || !('serviceWorker' in navigator)) return;

    navigator.serviceWorker.register('/staff-sw.js', { scope: '/staff/' }).catch(() => {
        // Registration failing (private browsing, an old browser) only loses offline reloads.
    });
}

/**
 * Removes this link's page from the offline cache once the link is finished
 * (switched off or its event over, and nothing left to send), so a phone does
 * not keep an usher's page for an event long gone. Other links are untouched.
 */
export async function forgetOfflinePage(origin, token, cacheStorage = globalThis.caches) {
    if (!cacheStorage) return;
    const names = (await cacheStorage.keys()).filter((name) =>
        name.startsWith('miconvener-staff-')
    );
    await Promise.all(
        names.map(async (name) =>
            (await cacheStorage.open(name)).delete(`${origin}/staff/${token}`)
        )
    );
}
