import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import {
    Building2,
    MapPin,
    Users,
    ShieldCheck,
    CheckCircle2,
    XCircle,
    ArrowRight,
    Phone,
    Mail,
    Maximize2,
    ArrowUpFromLine,
    Calendar,
    AlertCircle,
    Info,
    Share2,
} from 'lucide-react';

export default function VenueShow({ venue, otherSpaces = [] }) {
    const [selectedMediaIdx, setSelectedMediaIdx] = useState(0);
    const [inquiryModalOpen, setInquiryModalOpen] = useState(false);

    const getMediaUrl = (media) => {
        if (!media) return null;
        if (typeof media === 'string') return media;
        return media.url || media.file_path || null;
    };

    const rawMediaList =
        venue.media && venue.media.length > 0
            ? venue.media
            : venue.primary_media
              ? [venue.primary_media]
              : venue.primaryMedia
                ? [venue.primaryMedia]
                : [];

    const mediaList = rawMediaList
        .map((item) => ({
            ...item,
            url: getMediaUrl(item),
        }))
        .filter((item) => !!item.url);

    const includedAmenities = (venue.amenities || []).filter((a) => a.is_included);
    const excludedAmenities = (venue.amenities || []).filter((a) => !a.is_included);

    const formatPrice = (pesewas, pricingModel, priceVisibility) => {
        if (priceVisibility === 'on_request') {
            return 'Custom Quote on Request';
        }
        const ghs = (pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        });
        const modelLabel =
            {
                per_day: '/ day',
                per_half_day: '/ half day',
                per_hour: '/ hour',
                flat_rate: 'flat rate',
            }[pricingModel] ?? '';
        return `GHS ${ghs} ${modelLabel}`;
    };

    const formatCurrency = (pesewas) => {
        if (!pesewas) return null;
        return `GHS ${(pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        })}`;
    };

    return (
        <MarketplaceLayout>
            <Head title={`${venue.title} — ${venue.shop?.name} | MiConvener Marketplace`} />

            {/* Breadcrumb Bar */}
            <div className="border-b border-border bg-surface px-4 py-3 sm:px-6 lg:px-8 text-xs text-ink-secondary">
                <div className="mx-auto flex max-w-7xl items-center gap-2">
                    <Link href="/marketplace" className="hover:text-ink">
                        Marketplace
                    </Link>
                    <span>/</span>
                    <Link href="/marketplace/venues" className="hover:text-ink">
                        Venues
                    </Link>
                    <span>/</span>
                    <Link href={`/marketplace/${venue.shop?.slug}`} className="hover:text-ink">
                        {venue.shop?.name}
                    </Link>
                    <span>/</span>
                    <span className="text-ink font-medium truncate">{venue.title}</span>
                </div>
            </div>

            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                {/* Space Title & Location Header */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-border pb-6">
                    <div>
                        <div className="flex items-center gap-2 text-xs font-medium text-ink-secondary mb-1">
                            <Link
                                href={`/marketplace/${venue.shop?.slug}`}
                                className="hover:text-accent font-semibold text-ink"
                            >
                                {venue.shop?.name}
                            </Link>
                            {venue.shop?.verification_status === 'verified' && (
                                <span className="inline-flex items-center gap-1 rounded bg-accent/10 px-2 py-0.5 text-[10px] font-semibold text-accent">
                                    <ShieldCheck className="h-3 w-3" />
                                    <span>Verified Host</span>
                                </span>
                            )}
                        </div>

                        <h1 className="text-2xl sm:text-3xl font-semibold text-ink tracking-tight">
                            {venue.title}
                        </h1>

                        <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-ink-secondary">
                            <div className="flex items-center gap-1">
                                <MapPin className="h-3.5 w-3.5 text-accent" />
                                <span>
                                    {venue.shop?.address}, {venue.shop?.city}, {venue.shop?.region}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                if (navigator.share) {
                                    navigator.share({
                                        title: venue.title,
                                        url: window.location.href,
                                    });
                                } else {
                                    navigator.clipboard.writeText(window.location.href);
                                    alert('Link copied to clipboard!');
                                }
                            }}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-hover"
                        >
                            <Share2 className="h-3.5 w-3.5" />
                            <span>Share</span>
                        </button>
                    </div>
                </div>

                {/* Media Gallery Grid */}
                <div className="mt-6 space-y-3">
                    <div className="relative aspect-video sm:aspect-[21/9] w-full overflow-hidden rounded-xl border border-border bg-canvas flex items-center justify-center">
                        {mediaList.length > 0 ? (
                            <img
                                src={mediaList[selectedMediaIdx]?.url}
                                alt={venue.title}
                                className="h-full w-full object-cover"
                            />
                        ) : (
                            <div className="flex flex-col items-center gap-2 text-ink-tertiary">
                                <Building2 className="h-12 w-12 stroke-1" />
                                <span className="text-xs">No photos uploaded for this space</span>
                            </div>
                        )}
                    </div>

                    {mediaList.length > 1 && (
                        <div className="flex gap-2 overflow-x-auto pb-2">
                            {mediaList.map((item, idx) => (
                                <button
                                    key={item.id ?? idx}
                                    type="button"
                                    onClick={() => setSelectedMediaIdx(idx)}
                                    className={`relative aspect-video h-16 shrink-0 overflow-hidden rounded-md border-2 transition-all ${
                                        selectedMediaIdx === idx
                                            ? 'border-accent shadow-xs'
                                            : 'border-border opacity-70 hover:opacity-100'
                                    }`}
                                >
                                    <img
                                        src={item.url}
                                        alt=""
                                        className="h-full w-full object-cover"
                                    />
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Main Two-Column Layout */}
                <div className="mt-8 grid grid-cols-1 gap-10 lg:grid-cols-3">
                    {/* Left 2 Columns: Specs, Capacities, Inclusions/Exclusions, Bio */}
                    <div className="lg:col-span-2 space-y-10">
                        {/* Quick Spec Metrics */}
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 rounded-xl border border-border bg-surface p-4 text-center">
                            <div className="space-y-1">
                                <div className="text-[11px] text-ink-secondary">Floor Area</div>
                                <div className="flex items-center justify-center gap-1 text-sm font-semibold text-ink">
                                    <Maximize2 className="h-4 w-4 text-accent" />
                                    <span>
                                        {venue.floor_area_sqm
                                            ? `${venue.floor_area_sqm} m²`
                                            : 'N/A'}
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-1 border-l border-border">
                                <div className="text-[11px] text-ink-secondary">
                                    Ceiling Clearance
                                </div>
                                <div className="flex items-center justify-center gap-1 text-sm font-semibold text-ink">
                                    <ArrowUpFromLine className="h-4 w-4 text-accent" />
                                    <span>
                                        {venue.ceiling_height_meters
                                            ? `${venue.ceiling_height_meters} m`
                                            : 'N/A'}
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-1 border-l border-border">
                                <div className="text-[11px] text-ink-secondary">
                                    Banquet Capacity
                                </div>
                                <div className="flex items-center justify-center gap-1 text-sm font-semibold text-ink">
                                    <Users className="h-4 w-4 text-accent" />
                                    <span>
                                        {venue.capacity_breakdown?.banquet
                                            ? `${venue.capacity_breakdown.banquet} seats`
                                            : 'N/A'}
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-1 border-l border-border">
                                <div className="text-[11px] text-ink-secondary">
                                    Theater Plenary
                                </div>
                                <div className="flex items-center justify-center gap-1 text-sm font-semibold text-ink">
                                    <Users className="h-4 w-4 text-accent" />
                                    <span>
                                        {venue.capacity_breakdown?.theater
                                            ? `${venue.capacity_breakdown.theater} seats`
                                            : 'N/A'}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Description */}
                        {venue.description && (
                            <div className="space-y-3">
                                <h2 className="text-base font-semibold text-ink">
                                    About This Space
                                </h2>
                                <p className="text-xs text-ink-secondary leading-relaxed whitespace-pre-line">
                                    {venue.description}
                                </p>
                            </div>
                        )}

                        {/* Seating Configurations Matrix */}
                        <div className="space-y-4">
                            <h2 className="text-base font-semibold text-ink">
                                Seating Configurations
                            </h2>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {[
                                    {
                                        key: 'banquet',
                                        label: 'Banquet / Gala',
                                        desc: 'Round banquet tables',
                                    },
                                    {
                                        key: 'theater',
                                        label: 'Theater / Plenary',
                                        desc: 'Row seating with aisle',
                                    },
                                    {
                                        key: 'cocktail',
                                        label: 'Cocktail / Reception',
                                        desc: 'Standing with high tops',
                                    },
                                    {
                                        key: 'classroom',
                                        label: 'Classroom / Seminar',
                                        desc: 'Tables facing presenter',
                                    },
                                ].map((style) => (
                                    <div
                                        key={style.key}
                                        className="rounded-xl border border-border bg-surface p-4"
                                    >
                                        <div className="text-lg font-bold text-ink">
                                            {venue.capacity_breakdown?.[style.key]
                                                ? venue.capacity_breakdown[
                                                      style.key
                                                  ].toLocaleString()
                                                : '—'}
                                        </div>
                                        <div className="mt-1 text-xs font-semibold text-ink">
                                            {style.label}
                                        </div>
                                        <div className="text-[10px] text-ink-tertiary">
                                            {style.desc}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* THE CRITICAL: Included vs. Excluded Amenities Grid */}
                        <div className="space-y-4">
                            <div>
                                <h2 className="text-base font-semibold text-ink">
                                    Amenities & Infrastructure Checklist
                                </h2>
                                <p className="text-xs text-ink-secondary">
                                    Full transparency on what the base rental fee covers vs items
                                    requiring in-house add-on charges or external vendors.
                                </p>
                            </div>

                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                {/* Included Column */}
                                <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-5 space-y-4">
                                    <div className="flex items-center gap-2 border-b border-emerald-500/20 pb-3 text-emerald-600 dark:text-emerald-400">
                                        <CheckCircle2 className="h-5 w-5" />
                                        <h3 className="text-sm font-semibold">
                                            Included in Base Rental
                                        </h3>
                                        <span className="ml-auto rounded-full bg-emerald-500/20 px-2 py-0.5 text-[10px] font-bold">
                                            {includedAmenities.length}
                                        </span>
                                    </div>

                                    {includedAmenities.length === 0 ? (
                                        <p className="text-xs text-ink-tertiary italic">
                                            No specific inclusions declared.
                                        </p>
                                    ) : (
                                        <ul className="space-y-3">
                                            {includedAmenities.map((item) => (
                                                <li key={item.id} className="text-xs">
                                                    <div className="flex items-start gap-2">
                                                        <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500 shrink-0 mt-0.5" />
                                                        <div>
                                                            <span className="font-medium text-ink">
                                                                {item.amenity?.name}
                                                            </span>
                                                            {item.notes && (
                                                                <p className="text-[11px] text-ink-secondary mt-0.5">
                                                                    {item.notes}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                {/* Excluded Column */}
                                <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-5 space-y-4">
                                    <div className="flex items-center gap-2 border-b border-amber-500/20 pb-3 text-amber-600 dark:text-amber-400">
                                        <AlertCircle className="h-5 w-5" />
                                        <h3 className="text-sm font-semibold">
                                            Excluded / Add-on Required
                                        </h3>
                                        <span className="ml-auto rounded-full bg-amber-500/20 px-2 py-0.5 text-[10px] font-bold">
                                            {excludedAmenities.length}
                                        </span>
                                    </div>

                                    {excludedAmenities.length === 0 ? (
                                        <p className="text-xs text-ink-tertiary italic">
                                            All listed venue amenities are included.
                                        </p>
                                    ) : (
                                        <ul className="space-y-3">
                                            {excludedAmenities.map((item) => (
                                                <li key={item.id} className="text-xs">
                                                    <div className="flex items-start gap-2">
                                                        <XCircle className="h-3.5 w-3.5 text-amber-500 shrink-0 mt-0.5" />
                                                        <div>
                                                            <span className="font-medium text-ink">
                                                                {item.amenity?.name}
                                                            </span>
                                                            {item.notes ? (
                                                                <p className="text-[11px] text-amber-700 dark:text-amber-300 font-medium mt-0.5">
                                                                    {item.notes}
                                                                </p>
                                                            ) : (
                                                                <p className="text-[11px] text-ink-tertiary mt-0.5">
                                                                    Must be rented or brought in by
                                                                    organizer
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Rules and Policies */}
                        {venue.rules_and_policies &&
                            Object.keys(venue.rules_and_policies).length > 0 && (
                                <div className="space-y-3">
                                    <h2 className="text-base font-semibold text-ink">
                                        Venue Rules & Policies
                                    </h2>
                                    <div className="rounded-xl border border-border bg-surface p-5 space-y-2 text-xs text-ink-secondary">
                                        {Object.entries(venue.rules_and_policies).map(
                                            ([key, val]) => (
                                                <div
                                                    key={key}
                                                    className="flex items-center justify-between border-b border-border/50 py-1.5 last:border-0"
                                                >
                                                    <span className="capitalize font-medium text-ink">
                                                        {key.replace('_', ' ')}
                                                    </span>
                                                    <span>
                                                        {typeof val === 'boolean'
                                                            ? val
                                                                ? 'Allowed'
                                                                : 'Not Allowed'
                                                            : String(val)}
                                                    </span>
                                                </div>
                                            )
                                        )}
                                    </div>
                                </div>
                            )}
                    </div>

                    {/* Right Column: Pricing & Booking Request Box */}
                    <div className="space-y-6">
                        <div className="sticky top-20 rounded-xl border-2 border-accent/30 bg-surface p-6 shadow-lg space-y-6">
                            <div>
                                <div className="text-[11px] font-medium text-ink-secondary uppercase tracking-wider">
                                    Rental Rate
                                </div>
                                <div className="mt-1 text-2xl font-bold text-ink">
                                    {formatPrice(
                                        venue.rental_price_pesewas,
                                        venue.pricing_model,
                                        venue.price_visibility
                                    )}
                                </div>
                                {venue.price_visibility === 'on_request' && (
                                    <p className="text-[11px] text-ink-secondary mt-1">
                                        Rates depend on bespoke guest counts and setup requirements.
                                    </p>
                                )}
                            </div>

                            {venue.security_deposit_pesewas && (
                                <div className="rounded-lg bg-canvas p-3 border border-border text-xs flex items-center justify-between">
                                    <span className="text-ink-secondary">
                                        Refundable Security Deposit:
                                    </span>
                                    <span className="font-semibold text-ink">
                                        {formatCurrency(venue.security_deposit_pesewas)}
                                    </span>
                                </div>
                            )}

                            <div className="space-y-3 border-t border-border pt-4">
                                <div className="flex items-center gap-2 text-xs text-emerald-600 dark:text-emerald-400">
                                    <ShieldCheck className="h-4 w-4 shrink-0" />
                                    <span>
                                        Approval-First: No upfront charges until dates confirmed
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => setInquiryModalOpen(true)}
                                    className="w-full rounded-lg bg-accent py-3 text-xs font-semibold text-white shadow-xs hover:bg-accent/90 transition-colors"
                                >
                                    Request to Book / Inquire
                                </button>
                            </div>

                            {/* Host Contact Snapshot */}
                            <div className="border-t border-border pt-5 space-y-3 text-xs">
                                <div className="font-semibold text-ink">
                                    Hosted by {venue.shop?.name}
                                </div>
                                {venue.shop?.phone && (
                                    <div className="flex items-center gap-2 text-ink-secondary">
                                        <Phone className="h-3.5 w-3.5 text-accent" />
                                        <span>{venue.shop.phone}</span>
                                    </div>
                                )}
                                {venue.shop?.email && (
                                    <div className="flex items-center gap-2 text-ink-secondary">
                                        <Mail className="h-3.5 w-3.5 text-accent" />
                                        <span>{venue.shop.email}</span>
                                    </div>
                                )}
                                <Link
                                    href={`/marketplace/${venue.shop?.slug}`}
                                    className="inline-flex items-center gap-1 text-accent font-semibold hover:underline"
                                >
                                    <span>View host full storefront</span>
                                    <ArrowRight className="h-3 w-3" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                {/* More Spaces from This Venue */}
                {otherSpaces.length > 0 && (
                    <div className="mt-16 border-t border-border pt-10">
                        <div className="flex items-center justify-between mb-6">
                            <div>
                                <h2 className="text-lg font-semibold text-ink">
                                    More Spaces at {venue.shop?.name}
                                </h2>
                                <p className="text-xs text-ink-secondary">
                                    Other halls and meeting rooms by the same host.
                                </p>
                            </div>
                            <Link
                                href={`/marketplace/${venue.shop?.slug}`}
                                className="text-xs font-semibold text-accent hover:underline"
                            >
                                View all spaces
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                            {otherSpaces.map((space) => {
                                const spaceMediaUrl = getMediaUrl(
                                    space.primary_media || space.primaryMedia
                                );

                                return (
                                    <Link
                                        key={space.id}
                                        href={`/marketplace/venues/${space.slug}`}
                                        className="group overflow-hidden rounded-xl border border-border bg-surface transition-all hover:border-accent hover:shadow-md flex flex-col"
                                    >
                                        <div className="aspect-video w-full bg-canvas flex items-center justify-center overflow-hidden border-b border-border">
                                            {spaceMediaUrl ? (
                                                <img
                                                    src={spaceMediaUrl}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <Building2 className="h-8 w-8 text-ink-tertiary" />
                                            )}
                                        </div>
                                        <div className="p-4 flex flex-1 flex-col">
                                            <h3 className="text-xs font-semibold text-ink group-hover:text-accent transition-colors">
                                                {space.title}
                                            </h3>
                                            <div className="mt-auto pt-3 flex items-center justify-between text-xs font-medium text-ink">
                                                <span>
                                                    {formatPrice(
                                                        space.rental_price_pesewas,
                                                        space.pricing_model,
                                                        space.price_visibility
                                                    )}
                                                </span>
                                                <ArrowRight className="h-3.5 w-3.5 text-accent" />
                                            </div>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    </div>
                )}
            </div>

            {/* Inquiry / Booking Preview Modal */}
            {inquiryModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl space-y-4">
                        <div className="flex items-center justify-between border-b border-border pb-3">
                            <h3 className="text-sm font-semibold text-ink">
                                Request to Book {venue.title}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setInquiryModalOpen(false)}
                                className="text-ink-secondary hover:text-ink"
                            >
                                ✕
                            </button>
                        </div>

                        <p className="text-xs text-ink-secondary leading-relaxed">
                            Booking workflows and instant date holds will unlock in{' '}
                            <strong>Build 2 (Booking & Negotiation Engine)</strong>. In the
                            meantime, you can directly reach out to the venue host team:
                        </p>

                        <div className="rounded-lg bg-canvas p-4 border border-border space-y-2 text-xs">
                            <div className="font-semibold text-ink">{venue.shop?.name}</div>
                            {venue.shop?.phone && <div>📞 {venue.shop.phone}</div>}
                            {venue.shop?.email && <div>✉️ {venue.shop.email}</div>}
                            {venue.shop?.address && (
                                <div>
                                    📍 {venue.shop.address}, {venue.shop.city}
                                </div>
                            )}
                        </div>

                        <button
                            type="button"
                            onClick={() => setInquiryModalOpen(false)}
                            className="w-full rounded-lg bg-accent py-2 text-xs font-semibold text-white"
                        >
                            Got It
                        </button>
                    </div>
                </div>
            )}
        </MarketplaceLayout>
    );
}
