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
    SlidersHorizontal,
    X,
    RotateCcw,
    Zap,
    ChevronDown,
} from 'lucide-react';

export default function VenuesIndex({ venues, amenities = [], filters = {} }) {
    const [q, setQ] = useState(filters.q || '');
    const [city, setCity] = useState(filters.city || '');
    const [minCapacity, setMinCapacity] = useState(filters.min_capacity || '');
    const [capacityStyle, setCapacityStyle] = useState(filters.capacity_style || 'banquet');
    const [minPrice, setMinPrice] = useState(filters.min_price || '');
    const [maxPrice, setMaxPrice] = useState(filters.max_price || '');
    const [priceVisibility, setPriceVisibility] = useState(filters.price_visibility || '');
    const [selectedAmenities, setSelectedAmenities] = useState(filters.amenities || []);
    const [sort, setSort] = useState(filters.sort || 'recommended');
    const [filterDrawerOpen, setFilterDrawerOpen] = useState(false);

    const applyFilters = (overrides = {}) => {
        const query = {
            q: q || undefined,
            city: city || undefined,
            min_capacity: minCapacity || undefined,
            capacity_style: minCapacity ? capacityStyle : undefined,
            min_price: minPrice || undefined,
            max_price: maxPrice || undefined,
            price_visibility: priceVisibility || undefined,
            amenities: selectedAmenities.length > 0 ? selectedAmenities : undefined,
            sort: sort !== 'recommended' ? sort : undefined,
            ...overrides,
        };

        router.get('/marketplace/venues', query, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleAmenityToggle = (slug) => {
        const updated = selectedAmenities.includes(slug)
            ? selectedAmenities.filter((s) => s !== slug)
            : [...selectedAmenities, slug];
        setSelectedAmenities(updated);
        applyFilters({ amenities: updated.length > 0 ? updated : undefined });
    };

    const clearAll = () => {
        setQ('');
        setCity('');
        setMinCapacity('');
        setCapacityStyle('banquet');
        setMinPrice('');
        setMaxPrice('');
        setPriceVisibility('');
        setSelectedAmenities([]);
        setSort('recommended');
        router.get('/marketplace/venues');
    };

    const formatPrice = (pesewas, pricingModel, priceVisibility) => {
        if (priceVisibility === 'on_request') {
            return 'Price on request';
        }
        const ghs = (pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        });
        const modelLabel = {
            per_day: '/ day',
            per_half_day: '/ half day',
            per_hour: '/ hr',
            flat_rate: 'flat rate',
        }[pricingModel] ?? '';
        return `GHS ${ghs} ${modelLabel}`;
    };

    const hasActiveFilters = Boolean(
        q || city || minCapacity || minPrice || maxPrice || priceVisibility || selectedAmenities.length > 0
    );

    return (
        <MarketplaceLayout>
            <Head title="Venues & Spaces Directory — MiConvener Marketplace" />

            {/* Top Search & Filter Bar */}
            <div className="border-b border-border bg-surface px-4 py-4 sm:px-6 lg:px-8">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-ink">Venues & Event Spaces</h1>
                        <p className="text-xs text-ink-secondary">
                            {venues.total} {venues.total === 1 ? 'space' : 'spaces'} available across Ghana
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={() => setFilterDrawerOpen(!filterDrawerOpen)}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-canvas px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface md:hidden"
                        >
                            <SlidersHorizontal className="h-4 w-4" />
                            <span>Filters {selectedAmenities.length > 0 && `(${selectedAmenities.length})`}</span>
                        </button>

                        <div className="relative">
                            <select
                                value={sort}
                                onChange={(e) => {
                                    setSort(e.target.value);
                                    applyFilters({ sort: e.target.value });
                                }}
                                className="rounded-lg border border-border bg-canvas py-1.5 pl-3 pr-8 text-xs text-ink focus:border-accent focus:ring-1 focus:ring-accent"
                            >
                                <option value="recommended">Sort: Recommended (Verified First)</option>
                                <option value="price_asc">Price: Low to High</option>
                                <option value="price_desc">Price: High to Low</option>
                                <option value="capacity_desc">Highest Capacity</option>
                                <option value="newest">Recently Listed</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <div className="grid grid-cols-1 gap-8 md:grid-cols-4">
                    {/* Left Filters Sidebar (Desktop) */}
                    <div className={`space-y-6 md:block ${filterDrawerOpen ? 'block' : 'hidden'}`}>
                        <div className="rounded-xl border border-border bg-surface p-5 space-y-5">
                            <div className="flex items-center justify-between border-b border-border pb-3">
                                <span className="text-xs font-semibold text-ink uppercase tracking-wider">Refine Search</span>
                                {hasActiveFilters && (
                                    <button
                                        type="button"
                                        onClick={clearAll}
                                        className="inline-flex items-center gap-1 text-[11px] text-accent hover:underline"
                                    >
                                        <RotateCcw className="h-3 w-3" />
                                        <span>Reset</span>
                                    </button>
                                )}
                            </div>

                            {/* Keyword */}
                            <div className="space-y-1.5">
                                <label className="text-[11px] font-medium text-ink-secondary">Search</label>
                                <div className="relative">
                                    <Search className="absolute left-3 top-2.5 h-3.5 w-3.5 text-ink-tertiary" />
                                    <input
                                        type="text"
                                        placeholder="Space or venue name..."
                                        value={q}
                                        onChange={(e) => setQ(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                        className="w-full rounded-md border border-border bg-canvas py-1.5 pl-8 pr-3 text-xs text-ink placeholder:text-ink-tertiary"
                                    />
                                </div>
                            </div>

                            {/* City */}
                            <div className="space-y-1.5">
                                <label className="text-[11px] font-medium text-ink-secondary">City / Region</label>
                                <select
                                    value={city}
                                    onChange={(e) => {
                                        setCity(e.target.value);
                                        applyFilters({ city: e.target.value || undefined });
                                    }}
                                    className="w-full rounded-md border border-border bg-canvas py-1.5 px-3 text-xs text-ink"
                                >
                                    <option value="">All Cities in Ghana</option>
                                    <option value="Accra">Accra (Greater Accra)</option>
                                    <option value="Kumasi">Kumasi (Ashanti)</option>
                                    <option value="Takoradi">Takoradi (Western)</option>
                                    <option value="Cape Coast">Cape Coast (Central)</option>
                                    <option value="Tamale">Tamale (Northern)</option>
                                    <option value="Ho">Ho (Volta)</option>
                                    <option value="Koforidua">Koforidua (Eastern)</option>
                                </select>
                            </div>

                            {/* Capacity Style & Minimum */}
                            <div className="space-y-2 border-t border-border pt-4">
                                <label className="text-[11px] font-medium text-ink-secondary">Capacity Layout</label>
                                <div className="grid grid-cols-2 gap-1 text-[11px]">
                                    {[
                                        { id: 'banquet', label: 'Banquet' },
                                        { id: 'theater', label: 'Theater' },
                                        { id: 'cocktail', label: 'Cocktail' },
                                        { id: 'classroom', label: 'Classroom' },
                                    ].map((style) => (
                                        <button
                                            key={style.id}
                                            type="button"
                                            onClick={() => {
                                                setCapacityStyle(style.id);
                                                if (minCapacity) applyFilters({ capacity_style: style.id });
                                            }}
                                            className={`rounded border px-2 py-1 text-center transition-colors ${
                                                capacityStyle === style.id
                                                    ? 'border-accent bg-accent/10 font-semibold text-accent'
                                                    : 'border-border bg-canvas text-ink-secondary hover:text-ink'
                                            }`}
                                        >
                                            {style.label}
                                        </button>
                                    ))}
                                </div>

                                <div className="mt-2">
                                    <input
                                        type="number"
                                        placeholder="Min. guests (e.g. 200)"
                                        value={minCapacity}
                                        onChange={(e) => setMinCapacity(e.target.value)}
                                        onBlur={() => applyFilters()}
                                        onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                        className="w-full rounded-md border border-border bg-canvas py-1.5 px-3 text-xs text-ink"
                                    />
                                </div>
                            </div>

                            {/* Price Visibility */}
                            <div className="space-y-1.5 border-t border-border pt-4">
                                <label className="text-[11px] font-medium text-ink-secondary">Pricing Transparency</label>
                                <div className="space-y-1 text-xs">
                                    <label className="flex items-center gap-2 cursor-pointer text-ink-secondary hover:text-ink">
                                        <input
                                            type="radio"
                                            name="price_visibility"
                                            checked={priceVisibility === ''}
                                            onChange={() => {
                                                setPriceVisibility('');
                                                applyFilters({ price_visibility: undefined });
                                            }}
                                            className="text-accent"
                                        />
                                        <span>All listings</span>
                                    </label>
                                    <label className="flex items-center gap-2 cursor-pointer text-ink-secondary hover:text-ink">
                                        <input
                                            type="radio"
                                            name="price_visibility"
                                            checked={priceVisibility === 'public'}
                                            onChange={() => {
                                                setPriceVisibility('public');
                                                applyFilters({ price_visibility: 'public' });
                                            }}
                                            className="text-accent"
                                        />
                                        <span>Upfront Public Rates only</span>
                                    </label>
                                    <label className="flex items-center gap-2 cursor-pointer text-ink-secondary hover:text-ink">
                                        <input
                                            type="radio"
                                            name="price_visibility"
                                            checked={priceVisibility === 'on_request'}
                                            onChange={() => {
                                                setPriceVisibility('on_request');
                                                applyFilters({ price_visibility: 'on_request' });
                                            }}
                                            className="text-accent"
                                        />
                                        <span>Custom Quote on Request</span>
                                    </label>
                                </div>
                            </div>

                            {/* Required Guaranteed Amenities */}
                            {amenities.length > 0 && (
                                <div className="space-y-2 border-t border-border pt-4">
                                    <label className="text-[11px] font-medium text-ink-secondary">
                                        Guaranteed Inclusions
                                    </label>
                                    <div className="max-h-56 overflow-y-auto space-y-1.5 text-xs pr-1">
                                        {amenities.map((amenity) => (
                                            <label
                                                key={amenity.id}
                                                className="flex items-center gap-2 cursor-pointer text-ink-secondary hover:text-ink select-none"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={selectedAmenities.includes(amenity.slug)}
                                                    onChange={() => handleAmenityToggle(amenity.slug)}
                                                    className="rounded border-border text-accent focus:ring-accent"
                                                />
                                                <span className="text-[11px]">{amenity.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={() => applyFilters()}
                                className="w-full rounded-lg bg-accent py-2 text-xs font-semibold text-white hover:bg-accent/90 transition-colors"
                            >
                                Apply Filters
                            </button>
                        </div>
                    </div>

                    {/* Right Results Grid (3 columns on desktop) */}
                    <div className="md:col-span-3 space-y-6">
                        {venues.data.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-border bg-surface p-12 text-center">
                                <Building2 className="mx-auto h-10 w-10 text-ink-tertiary" />
                                <h3 className="mt-3 text-sm font-semibold text-ink">No matching spaces found</h3>
                                <p className="mt-1 text-xs text-ink-secondary max-w-sm mx-auto">
                                    Try adjusting your search criteria, clearing some amenity filters, or broadening the capacity range.
                                </p>
                                <button
                                    type="button"
                                    onClick={clearAll}
                                    className="mt-4 inline-flex items-center gap-1.5 rounded-md bg-accent px-3 py-1.5 text-xs font-semibold text-white"
                                >
                                    <RotateCcw className="h-3.5 w-3.5" />
                                    <span>Reset All Filters</span>
                                </button>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {venues.data.map((venue) => {
                                    // Extract included amenities for preview chips
                                    const includedAmenities = (venue.amenities || [])
                                        .filter((a) => a.is_included)
                                        .slice(0, 2);

                                    return (
                                        <div
                                            key={venue.id}
                                            className="group flex flex-col overflow-hidden rounded-xl border border-border bg-surface transition-all hover:border-accent/40 hover:shadow-md"
                                        >
                                            <div className="relative aspect-video w-full bg-canvas flex items-center justify-center overflow-hidden border-b border-border">
                                                {venue.primary_media?.url ? (
                                                    <img
                                                        src={venue.primary_media.url}
                                                        alt={venue.title}
                                                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                                    />
                                                ) : (
                                                    <div className="flex flex-col items-center gap-1 text-ink-tertiary">
                                                        <Building2 className="h-8 w-8 stroke-1" />
                                                        <span className="text-[10px]">Photo Preview</span>
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
                                                <Link
                                                    href={`/marketplace/${venue.shop?.slug}`}
                                                    className="text-[11px] font-medium text-ink-secondary hover:text-accent truncate"
                                                >
                                                    {venue.shop?.name}
                                                </Link>
                                                <h3 className="mt-1 text-sm font-semibold text-ink group-hover:text-accent transition-colors line-clamp-1">
                                                    <Link href={`/marketplace/venues/${venue.slug}`}>
                                                        {venue.title}
                                                    </Link>
                                                </h3>

                                                <div className="mt-3 flex flex-wrap items-center gap-1.5 text-[10.5px] text-ink-secondary">
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

                                                {/* Inclusions pill preview */}
                                                {includedAmenities.length > 0 && (
                                                    <div className="mt-2.5 flex flex-wrap gap-1 text-[10px] text-accent">
                                                        {includedAmenities.map((a) => (
                                                            <span
                                                                key={a.id}
                                                                className="inline-flex items-center gap-1 rounded bg-accent/5 border border-accent/15 px-1.5 py-0.5"
                                                            >
                                                                <CheckCircle2 className="h-2.5 w-2.5" />
                                                                <span>{a.amenity?.name}</span>
                                                            </span>
                                                        ))}
                                                    </div>
                                                )}

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
                                                        <span>View Space</span>
                                                        <ArrowRight className="h-3 w-3" />
                                                    </Link>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {/* Pagination */}
                        {venues.links && venues.links.length > 3 && (
                            <div className="flex items-center justify-center gap-1 pt-6 border-t border-border">
                                {venues.links.map((link, idx) => (
                                    <Link
                                        key={idx}
                                        href={link.url ?? '#'}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                        className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
                                            link.active
                                                ? 'bg-accent text-white'
                                                : link.url
                                                ? 'border border-border bg-surface text-ink hover:bg-surface-hover'
                                                : 'text-ink-tertiary pointer-events-none'
                                        }`}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </MarketplaceLayout>
    );
}
