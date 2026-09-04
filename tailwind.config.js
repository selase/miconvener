import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
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
                console: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                surface: {
                    DEFAULT: '#FFFFFF',
                    sunken: '#F7F7F8',
                    hover: '#F2F2F4',
                },
                ink: {
                    DEFAULT: '#111113',
                    secondary: '#6B7280',
                    tertiary: '#9CA3AF',
                },
                border: {
                    DEFAULT: '#E8E8EC',
                    strong: '#D9D9DE',
                },
                accent: {
                    DEFAULT: '#2563EB',
                    graph: '#4F46E5',
                },
                inverse: '#0A0A0A',
                success: { fg: '#0F7A4D', bg: '#E9F7F0' },
                warning: { fg: '#9A6400', bg: '#FDF5E3' },
                danger: { fg: '#C4281C', bg: '#FDF0EF' },
                neutral: { fg: '#4B5563', bg: '#F1F1F3' },
            },
            borderRadius: {
                sm: '6px',
                md: '10px',
                lg: '12px',
                xl: '16px',
            },
            boxShadow: {
                float: '0 16px 40px -12px rgba(17,17,19,.18), 0 2px 6px rgba(17,17,19,.06)',
                raised: '0 1px 2px rgba(17,17,19,.06)',
            },
            spacing: {
                control: '44px',
            },
            transitionDuration: {
                120: '120ms',
            },
        },
    },

    plugins: [forms],
};
