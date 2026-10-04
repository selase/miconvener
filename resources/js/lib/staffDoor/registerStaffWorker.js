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
