import { useEffect, useState } from 'react';

const STORAGE_KEY = 'miconvener-theme';

function getInitialTheme() {
    if (typeof window === 'undefined') return 'light';

    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);
        if (stored === 'dark' || stored === 'light') return stored;
    } catch {
        // localStorage unavailable — fall through to system preference
    }

    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export default function useTheme() {
    const [theme, setTheme] = useState(getInitialTheme);

    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch {
            // ignore — theme just won't persist across visits
        }
    }, [theme]);

    const toggle = () => setTheme((current) => (current === 'dark' ? 'light' : 'dark'));

    return [theme, toggle];
}
