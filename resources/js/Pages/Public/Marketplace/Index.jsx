import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import {
    Building2,
    Search,
    MapPin,
    Users,
    ShieldCheck,
    CheckCircle2,
    ArrowRight,
    Sparkles,
    Calendar,
    Zap,
    Utensils,
    Tv,
    FileText,
} from 'lucide-react';

export default function MarketplaceIndex({ featuredVenues = [], featuredMerchants = [] }) {
    const [q, setQ] = useState('');
    const [city, setCity] = useState('');
    const [minCapacity, setMinCapacity] = useState('');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get('/marketplace/venues', {
            q: q || undefined,
            city: city || undefined,
            min_capacity: minCapacity || undefined,
        });
    };

    const getMediaUrl = (media) => {
        if (!media) return null;
        if (typeof media === 'string') return media;
        return media.url || media.file_path || null;
    };

    const formatPrice = (pesewas, pricingModel, priceVisibility) => {
        if (priceVisibility === 'on_request') {
            return 'Price on request';
        }
        const ghs = (pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        });
        const modelLabel =
            {
                per_day: '/ day',
                per_half_day: '/ half day',
                per_hour: '/ hr',
                flat_rate: 'flat rate',
            }[pricingModel] ?? '';
        return `GHS ${ghs} ${modelLabel}`;
    };

    return (
        <MarketplaceLayout>
            <Head title="MiConvener Marketplace — Verified Venues & Event Spaces in Ghana" />

            {/* Hero Section */}
            <section className="relative overflow-hidden border-b border-border bg-gradient-to-b from-surface to-canvas py-16 sm:py-24">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                    <div className="inline-flex items-center gap-2 rounded-full border border-accent/20 bg-accent/5 px-3 py-1 text-xs font-medium text-accent mb-6">
                        <Sparkles className="h-3.5 w-3.5" />
                        <span>MiConvener Marketplace: Venues & Event Spaces</span>
                    </div>

                    <h1 className="text-3xl sm:text-5xl font-semibold tracking-tight text-ink max-w-3xl mx-auto leading-tight">
                        Discover & book verified event spaces across Ghana.
                    </h1>
                    <p className="mt-4 text-base sm:text-lg text-ink-secondary max-w-2xl mx-auto">
                        From luxury beachfront ballrooms to auditorium plenary halls. Compare
                        transparent capacity breakdowns, generator backups, and included amenities
                        with approval-first bookings.
                    </p>

                    {/* Quick Search Card */}
                    <div className="mt-8 max-w-4xl mx-auto">
                        <form
                            onSubmit={handleSearch}
                            className="flex flex-col md:flex-row items-stretch gap-3 rounded-xl border border-border bg-surface p-3 shadow-lg"
                        >
                            <div className="relative flex-1">
                                <Search className="absolute left-3.5 top-3.5 h-4 w-4 text-ink-tertiary" />
                                <input
                                    type="text"
                                    placeholder="Search by venue name or keyword..."
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    className="w-full rounded-lg border border-border/60 bg-canvas py-2.5 pl-10 pr-3 text-xs text-ink placeholder:text-ink-tertiary focus:border-accent focus:ring-1 focus:ring-accent"
                                />
                            </div>

                            <div className="relative md:w-52">
                                <MapPin className="absolute left-3.5 top-3.5 h-4 w-4 text-ink-tertiary" />
                                <select
                                    value={city}
                                    onChange={(e) => setCity(e.target.value)}
                                    className="w-full rounded-lg border border-border/60 bg-canvas py-2.5 pl-10 pr-8 text-xs text-ink focus:border-accent focus:ring-1 focus:ring-accent"
                                >
                                    <option value="">All Cities (Ghana)</option>
                                    <option value="Accra">Accra</option>
                                    <option value="Kumasi">Kumasi</option>
                                    <option value="Takoradi">Takoradi</option>
                                    <option value="Cape Coast">Cape Coast</option>
                                    <option value="Tamale">Tamale</option>
                                </select>
                            </div>

                            <div className="relative md:w-44">
                                <Users className="absolute left-3.5 top-3.5 h-4 w-4 text-ink-tertiary" />
                                <select
                                    value={minCapacity}
                                    onChange={(e) => setMinCapacity(e.target.value)}
                                    className="w-full rounded-lg border border-border/60 bg-canvas py-2.5 pl-10 pr-8 text-xs text-ink focus:border-accent focus:ring-1 focus:ring-accent"
                                >
                                    <option value="">Any Capacity</option>
                                    <option value="50">50+ Attendees</option>
                                    <option value="150">150+ Attendees</option>
                                    <option value="300">300+ Attendees</option>
                                    <option value="500">500+ Attendees</option>
                                    <option value="1000">1,000+ Attendees</option>
                                </select>
                            </div>

                            <button
                                type="submit"
                                className="inline-flex items-center justify-center gap-2 rounded-lg bg-accent px-6 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-accent/90 transition-colors"
                            >
                                <Search className="h-4 w-4" />
                                <span>Find Spaces</span>
                            </button>
                        </form>
                    </div>

                    {/* Quick Pill Suggestions */}
                    <div className="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs text-ink-secondary">
                        <span className="text-ink-tertiary">Popular:</span>
                        <Link
                            href="/marketplace/venues?city=Accra"
                            className="rounded-full border border-border px-3 py-1 hover:border-accent hover:text-ink"
                        >
                            Accra Venues
                        </Link>
                        <Link
                            href="/marketplace/venues?city=Kumasi"
                            className="rounded-full border border-border px-3 py-1 hover:border-accent hover:text-ink"
                        >
                            Kumasi Venues
                        </Link>
                        <Link
                            href="/marketplace/venues?capacity_style=banquet&min_capacity=300"
                            className="rounded-full border border-border px-3 py-1 hover:border-accent hover:text-ink"
                        >
                            Large Banquets (300+)
                        </Link>
                        <Link
                            href="/marketplace/venues?amenities[]=standby_generator"
                            className="rounded-full border border-border px-3 py-1 hover:border-accent hover:text-ink"
                        >
                            ⚡ Standby Generator Guaranteed
                        </Link>
                    </div>
                </div>
            </section>

            {/* Marketplace Categories */}
            <section className="border-b border-border bg-surface py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-8">
                        <div>
                            <h2 className="text-xl font-semibold text-ink">
                                Marketplace Directory
                            </h2>
                            <p className="text-xs text-ink-secondary">
                                Everything required to convene exceptional events.
                            </p>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {/* Venues - Active Anchor */}
                        <Link
                            href="/marketplace/venues"
                            className="group relative rounded-xl border-2 border-accent/40 bg-accent/5 p-5 transition-all hover:border-accent hover:shadow-md"
                        >
                            <div className="flex items-center justify-between">
                                <div className="grid h-10 w-10 place-items-center rounded-lg bg-accent text-white">
                                    <Building2 className="h-5 w-5" />
                                </div>
                                <span className="rounded-full bg-accent/20 px-2 py-0.5 text-[10px] font-semibold text-accent uppercase">
                                    Live Catalog
                                </span>
                            </div>
                            <h3 className="mt-4 text-sm font-semibold text-ink group-hover:text-accent transition-colors">
                                Venues & Spaces
                            </h3>
                            <p className="mt-1 text-xs text-ink-secondary leading-relaxed">
                                Ballrooms, plenary auditoriums, open-air lawns, and boardrooms
                                across Ghana.
                            </p>
                            <div className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-accent">
                                <span>Browse Venues</span>
                                <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-1" />
                            </div>
                        </Link>

                        {/* Catering - Future */}
                        <div className="rounded-xl border border-border bg-surface p-5 opacity-75">
                            <div className="flex items-center justify-between">
                                <div className="grid h-10 w-10 place-items-center rounded-lg bg-canvas text-ink-secondary">
                                    <Utensils className="h-5 w-5" />
                                </div>
                                <span className="rounded-full bg-border px-2 py-0.5 text-[10px] font-medium text-ink-tertiary">
                                    Coming Soon
                                </span>
                            </div>
                            <h3 className="mt-4 text-sm font-semibold text-ink">
                                Catering & Banqueting
                            </h3>
                            <p className="mt-1 text-xs text-ink-secondary leading-relaxed">
                                Full-course plated dinners, buffets, cocktail canapés, and coffee
                                break catering.
                            </p>
                        </div>

                        {/* AV & Production - Future */}
                        <div className="rounded-xl border border-border bg-surface p-5 opacity-75">
                            <div className="flex items-center justify-between">
                                <div className="grid h-10 w-10 place-items-center rounded-lg bg-canvas text-ink-secondary">
                                    <Tv className="h-5 w-5" />
                                </div>
                                <span className="rounded-full bg-border px-2 py-0.5 text-[10px] font-medium text-ink-tertiary">
                                    Coming Soon
                                </span>
                            </div>
                            <h3 className="mt-4 text-sm font-semibold text-ink">
                                Audiovisual & Tech
                            </h3>
                            <p className="mt-1 text-xs text-ink-secondary leading-relaxed">
                                LED video walls, multi-mic sound systems, livestreaming rigs, and
                                stage lighting.
                            </p>
                        </div>

                        {/* Decor & Infrastructure - Future */}
                        <div className="rounded-xl border border-border bg-surface p-5 opacity-75">
                            <div className="flex items-center justify-between">
                                <div className="grid h-10 w-10 place-items-center rounded-lg bg-canvas text-ink-secondary">
                                    <FileText className="h-5 w-5" />
                                </div>
                                <span className="rounded-full bg-border px-2 py-0.5 text-[10px] font-medium text-ink-tertiary">
                                    Coming Soon
                                </span>
                            </div>
                            <h3 className="mt-4 text-sm font-semibold text-ink">Decor & Rentals</h3>
                            <p className="mt-1 text-xs text-ink-secondary leading-relaxed">
                                High-peak canopies, Chiavari chairs, ambient drapery, and exhibition
                                booths.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {/* Featured Spaces Showcase */}
            <section className="py-16 bg-canvas">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-8">
                        <div>
                            <h2 className="text-xl font-semibold text-ink">Featured Spaces</h2>
                            <p className="text-xs text-ink-secondary">
                                Hand-picked spaces ready for your next conference or banquet.
                            </p>
                        </div>
                        <Link
                            href="/marketplace/venues"
                            className="inline-flex items-center gap-1 text-xs font-semibold text-accent hover:underline"
                        >
                            <span>View All Spaces</span>
                            <ArrowRight className="h-3.5 w-3.5" />
                        </Link>
                    </div>

                    {featuredVenues.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border p-12 text-center">
                            <Building2 className="mx-auto h-8 w-8 text-ink-tertiary" />
                            <p className="mt-2 text-xs text-ink-secondary">
                                No featured spaces listed yet.
                            </p>
                            <Link
                                href="/marketplace/venues"
                                className="mt-4 inline-flex items-center gap-1.5 rounded-md bg-accent px-4 py-2 text-xs font-semibold text-white"
                            >
                                Browse All Venues
                            </Link>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {featuredVenues.map((venue) => {
                                const maxCap = Math.max(
                                    venue.capacity_breakdown?.theater ?? 0,
                                    venue.capacity_breakdown?.banquet ?? 0,
                                    venue.capacity_breakdown?.cocktail ?? 0
                                );

                                const mediaUrl = getMediaUrl(
                                    venue.primary_media || venue.primaryMedia
                                );

                                return (
                                    <div
                                        key={venue.id}
                                        className="group flex flex-col overflow-hidden rounded-xl border border-border bg-surface transition-all hover:border-accent/40 hover:shadow-md"
                                    >
                                        <div className="relative aspect-video w-full bg-canvas flex items-center justify-center overflow-hidden border-b border-border">
                                            {mediaUrl ? (
                                                <img
                                                    src={mediaUrl}
                                                    alt={venue.title}
                                                    className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                                />
                                            ) : (
                                                <div className="flex flex-col items-center gap-1 text-ink-tertiary">
                                                    <Building2 className="h-8 w-8 stroke-1" />
                                                    <span className="text-[10px]">
                                                        Photo Preview
                                                    </span>
                                                </div>
                                            )}

                                            <div className="absolute top-2.5 left-2.5 flex items-center gap-1 rounded bg-ink/80 px-2 py-0.5 text-[10px] font-medium text-white backdrop-blur-xs">
                                                <MapPin className="h-3 w-3" />
                                                <span>{venue.shop?.city ?? 'Ghana'}</span>
                                            </div>

                                            {venue.shop?.verification_status === 'verified' && (
                                                <div className="absolute top-2.5 right-2.5 flex items-center gap-1 rounded bg-accent/90 px-2 py-0.5 text-[10px] font-semibold text-white shadow-xs">
                                                    <ShieldCheck className="h-3 w-3" />
                                                    <span>Verified</span>
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex flex-1 flex-col p-5">
                                            <div className="text-[11px] font-medium text-ink-secondary">
                                                {venue.shop?.name}
                                            </div>
                                            <h3 className="mt-1 text-sm font-semibold text-ink group-hover:text-accent transition-colors line-clamp-1">
                                                {venue.title}
                                            </h3>

                                            <div className="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-ink-secondary">
                                                {venue.capacity_breakdown?.banquet && (
                                                    <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                        {venue.capacity_breakdown.banquet} Banquet
                                                    </span>
                                                )}
                                                {venue.capacity_breakdown?.theater && (
                                                    <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                        {venue.capacity_breakdown.theater} Theater
                                                    </span>
                                                )}
                                                {venue.floor_area_sqm && (
                                                    <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                        {venue.floor_area_sqm} m²
                                                    </span>
                                                )}
                                            </div>

                                            <div className="mt-auto pt-4 border-t border-border flex items-center justify-between">
                                                <div className="text-xs font-semibold text-ink">
                                                    {formatPrice(
                                                        venue.rental_price_pesewas,
                                                        venue.pricing_model,
                                                        venue.price_visibility
                                                    )}
                                                </div>

                                                <Link
                                                    href={`/marketplace/venues/${venue.slug}`}
                                                    className="inline-flex items-center gap-1 rounded bg-accent/10 px-2.5 py-1 text-xs font-semibold text-accent hover:bg-accent hover:text-white transition-colors"
                                                >
                                                    <span>Details</span>
                                                    <ArrowRight className="h-3 w-3" />
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </section>

            {/* Invariant Trust Section */}
            <section className="border-t border-border bg-surface py-16">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="text-center max-w-2xl mx-auto mb-12">
                        <h2 className="text-2xl font-semibold text-ink">
                            Why book through MiConvener Marketplace?
                        </h2>
                        <p className="mt-2 text-xs text-ink-secondary">
                            Designed specifically for professional event organizers who cannot
                            afford surprises on event day.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-8 md:grid-cols-3">
                        <div className="rounded-xl border border-border bg-canvas p-6 space-y-3">
                            <div className="grid h-10 w-10 place-items-center rounded-lg bg-accent/10 text-accent">
                                <CheckCircle2 className="h-5 w-5" />
                            </div>
                            <h3 className="text-sm font-semibold text-ink">
                                Included vs. Excluded Transparency
                            </h3>
                            <p className="text-xs text-ink-secondary leading-relaxed">
                                Every venue publishes an exact checklist. Know upfront if standby
                                generators, central AC, chairs, or AV gear are included in the base
                                rate or require add-on fees.
                            </p>
                        </div>

                        <div className="rounded-xl border border-border bg-canvas p-6 space-y-3">
                            <div className="grid h-10 w-10 place-items-center rounded-lg bg-accent/10 text-accent">
                                <ShieldCheck className="h-5 w-5" />
                            </div>
                            <h3 className="text-sm font-semibold text-ink">
                                Approval-First Bookings
                            </h3>
                            <p className="text-xs text-ink-secondary leading-relaxed">
                                Avoid double-bookings. Dates and bespoke configurations are
                                confirmed directly with the venue management before any financial
                                commitments or payments unlock.
                            </p>
                        </div>

                        <div className="rounded-xl border border-border bg-canvas p-6 space-y-3">
                            <div className="grid h-10 w-10 place-items-center rounded-lg bg-accent/10 text-accent">
                                <Building2 className="h-5 w-5" />
                            </div>
                            <h3 className="text-sm font-semibold text-ink">
                                Integrated 8-Pillars Coordination
                            </h3>
                            <p className="text-xs text-ink-secondary leading-relaxed">
                                Confirmed bookings seamlessly bridge into your event operations
                                dashboard (Pillar 6: Venue & Logistics) with direct real-time
                                communication threads.
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </MarketplaceLayout>
    );
}
