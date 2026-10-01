import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import {
    Building2,
    MapPin,
    ShieldCheck,
    Phone,
    Mail,
    ArrowRight,
    Users,
    Maximize2,
    CheckCircle2,
    Utensils,
    Tv,
} from 'lucide-react';

export default function StorefrontShow({ shop, spaces = [] }) {
    const formatPrice = (pesewas, pricingModel, priceVisibility) => {
        if (priceVisibility === 'on_request') {
            return 'Custom Quote on Request';
        }
        const ghs = (pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        });
        const modelLabel = {
            per_day: '/ day',
            per_half_day: '/ half day',
            per_hour: '/ hour',
            flat_rate: 'flat rate',
        }[pricingModel] ?? '';
        return `GHS ${ghs} ${modelLabel}`;
    };

    return (
        <MarketplaceLayout>
            <Head title={`${shop.name} — Venue Host Profile | MiConvener Marketplace`} />

            {/* Merchant Banner & Header */}
            <div className="relative border-b border-border bg-gradient-to-r from-surface to-canvas">
                <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <div className="flex flex-col sm:flex-row items-start sm:items-center gap-6">
                        <div className="grid h-20 w-20 shrink-0 place-items-center rounded-2xl bg-accent text-white font-bold text-2xl shadow-md border-2 border-surface">
                            {shop.name.slice(0, 2).toUpperCase()}
                        </div>

                        <div className="space-y-2 flex-1">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="text-2xl sm:text-3xl font-bold text-ink">{shop.name}</h1>
                                {shop.verification_status === 'verified' && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent">
                                        <ShieldCheck className="h-3.5 w-3.5" />
                                        <span>Verified Venue Host</span>
                                    </span>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-4 text-xs text-ink-secondary">
                                <div className="flex items-center gap-1">
                                    <MapPin className="h-3.5 w-3.5 text-accent" />
                                    <span>{shop.address}, {shop.city}, {shop.region}</span>
                                </div>
                                {shop.phone && (
                                    <div className="flex items-center gap-1">
                                        <Phone className="h-3.5 w-3.5 text-accent" />
                                        <span>{shop.phone}</span>
                                    </div>
                                )}
                                {shop.email && (
                                    <div className="flex items-center gap-1">
                                        <Mail className="h-3.5 w-3.5 text-accent" />
                                        <span>{shop.email}</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8 space-y-12">
                {/* About Section */}
                {shop.description && (
                    <div className="rounded-xl border border-border bg-surface p-6 space-y-3">
                        <h2 className="text-sm font-semibold text-ink uppercase tracking-wider">About {shop.name}</h2>
                        <p className="text-xs text-ink-secondary leading-relaxed whitespace-pre-line">
                            {shop.description}
                        </p>
                    </div>
                )}

                {/* All Spaces Listed by this Host */}
                <div className="space-y-6">
                    <div>
                        <h2 className="text-xl font-semibold text-ink">Spaces & Event Halls</h2>
                        <p className="text-xs text-ink-secondary">
                            Explore all conference halls, ballrooms, and outdoor spaces hosted by {shop.name}.
                        </p>
                    </div>

                    {spaces.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-border bg-surface p-12 text-center text-xs text-ink-secondary">
                            <Building2 className="mx-auto h-8 w-8 text-ink-tertiary mb-2" />
                            <span>No spaces currently published by this host.</span>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {spaces.map((space) => {
                                const includedAmenities = (space.amenities || [])
                                    .filter((a) => a.is_included)
                                    .slice(0, 3);

                                return (
                                    <div
                                        key={space.id}
                                        className="group flex flex-col overflow-hidden rounded-xl border border-border bg-surface transition-all hover:border-accent hover:shadow-md"
                                    >
                                        <div className="aspect-video w-full bg-canvas flex items-center justify-center overflow-hidden border-b border-border">
                                            {space.primary_media?.url ? (
                                                <img
                                                    src={space.primary_media.url}
                                                    alt=""
                                                    className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                                />
                                            ) : (
                                                <Building2 className="h-8 w-8 text-ink-tertiary" />
                                            )}
                                        </div>

                                        <div className="flex flex-1 flex-col p-5">
                                            <h3 className="text-sm font-semibold text-ink group-hover:text-accent transition-colors line-clamp-1">
                                                <Link href={`/marketplace/venues/${space.slug}`}>
                                                    {space.title}
                                                </Link>
                                            </h3>

                                            <div className="mt-2.5 flex flex-wrap items-center gap-1.5 text-[10.5px] text-ink-secondary">
                                                {space.capacity_breakdown?.banquet && (
                                                    <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                        {space.capacity_breakdown.banquet} Banquet
                                                    </span>
                                                )}
                                                {space.capacity_breakdown?.theater && (
                                                    <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                        {space.capacity_breakdown.theater} Theater
                                                    </span>
                                                )}
                                                {space.floor_area_sqm && (
                                                    <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                        {space.floor_area_sqm} m²
                                                    </span>
                                                )}
                                            </div>

                                            {includedAmenities.length > 0 && (
                                                <div className="mt-3 flex flex-wrap gap-1 text-[10px] text-emerald-600 dark:text-emerald-400">
                                                    {includedAmenities.map((a) => (
                                                        <span
                                                            key={a.id}
                                                            className="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-1.5 py-0.5"
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
                                                        space.rental_price_pesewas,
                                                        space.pricing_model,
                                                        space.price_visibility
                                                    )}
                                                </div>

                                                <Link
                                                    href={`/marketplace/venues/${space.slug}`}
                                                    className="inline-flex items-center gap-1 rounded bg-accent/10 px-2.5 py-1 text-xs font-semibold text-accent hover:bg-accent hover:text-white transition-colors"
                                                >
                                                    <span>View Specs</span>
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

                {/* Cross-Sell & Hospitality Services Bridge */}
                <div className="rounded-xl border border-border bg-canvas p-6 space-y-4">
                    <h3 className="text-sm font-semibold text-ink">Other Services Offered by {shop.name}</h3>
                    <p className="text-xs text-ink-secondary">
                        When booking a space at {shop.name}, additional in-house event services can be packaged into your custom quotation.
                    </p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div className="rounded-lg border border-border bg-surface p-4 flex items-center gap-3">
                            <div className="grid h-8 w-8 place-items-center rounded bg-accent/10 text-accent">
                                <Utensils className="h-4 w-4" />
                            </div>
                            <div>
                                <div className="text-xs font-semibold text-ink">In-House Catering & Banqueting</div>
                                <div className="text-[11px] text-ink-secondary">Buffets, multi-course dining, and cocktail finger foods</div>
                            </div>
                        </div>

                        <div className="rounded-lg border border-border bg-surface p-4 flex items-center gap-3">
                            <div className="grid h-8 w-8 place-items-center rounded bg-accent/10 text-accent">
                                <Tv className="h-4 w-4" />
                            </div>
                            <div>
                                <div className="text-xs font-semibold text-ink">In-House AV & Lighting Rig</div>
                                <div className="text-[11px] text-ink-secondary">Audio systems, projectors, and stage lighting packages</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </MarketplaceLayout>
    );
}
