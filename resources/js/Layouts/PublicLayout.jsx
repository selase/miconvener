import { Link } from '@inertiajs/react';
import ThemeToggle from '@/Components/Console/ThemeToggle';

export default function PublicLayout({ children }) {
    return (
        <div className="min-h-screen bg-canvas font-console text-ink">
            <header className="flex items-center justify-between border-b border-border px-6 py-3.5 sm:px-10">
                <Link href="/" className="flex items-center" aria-label="MiConvener">
                    {/* The wordmark is navy on light and reversed on dark; both are
                        rendered and the theme picks one, so there is no flash of the
                        wrong one while JS boots. */}
                    <img
                        src="/assets/img/brand/miconvener.png"
                        srcSet="/assets/img/brand/miconvener.png 1x, /assets/img/brand/miconvener@2x.png 2x"
                        alt="MiConvener"
                        width={1228}
                        height={229}
                        className="h-6 w-auto dark:hidden"
                    />
                    <img
                        src="/assets/img/brand/miconvener-light.png"
                        srcSet="/assets/img/brand/miconvener-light.png 1x, /assets/img/brand/miconvener-light@2x.png 2x"
                        alt=""
                        aria-hidden="true"
                        width={1228}
                        height={229}
                        className="hidden h-6 w-auto dark:block"
                    />
                </Link>
                <ThemeToggle />
            </header>

            {children}
        </div>
    );
}
