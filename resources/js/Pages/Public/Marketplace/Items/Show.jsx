import { useEffect, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Package, MapPin, LockKeyhole } from 'lucide-react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { formatDuesMoney } from '@/lib/dues';
import csrfFetch from '@/lib/csrfFetch';

export default function ItemShow({
    item: initialItem,
    storefrontUrl,
    preview = false,
    verifiedUntil = null,
}) {
    const privateItem = initialItem.visibility === 'members';
    const [item, setItem] = useState(privateItem ? null : initialItem);
    const [checking, setChecking] = useState(privateItem);
    const [isPreview, setPreview] = useState(preview);
    const [expires, setExpires] = useState(verifiedUntil);
    const [selected, setSelected] = useState(
        initialItem.variants.find((variant) => variant.available)?.id ?? initialItem.variants[0]?.id
    );
    const [photo, setPhoto] = useState(0);
    const currentRequest = useRef(null);
    useEffect(() => {
        const refresh = async () => {
            currentRequest.current?.abort();
            const controller = new AbortController();
            currentRequest.current = controller;
            setItem(null);
            setChecking(true);
            try {
                const response = await csrfFetch(
                    route('marketplace.items.show', { slug: initialItem.slug }),
                    { cache: 'no-store', signal: controller.signal }
                );
                if (!response.ok) return;
                const data = await response.json();
                if (
                    data.item?.visibility === 'members' &&
                    data.verifiedUntil != null &&
                    data.verifiedUntil * 1000 <= Date.now()
                )
                    return;
                if (!controller.signal.aborted) {
                    setItem(data.item);
                    setPreview(data.preview ?? false);
                    setPhoto(0);
                    setExpires(data.verifiedUntil);
                }
            } catch {
                /* Keep private content cleared if revalidation cannot complete. */
            } finally {
                if (!controller.signal.aborted) setChecking(false);
            }
        };
        if (privateItem) refresh();
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
    }, [initialItem.slug, privateItem]);
    useEffect(() => {
        if (expires === null) return;
        const timer = window.setTimeout(
            () => {
                setItem(null);
            },
            Math.max(0, expires * 1000 - Date.now())
        );
        return () => window.clearTimeout(timer);
    }, [expires]);
    const variant =
        item?.variants.find((candidate) => candidate.id === selected) ?? item?.variants[0];
    const showOptions =
        item &&
        (item.variants.length > 1 || Object.keys(item.variants[0]?.options ?? {}).length > 0);
    return (
        <MarketplaceLayout>
            <Head title={item ? `${item.title} · ${item.seller.name}` : 'Item unavailable'} />
            <article className="mx-auto max-w-6xl px-5 py-8 sm:py-12">
                <Link
                    href={storefrontUrl}
                    className="mb-8 inline-flex items-center gap-2 text-sm text-ink-secondary hover:text-accent"
                >
                    <ArrowLeft className="h-4 w-4" />
                    Back to the store
                </Link>
                {!item ? (
                    <div role="status" className="border border-border bg-surface p-8">
                        <h1 className="text-2xl font-semibold text-ink">
                            {checking
                                ? 'Checking item access…'
                                : 'This item is no longer available to this session.'}
                        </h1>
                        <p className="mt-3 text-ink-secondary">
                            Return to the store or verify your membership again. Refresh to retry if
                            your connection was interrupted.
                        </p>
                    </div>
                ) : (
                    <>
                        {isPreview && (
                            <p className="mb-6 border-l-2 border-accent bg-accent/5 p-4 text-sm text-ink">
                                Seller preview · This item is not publicly available in its current
                                state.
                            </p>
                        )}
                        <div className="grid min-w-0 gap-8 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-14">
                            <section aria-label="Product photos" className="min-w-0">
                                <div className="flex aspect-square items-center justify-center border border-border bg-surface p-6">
                                    {item.photos[photo] ? (
                                        <img
                                            src={item.photos[photo].url}
                                            alt={item.title}
                                            className="h-full w-full object-contain"
                                        />
                                    ) : (
                                        <div className="space-y-3 text-center text-ink-tertiary">
                                            <Package
                                                className="mx-auto h-20 w-20"
                                                strokeWidth={1}
                                            />
                                            <p className="text-sm">Product photo coming soon</p>
                                        </div>
                                    )}
                                </div>
                                {item.photos.length > 1 && (
                                    <div className="mt-3 grid grid-cols-4 gap-3">
                                        {item.photos.map((image, index) => (
                                            <button
                                                type="button"
                                                key={image.id}
                                                onClick={() => setPhoto(index)}
                                                aria-label={`Photo ${index + 1}`}
                                                aria-pressed={photo === index}
                                                className={`aspect-square border bg-surface p-2 ${photo === index ? 'border-accent' : 'border-border'}`}
                                            >
                                                <img
                                                    src={image.url}
                                                    alt=""
                                                    className="h-full w-full object-contain"
                                                />
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </section>
                            <section className="min-w-0 space-y-6">
                                <div className="space-y-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-accent">
                                        {item.seller.name} · Store
                                    </p>
                                    <h1 className="break-words text-3xl font-semibold leading-tight tracking-tight text-ink sm:text-4xl">
                                        {item.title}
                                    </h1>
                                    {item.visibility === 'members' && (
                                        <p className="inline-flex items-center gap-2 text-xs text-ink-secondary">
                                            <LockKeyhole className="h-3.5 w-3.5" />
                                            For verified active members
                                        </p>
                                    )}
                                </div>
                                {variant && (
                                    <p className="text-2xl font-semibold text-ink">
                                        {formatDuesMoney(variant.price_pesewas, item.currency)}
                                    </p>
                                )}
                                {showOptions && (
                                    <fieldset>
                                        <legend className="mb-3 text-sm font-semibold text-ink">
                                            Choose an option
                                        </legend>
                                        <div className="flex flex-wrap gap-2">
                                            {item.variants.map((option) => (
                                                <label
                                                    key={option.id}
                                                    className={`relative min-w-0 max-w-full cursor-pointer break-words border px-4 py-3 text-sm text-ink focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent ${variant?.id === option.id ? 'border-accent bg-accent/5' : 'border-border bg-surface'}`}
                                                >
                                                    <input
                                                        className="sr-only"
                                                        type="radio"
                                                        name="variant"
                                                        checked={variant?.id === option.id}
                                                        onChange={() => setSelected(option.id)}
                                                    />
                                                    {Object.entries(option.options)
                                                        .map(([name, value]) => `${name}: ${value}`)
                                                        .join(' · ') || 'Standard'}
                                                    {!option.available && (
                                                        <span className="ml-2 text-xs text-ink-tertiary">
                                                            Unavailable
                                                        </span>
                                                    )}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                )}
                                <p role="status" className="text-sm font-medium text-ink-secondary">
                                    {variant?.available ? 'In stock' : 'Currently unavailable'}
                                </p>
                                <div className="border border-border bg-canvas p-5">
                                    <h2 className="font-semibold text-ink">
                                        Purchasing is not enabled yet
                                    </h2>
                                    <p className="mt-2 text-sm leading-relaxed text-ink-secondary">
                                        Browse the catalog and explore your options. This page does
                                        not reserve stock or accept payment.
                                    </p>
                                </div>
                                {item.description && (
                                    <div className="whitespace-pre-line break-words text-sm leading-7 text-ink-secondary">
                                        {item.description}
                                    </div>
                                )}
                                <div className="border-t border-border pt-5">
                                    <h2 className="flex items-center gap-2 text-sm font-semibold text-ink">
                                        <MapPin className="h-4 w-4" />
                                        Collection information
                                    </h2>
                                    <p className="mt-3 whitespace-pre-line break-words text-sm leading-6 text-ink-secondary">
                                        {item.collection_instructions}
                                    </p>
                                </div>
                            </section>
                        </div>
                    </>
                )}
            </article>
        </MarketplaceLayout>
    );
}
