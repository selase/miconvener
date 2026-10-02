import { useState } from 'react';
import { Link } from '@inertiajs/react';
import ThemeToggle from '@/Components/Console/ThemeToggle';
import AiConciergeDrawer from '@/Components/Marketplace/AiConciergeDrawer';
import { Building2, ShieldCheck, Sparkles } from 'lucide-react';

export default function MarketplaceLayout({ children }) {
    const [conciergeOpen, setConciergeOpen] = useState(false);

    return (
        <div className="min-h-screen bg-canvas font-console text-ink flex flex-col">
            {/* Header */}
            <header className="sticky top-0 z-30 border-b border-border bg-surface/90 backdrop-blur-md">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                    <div className="flex items-center gap-6">
                        <Link href="/marketplace" className="flex items-center gap-2.5">
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
                            <span className="rounded bg-accent/10 px-2 py-0.5 text-[11px] font-semibold tracking-wide text-accent uppercase">
                                Marketplace
                            </span>
                        </Link>

                        <nav className="hidden md:flex items-center gap-5 text-[13px] font-medium text-ink-secondary">
                            <Link
                                href="/marketplace/venues"
                                className="hover:text-ink transition-colors"
                            >
                                Explore Venues
                            </Link>
                            <Link href="/marketplace" className="hover:text-ink transition-colors">
                                Categories
                            </Link>
                            <button
                                type="button"
                                onClick={() => setConciergeOpen(true)}
                                className="inline-flex items-center gap-1.5 rounded-full border border-purple-500/30 bg-purple-500/10 px-3 py-1 text-[12px] font-semibold text-purple-600 dark:text-purple-400 hover:bg-purple-500/20 transition-all cursor-pointer"
                            >
                                <Sparkles className="h-3.5 w-3.5" />
                                <span>AI Concierge</span>
                            </button>
                        </nav>
                    </div>

                    <div className="flex items-center gap-3">
                        <ThemeToggle />
                        <Link
                            href="/login"
                            className="hidden sm:inline-flex text-[13px] font-medium text-ink-secondary hover:text-ink px-3 py-1.5"
                        >
                            Sign In
                        </Link>
                        <Link
                            href="/register"
                            className="inline-flex items-center gap-1.5 rounded-md bg-accent px-3 py-1.5 text-[13px] font-medium text-white shadow-xs hover:bg-accent/90 transition-colors"
                        >
                            <Building2 className="h-4 w-4" />
                            <span>List Your Venue</span>
                        </Link>
                    </div>
                </div>
            </header>

            {/* Main Content */}
            <main className="flex-1">{children}</main>

            {/* Footer */}
            <footer className="border-t border-border bg-surface py-12 text-ink-secondary text-xs">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-8 md:grid-cols-4">
                        <div className="space-y-3">
                            <div className="flex items-center gap-2">
                                <span className="font-semibold text-ink text-sm">
                                    MiConvener Marketplace
                                </span>
                            </div>
                            <p className="text-[12px] leading-relaxed text-ink-secondary">
                                Ghana's dedicated event infrastructure platform. Discover verified
                                conference centres, banquet halls, and meeting spaces with
                                transparent pricing and specs.
                            </p>
                            <div className="flex items-center gap-2 text-[11px] text-accent">
                                <ShieldCheck className="h-4 w-4" />
                                <span>100% Verified Venue Supply</span>
                            </div>
                        </div>

                        <div>
                            <h4 className="font-semibold text-ink text-xs uppercase tracking-wider mb-3">
                                Popular Hubs
                            </h4>
                            <ul className="space-y-2 text-[12px]">
                                <li>
                                    <Link
                                        href="/marketplace/venues?city=Accra"
                                        className="hover:text-ink"
                                    >
                                        Venues in Accra
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href="/marketplace/venues?city=Kumasi"
                                        className="hover:text-ink"
                                    >
                                        Venues in Kumasi
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href="/marketplace/venues?city=Takoradi"
                                        className="hover:text-ink"
                                    >
                                        Venues in Takoradi
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href="/marketplace/venues?city=Tamale"
                                        className="hover:text-ink"
                                    >
                                        Venues in Tamale
                                    </Link>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <h4 className="font-semibold text-ink text-xs uppercase tracking-wider mb-3">
                                Venue Types
                            </h4>
                            <ul className="space-y-2 text-[12px]">
                                <li>
                                    <Link
                                        href="/marketplace/venues?capacity_style=banquet"
                                        className="hover:text-ink"
                                    >
                                        Banquet & Gala Halls
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href="/marketplace/venues?capacity_style=theater"
                                        className="hover:text-ink"
                                    >
                                        Plenary Auditoriums
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href="/marketplace/venues?capacity_style=classroom"
                                        className="hover:text-ink"
                                    >
                                        Executive Meeting Rooms
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href="/marketplace/venues?capacity_style=cocktail"
                                        className="hover:text-ink"
                                    >
                                        Outdoor & Cocktail Spaces
                                    </Link>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <h4 className="font-semibold text-ink text-xs uppercase tracking-wider mb-3">
                                Venue Hosts
                            </h4>
                            <ul className="space-y-2 text-[12px]">
                                <li>
                                    <Link href="/register" className="hover:text-ink">
                                        Join as a Merchant
                                    </Link>
                                </li>
                                <li>
                                    <Link href="/login" className="hover:text-ink">
                                        Host Console
                                    </Link>
                                </li>
                                <li>
                                    <span className="text-ink-tertiary">
                                        Approval-first booking management
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div className="mt-8 border-t border-border pt-6 flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px] text-ink-tertiary">
                        <div>
                            &copy; {new Date().getFullYear()} MiConvener. All rights reserved.
                        </div>
                        <div className="flex gap-4">
                            <Link href="/" className="hover:text-ink">
                                Platform Home
                            </Link>
                            <Link href="/product-enterprise" className="hover:text-ink">
                                Enterprise
                            </Link>
                        </div>
                    </div>
                </div>
            </footer>

            {/* Floating AI Concierge Action Button */}
            <div className="fixed bottom-6 right-6 z-40">
                <button
                    type="button"
                    onClick={() => setConciergeOpen(true)}
                    className="flex items-center gap-2 rounded-full bg-gradient-to-r from-accent to-purple-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xl hover:shadow-2xl hover:scale-105 active:scale-95 transition-all cursor-pointer"
                >
                    <Sparkles className="h-4 w-4" />
                    <span>Ask AI Concierge</span>
                </button>
            </div>

            {/* AI Concierge Slide-over Drawer */}
            <AiConciergeDrawer isOpen={conciergeOpen} onClose={() => setConciergeOpen(false)} />
        </div>
    );
}
