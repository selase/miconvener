import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * Separate from vite.config.js on purpose: the Laravel plugin there expects a
 * backend and a manifest, neither of which a component test has.
 *
 * Tests live under resources/js/test, never beside a page. app.jsx eagerly
 * globs every .jsx under Pages/, so a test there would be bundled into
 * production along with Vitest itself.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: { '@': path.resolve(import.meta.dirname, 'resources/js') },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/test/**/*.test.{js,jsx}'],
        setupFiles: ['resources/js/test/setup.js'],
    },
});
