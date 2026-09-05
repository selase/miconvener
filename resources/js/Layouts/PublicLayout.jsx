import { Link } from '@inertiajs/react';
import ThemeToggle from '@/Components/Console/ThemeToggle';

export default function PublicLayout({ children }) {
    return (
        <div className="min-h-screen bg-canvas font-console text-ink">
            <header className="flex items-center justify-between border-b border-border px-6 py-3.5 sm:px-10">
                <Link href="/" className="text-sm font-semibold tracking-tight text-ink">
                    MiConvener
                </Link>
                <ThemeToggle />
            </header>

            {children}
        </div>
    );
}
