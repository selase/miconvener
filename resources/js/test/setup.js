import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';
import { createElement } from 'react';

/**
 * Inertia's Head, Link and router all reach for an app context that only exists
 * once Inertia has mounted a page. A component test mounts the component alone,
 * so they are replaced with the smallest stand-ins that still render children
 * and let a test assert what a click asked for. Written with createElement
 * rather than JSX because this file is plain .js.
 */
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }) => createElement('a', { href, ...rest }, children),
    usePage: () => ({ props: {}, url: '/', component: '' }),
    router: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
}));

/**
 * Ziggy's route() is a browser global. Components only need a stable string,
 * which also lets a test assert which route was asked for.
 */
globalThis.route = vi.fn((name, params = {}) => `/${name}?${new URLSearchParams(params)}`);

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});
