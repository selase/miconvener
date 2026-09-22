import { afterEach, vi } from 'vitest';
import { cleanup } from '@testing-library/react';

/**
 * Ziggy's route() is a browser global. Components only need a stable string,
 * which also lets a test assert which route was asked for.
 */
globalThis.route = vi.fn((name, params = {}) => `/${name}?${new URLSearchParams(params)}`);

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});
