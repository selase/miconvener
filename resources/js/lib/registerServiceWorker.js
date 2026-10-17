export function registerAttendeeServiceWorker() {
    if (
        typeof window === 'undefined' ||
        !('serviceWorker' in navigator) ||
        !/^\/my(?:\/|$)/.test(window.location.pathname)
    )
        return;
    const register = () =>
        navigator.serviceWorker.register('/attendee-worker.js', { scope: '/my/' }).catch(() => {});
    if (document.readyState === 'complete') register();
    else window.addEventListener('load', register, { once: true });
}
