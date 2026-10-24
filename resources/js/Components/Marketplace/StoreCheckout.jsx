import { useEffect, useRef, useState } from 'react';
import { StoreFulfilmentSelector } from '@/Components/Marketplace/StoreFulfilmentChoice';
import csrfFetch from '@/lib/csrfFetch';
import { formatDuesMoney } from '@/lib/dues';

export const STORE_BUTTON =
    'inline-flex min-h-11 items-center justify-center border border-border bg-accent px-5 py-3 text-sm font-medium text-accent-ink disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';
export function QuoteTimer({ expires, onExpire }) {
    const [left, setLeft] = useState(() =>
        Math.max(0, Math.ceil((Date.parse(expires) - Date.now()) / 1000))
    );
    useEffect(() => {
        const tick = () =>
            setLeft(Math.max(0, Math.ceil((Date.parse(expires) - Date.now()) / 1000)));
        tick();
        const timer = setInterval(tick, 1000);
        return () => clearInterval(timer);
    }, [expires]);
    useEffect(() => {
        if (left === 0) onExpire?.(true);
    }, [left, onExpire]);
    return (
        <p className="text-sm text-ink-secondary" role="timer" aria-label="Quote time remaining">
            {left
                ? `Price and stock held for ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`
                : 'Quote expired. Return to the item for a fresh quote.'}
        </p>
    );
}
export default function StoreCheckout({ shop, variant }) {
    const [quantity, setQuantity] = useState(1);
    const [quote, setQuote] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [unknown, setUnknown] = useState(false);
    const [expired, setExpired] = useState(false);
    const alert = useRef(null);
    const key = useRef(crypto.randomUUID());
    const currentRequest = useRef(null);
    useEffect(() => () => currentRequest.current?.abort(), []);
    useEffect(() => {
        if (error) alert.current?.focus();
    }, [error]);
    useEffect(() => {
        if (!quote) return;
        const timer = setTimeout(
            () => setExpired(true),
            Math.max(0, Date.parse(quote.expires_at) - Date.now())
        );
        return () => clearTimeout(timer);
    }, [quote]);
    const request = async (start) => {
        currentRequest.current?.abort();
        const controller = new AbortController();
        currentRequest.current = controller;
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(
                start
                    ? `/my/store-orders/${quote.id}/checkout`
                    : route('store.quotes.create', { shop }),
                {
                    method: 'POST',
                    signal: controller.signal,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(
                        start
                            ? { submission_key: key.current }
                            : {
                                  submission_key: key.current,
                                  lines: [{ variant_id: variant.id, quantity: Number(quantity) }],
                              }
                    ),
                }
            );
            const data = await response.json().catch(() => ({}));
            if (controller.signal.aborted) return;
            if (!response.ok)
                throw new Error(
                    response.status === 401
                        ? 'Verify your email in My MiConvener, then return to this item.'
                        : Object.values(data.errors ?? {}).flat()[0] ||
                              data.message ||
                              'Could not complete this step. Please retry.'
                );
            if (!start) {
                setQuote(data.order);
                setExpired(false);
            } else if (data.checkout_url) window.location.assign(data.checkout_url);
            else if (data.status === 'confirmed')
                window.location.assign(`/my/store-orders/${quote.id}`);
            else setUnknown(true);
        } catch (failure) {
            if (controller.signal.aborted) return;
            setError(
                start
                    ? 'Payment could not be confirmed. Open your order before trying again; do not pay twice.'
                    : failure.message
            );
            if (start) setUnknown(true);
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    };
    return (
        <section aria-label="Checkout" className="space-y-4 border border-border bg-surface p-5">
            <h2 className="text-lg font-semibold text-ink">
                {quote ? 'Review your order' : 'Make it yours'}
            </h2>
            {error && (
                <p
                    ref={alert}
                    tabIndex={-1}
                    role="alert"
                    className="break-words border-l-2 border-danger p-3 text-sm text-ink"
                >
                    {error}
                </p>
            )}
            {!quote ? (
                <>
                    <label className="block text-sm text-ink">
                        Quantity
                        <input
                            type="number"
                            min="1"
                            max="99"
                            step="1"
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            className="mt-2 block min-h-11 w-24 border border-border bg-canvas px-3 text-ink"
                        />
                    </label>
                    <button
                        type="button"
                        className={STORE_BUTTON}
                        disabled={
                            busy ||
                            !variant?.available ||
                            !Number.isInteger(Number(quantity)) ||
                            quantity < 1 ||
                            quantity > 99
                        }
                        onClick={() => request(false)}
                    >
                        {busy ? 'Checking availability…' : 'Review order'}
                    </button>
                    <p className="text-xs text-ink-secondary">
                        Verified email required. No membership or dues needed for public items.
                    </p>
                    <a className="text-sm text-accent underline" href="/my">
                        My MiConvener · verify email or view orders
                    </a>
                </>
            ) : (
                <>
                    <p className="text-2xl font-semibold text-ink">
                        {formatDuesMoney(quote.total_pesewas, quote.currency)}
                    </p>
                    <p className="text-sm text-ink-secondary">
                        {quantity} item{Number(quantity) === 1 ? '' : 's'} · Choose collection or
                        delivery
                    </p>
                    {quote.buyer_fee_pesewas != null && (
                        <p className="text-sm text-ink-secondary">
                            Checkout fee included:{' '}
                            {formatDuesMoney(quote.buyer_fee_pesewas, quote.currency)}
                        </p>
                    )}
                    <QuoteTimer expires={quote.expires_at} />
                    <p className="whitespace-pre-line break-words text-sm text-ink">
                        {quote.collection_terms}
                    </p>
                    <p className="whitespace-pre-line break-words text-sm text-ink-secondary">
                        {quote.refund_terms}
                    </p>
                    {quote && (
                        <StoreFulfilmentSelector
                            order={quote}
                            onAccepted={async (accepted) => {
                                const response = await csrfFetch(
                                    `/my/store-orders/${accepted.order_id}`,
                                    { headers: { Accept: 'application/json' } }
                                );
                                if (!response.ok) {
                                    setQuote(null);
                                    setError('Verify your email to continue.');
                                    return;
                                }
                                const data = await response.json();
                                setQuote(data.order);
                            }}
                        />
                    )}
                    {unknown ? (
                        <p role="status" className="text-sm text-ink">
                            Your payment needs confirmation. Do not pay again. Check your order or
                            contact the seller.
                        </p>
                    ) : (
                        <button
                            type="button"
                            className={STORE_BUTTON}
                            disabled={busy || expired || !quote.fulfilment_method}
                            onClick={() => request(true)}
                        >
                            {busy
                                ? 'Preparing checkout…'
                                : quote.total_pesewas === 0
                                  ? 'Confirm free order'
                                  : 'Continue to payment'}
                        </button>
                    )}
                    <a
                        href={`/my/store-orders/${quote.id}`}
                        className="block text-sm text-accent underline"
                    >
                        View this order
                    </a>
                    <p className="break-words text-xs text-ink-secondary">
                        Need help? {quote.support_contact}
                    </p>
                    <p className="text-xs text-ink-secondary">
                        The timer releases our stock hold. A mobile-money payment can still arrive
                        later and require review.
                    </p>
                </>
            )}
        </section>
    );
}
