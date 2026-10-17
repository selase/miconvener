export function registerAttendeeServiceWorker() {
    if (
        typeof window === 'undefined' ||
        !('serviceWorker' in navigator) ||
        !/^\/my(?:\/|$)/.test(window.location.pathname)
    )
        return;
    const register = async () => {
        try {
            await navigator.serviceWorker.register('/attendee-worker.js', { scope: '/my' });
            const registrations = await navigator.serviceWorker.getRegistrations();
            await Promise.all(
                registrations
                    .filter(
                        (registration) =>
                            new URL(registration.scope).pathname === '/my/' &&
                            [
                                registration.active,
                                registration.waiting,
                                registration.installing,
                            ].some(
                                (worker) =>
                                    worker &&
                                    new URL(worker.scriptURL).pathname === '/attendee-worker.js'
                            )
                    )
                    .map((registration) => registration.unregister())
            );
        } catch {
            /* Offline access stays optional when worker installation is unavailable. */
        }
    };
    if (document.readyState === 'complete') register();
    else window.addEventListener('load', register, { once: true });
}
