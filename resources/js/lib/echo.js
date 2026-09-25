import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Gives the console a socket to listen on.
 *
 * The Blade side gets this through bootstrap.js, which the Inertia entry point
 * does not load -- so until now every panel's `if (window.Echo)` guard was
 * quietly false and nothing real-time ever ran. This is the Echo half only:
 * pulling in bootstrap.js wholesale would put lodash and axios in the Inertia
 * bundle to get it.
 *
 * Built only when a key is configured. A build without Reverb leaves
 * window.Echo undefined, which the panels already handle, rather than leaving a
 * client retrying a connection to nowhere.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

if (key) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
