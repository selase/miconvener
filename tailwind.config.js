import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{js,jsx,ts,tsx}',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Nunito', ...defaultTheme.fontFamily.sans],
                console: ['Geist', ...defaultTheme.fontFamily.sans],
                mono: ['"Geist Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                surface: {
                    DEFAULT: 'var(--color-surface)',
                    sunken: 'var(--color-surface-sunken)',
                    hover: 'var(--color-surface-hover)',
                },
                ink: {
                    DEFAULT: 'var(--color-text)',
                    secondary: 'var(--color-text-secondary)',
                    tertiary: 'var(--color-text-tertiary)',
                },
                border: {
                    DEFAULT: 'var(--color-border)',
                    strong: 'var(--color-border-strong)',
                },
                accent: {
                    DEFAULT: 'var(--color-accent)',
                    soft: 'var(--color-accent-soft)',
                    ink: 'var(--color-accent-ink)',
                    graph: 'var(--color-accent)',
                },
                inverse: {
                    DEFAULT: 'var(--color-inverse)',
                    ink: 'var(--color-inverse-ink)',
                },
                success: { fg: 'var(--color-success-fg)', bg: 'var(--color-success-bg)' },
                warning: { fg: 'var(--color-warning-fg)', bg: 'var(--color-warning-bg)' },
                danger: { fg: 'var(--color-danger-fg)', bg: 'var(--color-danger-bg)' },
                neutral: { fg: 'var(--color-neutral-fg)', bg: 'var(--color-neutral-bg)' },
                canvas: 'var(--color-ink)',
            },
            borderRadius: {
                DEFAULT: '0px',
                none: '0px',
                sm: '0px',
                md: '0px',
                lg: '0px',
                xl: '0px',
                full: '9999px',
            },
            boxShadow: {
                float: '0 16px 40px -12px rgba(0,0,0,.24), 0 2px 6px rgba(0,0,0,.08)',
                raised: '0 1px 2px rgba(0,0,0,.06)',
            },
            spacing: {
                control: '40px',
            },
            letterSpacing: {
                tight: '-0.02em',
                tighter: '-0.045em',
            },
            transitionDuration: {
                120: '120ms',
            },
        },
    },

    plugins: [forms],
};
