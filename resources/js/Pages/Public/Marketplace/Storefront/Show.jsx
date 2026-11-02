import { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import MerchandiseCard from '@/Components/Marketplace/MerchandiseCard';
import csrfFetch from '@/lib/csrfFetch';
import {
    Building2,
    MapPin,
    ShieldCheck,
    Phone,
    Mail,
    ArrowRight,
    CheckCircle2,
} from 'lucide-react';

export default function StorefrontShow({
    shop,
    spaces = [],
    merchandise: initialMerchandise = [],
    catalogEnabled = false,
    merchandiseLinks: initialLinks = [],
    memberAccessUrl,
    verifiedUntil = null,
}) {
    const initialHasPrivate = initialMerchandise.some((item) => item.visibility === 'members');
    const [merchandise, setMerchandise] = useState(() =>
        initialMerchandise.filter((item) => item.visibility !== 'members')
    );
    const [checking, setChecking] = useState(initialHasPrivate);
    const [catalog, setCatalog] = useState(catalogEnabled);
    const [links, setLinks] = useState(initialLinks);
    const [expires, setExpires] = useState(verifiedUntil);
    const [refreshError, setRefreshError] = useState('');
    const currentRequest = useRef(null);
    useEffect(() => {
        const refresh = async () => {
            currentRequest.current?.abort();
            const controller = new AbortController();
            currentRequest.current = controller;
            setMerchandise([]);
            setRefreshError('');
            setChecking(true);
            try {
                const response = await csrfFetch(
                    `${route('marketplace.storefront.show', { merchant_slug: shop.slug })}${window.location.search}`,
                    { cache: 'no-store', signal: controller.signal }
                );
                if (!response.ok) throw new Error('Catalog unavailable');
                const data = await response.json();
                if (!controller.signal.aborted) {
                    setMerchandise(
                        data.verifiedUntil != null && data.verifiedUntil * 1000 <= Date.now()
                            ? data.merchandise.filter((item) => item.visibility !== 'members')
                            : data.merchandise
                    );
                    setCatalog(data.catalogEnabled);
                    setLinks(data.merchandiseLinks ?? []);
                    setExpires(data.verifiedUntil);
                }
            } catch {
                if (!controller.signal.aborted)
                    setRefreshError(
                        'The catalog could not be refreshed. Refresh this page to retry.'
                    );
            } finally {
                if (!controller.signal.aborted) setChecking(false);
            }
        };
        if (initialHasPrivate) refresh();
        window.addEventListener('focus', refresh);
        const back = (event) => {
            if (event.persisted) refresh();
        };
        window.addEventListener('pageshow', back);
        return () => {
            currentRequest.current?.abort();
            window.removeEventListener('focus', refresh);
            window.removeEventListener('pageshow', back);
        };
    }, [shop.slug, initialHasPrivate]);
    useEffect(() => {
        if (expires === null) return;
        const timer = window.setTimeout(
            () => {
                setMerchandise((items) => items.filter((item) => item.visibility !== 'members'));
            },
            Math.max(0, expires * 1000 - Date.now())
        );
        return () => window.clearTimeout(timer);
    }, [expires]);
    const getMediaUrl = (media) => {
        if (!media) return null;
        if (typeof media === 'string') return media;
        return media.url || media.file_path || null;
    };

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

    return (
        <MarketplaceLayout>
            <Head title={`${shop.name} · Store | MiConvener`} />

            {/* Merchant Banner & Header */}
            <div className="relative border-b border-border bg-gradient-to-r from-surface to-canvas">
                <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                    <div className="flex flex-col sm:flex-row items-start sm:items-center gap-6">
                        <div className="grid h-20 w-20 shrink-0 place-items-center rounded-none bg-accent text-white font-bold text-2xl shadow-md border-2 border-surface">
                            {shop.name.slice(0, 2).toUpperCase()}
                        </div>

                        <div className="space-y-2 flex-1">
                            <div className="flex flex-wrap items-center gap-2.5">
                                <h1 className="text-2xl sm:text-3xl font-bold text-ink">
                                    {shop.name}
                                </h1>
                                {shop.verification_status === 'verified' && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-accent/10 px-2.5 py-0.5 text-xs font-semibold text-accent">
                                        <ShieldCheck className="h-3.5 w-3.5" />
                                        <span>Verified organisation</span>
                                    </span>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-4 text-xs text-ink-secondary">
                                <div className="flex items-center gap-1">
                                    <MapPin className="h-3.5 w-3.5 text-accent" />
                                    <span>
                                        {[shop.address, shop.city, shop.region]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </span>
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
                    <div className="rounded-none border border-border bg-surface p-6 space-y-3">
                        <h2 className="text-sm font-semibold text-ink uppercase tracking-wider">
                            About {shop.name}
                        </h2>
                        <p className="text-xs text-ink-secondary leading-relaxed whitespace-pre-line">
                            {shop.description}
                        </p>
                    </div>
                )}

                {catalog && (
                    <section aria-labelledby="merchandise-heading" className="space-y-6">
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-[0.15em] text-accent">
                                    For our community
                                </p>
                                <h2
                                    id="merchandise-heading"
                                    className="text-2xl font-semibold text-ink"
                                >
                                    From our store
                                </h2>
                                <p className="mt-2 text-sm text-ink-secondary">
                                    Browse options and fulfilment details, or request a quote for
                                    made-to-order items. Each item shows its available ordering
                                    options.
                                </p>
                            </div>
                            {memberAccessUrl && (
                                <a
                                    href={memberAccessUrl}
                                    className="text-sm font-medium text-accent underline"
                                >
                                    Verify member access
                                </a>
                            )}
                        </div>
                        {refreshError && (
                            <p
                                role="alert"
                                className="border border-border p-4 text-sm text-ink-secondary"
                            >
                                {refreshError}
                            </p>
                        )}
                        {merchandise.length ? (
                            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                {merchandise.map((item) => (
                                    <MerchandiseCard key={item.id} item={item} />
                                ))}
                            </div>
                        ) : (
                            <p className="border border-border bg-surface p-6 text-sm text-ink-secondary">
                                {checking
                                    ? 'Checking catalog access…'
                                    : 'No merchandise is available in this view. Members can verify their email to see eligible items.'}
                            </p>
                        )}
                        {links.length > 3 && (
                            <nav aria-label="Merchandise pages" className="flex flex-wrap gap-2">
                                {links.map((link, index) =>
                                    link.url ? (
                                        <Link
                                            key={index}
                                            href={link.url}
                                            aria-current={link.active ? 'page' : undefined}
                                            className={`border px-3 py-2 text-sm ${link.active ? 'border-accent text-accent' : 'border-border text-ink-secondary'}`}
                                        >
                                            {link.label.replace(/&laquo;|&raquo;/g, '').trim()}
                                        </Link>
                                    ) : null
                                )}
                            </nav>
                        )}
                    </section>
                )}

                {/* All Spaces Listed by this Host */}
                {(spaces.length > 0 || !catalog) && (
                    <div className="space-y-6">
                        <div>
                            <h2 className="text-xl font-semibold text-ink">Spaces & Event Halls</h2>
                            <p className="text-xs text-ink-secondary">
                                Explore all conference halls, ballrooms, and outdoor spaces hosted
                                by {shop.name}.
                            </p>
                        </div>

                        {spaces.length === 0 ? (
                            <div className="rounded-none border border-dashed border-border bg-surface p-12 text-center text-xs text-ink-secondary">
                                <Building2 className="mx-auto h-8 w-8 text-ink-tertiary mb-2" />
                                <span>No spaces currently published by this host.</span>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                                {spaces.map((space) => {
                                    const includedAmenities = (space.amenities || [])
                                        .filter((a) => a.is_included)
                                        .slice(0, 3);

                                    const spaceMediaUrl = getMediaUrl(
                                        space.primary_media || space.primaryMedia
                                    );

                                    return (
                                        <div
                                            key={space.id}
                                            className="group flex flex-col overflow-hidden rounded-none border border-border bg-surface transition-all hover:border-accent hover:shadow-md"
                                        >
                                            <div className="aspect-video w-full bg-canvas flex items-center justify-center overflow-hidden border-b border-border">
                                                {spaceMediaUrl ? (
                                                    <img
                                                        src={spaceMediaUrl}
                                                        alt=""
                                                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                                    />
                                                ) : (
                                                    <Building2 className="h-8 w-8 text-ink-tertiary" />
                                                )}
                                            </div>

                                            <div className="flex flex-1 flex-col p-5">
                                                <h3 className="text-sm font-semibold text-ink group-hover:text-accent transition-colors line-clamp-1">
                                                    <Link
                                                        href={`/marketplace/venues/${space.slug}`}
                                                    >
                                                        {space.title}
                                                    </Link>
                                                </h3>

                                                <div className="mt-2.5 flex flex-wrap items-center gap-1.5 text-[10.5px] text-ink-secondary">
                                                    {space.capacity_breakdown?.banquet && (
                                                        <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                            {space.capacity_breakdown.banquet}{' '}
                                                            Banquet
                                                        </span>
                                                    )}
                                                    {space.capacity_breakdown?.theater && (
                                                        <span className="rounded bg-canvas px-2 py-0.5 border border-border">
                                                            {space.capacity_breakdown.theater}{' '}
                                                            Theater
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
                )}

                {(shop.phone || shop.email) && (
                    <section className="border border-border bg-surface p-6">
                        <h2 className="text-lg font-semibold text-ink">Get in touch</h2>
                        <p className="mt-2 text-sm text-ink-secondary">
                            Contact {shop.name} for help with listings and collection information.
                        </p>
                        <div className="mt-4 flex flex-wrap gap-5 text-sm text-accent">
                            {shop.email && (
                                <a className="break-all underline" href={`mailto:${shop.email}`}>
                                    {shop.email}
                                </a>
                            )}
                            {shop.phone && (
                                <a className="underline" href={`tel:${shop.phone}`}>
                                    {shop.phone}
                                </a>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </MarketplaceLayout>
    );
}
