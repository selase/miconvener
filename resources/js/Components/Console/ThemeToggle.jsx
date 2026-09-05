import { Sun, Moon } from 'lucide-react';
import useTheme from '@/lib/useTheme';

export default function ThemeToggle({ className = '' }) {
    const [theme, toggle] = useTheme();

    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
            title={theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
            className={`flex h-8 w-8 shrink-0 items-center justify-center border border-border text-ink-secondary transition-colors duration-120 ease-out hover:border-accent hover:text-accent ${className}`}
        >
            {theme === 'dark' ? <Sun className="h-4 w-4" strokeWidth={1.6} /> : <Moon className="h-4 w-4" strokeWidth={1.6} />}
        </button>
    );
}
