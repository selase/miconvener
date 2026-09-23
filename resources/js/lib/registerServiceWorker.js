export function registerAttendeeServiceWorker() {
    if (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        window.location.pathname.startsWith('/my')
    ) {
        window.addEventListener('load', () => {
            navigator.serviceWorker
                .register('/attendee-sw.js', { scope: '/my/' })
                .catch(() => {
                    // Service worker registration failures fail safely without disrupting user experience
                });
        });
    }
}
