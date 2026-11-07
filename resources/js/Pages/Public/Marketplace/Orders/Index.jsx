import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { StoreFulfilmentSelector } from '@/Components/Marketplace/StoreFulfilmentChoice';
import StoreHandoverPass from '@/Components/Marketplace/StoreHandoverPass';
import csrfFetch from '@/lib/csrfFetch';
import { formatDuesMoney } from '@/lib/dues';
import { QuoteTimer, STORE_BUTTON } from '@/Components/Marketplace/StoreCheckout';

export function OrderSummary({ order }) {
    const steps =
        order.fulfilment_method === 'delivery'
            ? ['Confirmed', 'Ready', 'Dispatched', 'Delivered']
            : ['Confirmed', 'Ready', 'Collected'];
    const step = ['collected', 'delivered'].includes(order.fulfilment_state)
        ? steps.length - 1
        : order.fulfilment_state === 'dispatched'
          ? 2
          : order.fulfilment_state === 'ready'
            ? 1
            : 0;
    return (
        <div className="space-y-5 break-words">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-xs uppercase tracking-widest text-accent">{order.seller}</p>
                    <h2 className="mt-2 text-xl font-semibold">Order {order.number}</h2>
                </div>
                <p className="text-2xl font-semibold">
                    {formatDuesMoney(order.total_pesewas, order.currency)}
                </p>
            </div>
            <dl className="grid gap-4 border-y border-border py-4 sm:grid-cols-2">
                <div>
                    <dt className="text-xs text-ink-secondary">Payment</dt>
                    <dd className="mt-1 font-medium">
                        {order.payment_label ?? order.payment_state}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-ink-secondary">Handover</dt>
                    <dd className="mt-1 font-medium">
                        {order.fulfilment_label ?? order.fulfilment_state}
                    </dd>
                </div>
            </dl>
            {order.state === 'confirmed' && order.fulfilment_method && (
                <ol
                    aria-label="Order handover progress"
                    className="flex min-w-0 flex-wrap gap-x-5 gap-y-3 text-xs"
                >
                    {steps.map((label, index) => (
                        <li
                            key={label}
                            aria-current={index === step ? 'step' : undefined}
                            className={`border-t-2 pt-2 ${index <= step ? 'border-accent text-accent' : 'border-border text-ink-secondary'}`}
                        >
                            {label}
                        </li>
                    ))}
                </ol>
            )}
            {order.handed_over_at && (
                <p className="text-sm text-ink-secondary">
                    Handover recorded: {new Date(order.handed_over_at).toLocaleString()}
                </p>
            )}
            {order.lines?.map((line) => (
                <div
                    key={line.id}
                    className="flex min-w-0 flex-wrap justify-between gap-3 border-b border-border pb-4"
                >
                    <div className="min-w-0 flex-1">
                        <h3 className="font-medium">{line.title}</h3>
                        <p className="mt-1 text-sm text-ink-secondary">
                            {Object.entries(line.options ?? {})
                                .map(([k, v]) => `${k}: ${v}`)
                                .join(' · ')}{' '}
                            · Qty {line.quantity}
                        </p>
                    </div>
                    <p>{formatDuesMoney(line.amount_pesewas, order.currency)}</p>
                </div>
            ))}
            {order.charges?.map((charge) => (
                <p key={charge.label} className="flex flex-wrap justify-between gap-3 text-sm">
                    <span>{charge.label}</span>
                    <span>{formatDuesMoney(charge.amount_pesewas, order.currency)}</span>
                </p>
            ))}
            {order.production_terms && (
                <section className="space-y-3 border-l-2 border-accent bg-accent/5 p-4">
                    <h3 className="font-semibold">Your agreed specification</h3>
                    <p className="whitespace-pre-line text-sm leading-6">{order.customisation}</p>
                    <h4 className="text-sm font-semibold">Production timeframe</h4>
                    <p className="whitespace-pre-line text-sm leading-6">
                        {order.production_terms}
                    </p>
                </section>
            )}
            {order.buyer_fee_pesewas != null && (
                <p className="text-sm">
                    Checkout fee: {formatDuesMoney(order.buyer_fee_pesewas, order.currency)}
                </p>
            )}
            {order.message && (
                <p
                    role="status"
                    className="border-l-2 border-accent bg-accent/5 p-4 text-sm leading-6"
                >
                    {order.message}
                </p>
            )}
            <section>
                <h3 className="font-semibold">
                    {order.fulfilment_method === 'delivery'
                        ? 'Seller-arranged delivery'
                        : 'Collection'}
                </h3>
                <p className="mt-2 whitespace-pre-line text-sm leading-6 text-ink-secondary">
                    {order.fulfilment_terms
                        ? (order.fulfilment_method === 'delivery'
                              ? [
                                    order.fulfilment_terms.delivery_timing,
                                    order.fulfilment_terms.delivery_instructions,
                                    'Delivery is included in the displayed price.',
                                ]
                              : [
                                    order.fulfilment_terms.collection_location,
                                    order.fulfilment_terms.collection_hours,
                                    order.fulfilment_terms.collection_instructions,
                                ]
                          )
                              .filter(Boolean)
                              .join('\n')
                        : order.collection_terms}
                </p>
            </section>
            {order.fulfilment_method === 'delivery' && order.destination && (
                <section>
                    <h3 className="font-semibold">Delivery destination</h3>
                    <address className="mt-2 space-y-1 text-sm not-italic text-ink-secondary">
                        {[
                            'recipient',
                            'address_line_1',
                            'address_line_2',
                            'locality',
                            'country',
                            'postal_code',
                            'landmark',
                            'phone',
                            'instructions',
                        ].map(
                            (key) =>
                                order.destination[key] && <p key={key}>{order.destination[key]}</p>
                        )}
                    </address>
                    <p className="mt-3 text-xs text-ink-secondary">
                        Contact the seller before dispatch if these details need correcting.
                    </p>
                </section>
            )}
            <section>
                <h3 className="font-semibold">Refunds & support</h3>
                <p className="mt-2 whitespace-pre-line text-sm leading-6 text-ink-secondary">
                    {order.refund_terms}
                </p>
                <p className="mt-2 text-sm">{order.support_contact}</p>
            </section>
        </div>
    );
}
export default function StoreOrders({ orderId = null, receipt = false }) {
    const [busy, setBusy] = useState(false);
    const [fulfilmentPending, setFulfilmentPending] = useState(false);
    const actionRequest = useRef(null);
    useEffect(() => () => actionRequest.current?.abort(), []);
    const [quoteExpired, setQuoteExpired] = useState(false);
    const [acceptedQuote, setAcceptedQuote] = useState(false);
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [page, setPage] = useState(1);
    useEffect(() => {
        let controller;
        let timer;
        let generation = 0;
        const clear = () => {
            actionRequest.current?.abort();
            setBusy(false);
            generation++;
            controller?.abort();
            clearTimeout(timer);
            setData(null);
        };
        const load = async () => {
            clear();
            const current = generation;
            controller = new AbortController();
            setError('');
            try {
                const response = await csrfFetch(
                    `/my/store-orders${orderId ? `/${orderId}${receipt ? '/receipt' : ''}` : `?page=${page}`}`,
                    { cache: 'no-store', signal: controller.signal }
                );
                if (!response.ok)
                    throw new Error(
                        response.status === 401
                            ? 'Verify your email to see your orders.'
                            : 'This order could not be loaded. Retry or return to your orders.'
                    );
                const result = await response.json().catch(() => {
                    throw new Error('The order response could not be read. Please retry.');
                });
                if (
                    !Number.isFinite(result.verified_until) ||
                    (orderId
                        ? !(receipt ? result.receipt : result.order)
                        : !Array.isArray(result.orders?.data))
                )
                    throw new Error('The order response could not be read. Please retry.');
                if (current !== generation || controller.signal.aborted) return;
                if (result.verified_until * 1000 <= Date.now())
                    throw new Error('Verify your email to see your orders.');
                setData(result);
                timer = setTimeout(
                    () => {
                        clear();
                        setError('Verify your email to see your orders.');
                    },
                    Math.max(0, result.verified_until * 1000 - Date.now())
                );
            } catch (e) {
                if (e.name !== 'AbortError' && current === generation) setError(e.message);
            }
        };
        const visibility = () => (document.visibilityState === 'hidden' ? clear() : load());
        load();
        window.addEventListener('focus', load);
        window.addEventListener('pageshow', load);
        document.addEventListener('visibilitychange', visibility);
        return () => {
            generation++;
            controller?.abort();
            clearTimeout(timer);
            window.removeEventListener('focus', load);
            window.removeEventListener('pageshow', load);
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [orderId, receipt, page, revision]);
    const order = data?.order;
    useEffect(() => setQuoteExpired(false), [order?.id, order?.expires_at]);
    useEffect(() => setAcceptedQuote(false), [order?.id, order?.seller_quote?.acceptance_hash]);
    const cancel = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        const controller = new AbortController();
        actionRequest.current = controller;
        try {
            const response = await csrfFetch(`/my/store-orders/${order.id}/cancel`, {
                method: 'POST',
                signal: controller.signal,
            });
            if (!response.ok)
                throw new Error(
                    'The hold could not be released. Refresh the order before retrying.'
                );
            setRevision((v) => v + 1);
        } catch (e) {
            if (!controller.signal.aborted) setError(e.message);
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    };
    const start = async () => {
        if (busy || fulfilmentPending || (order.seller_quote && !acceptedQuote)) return;
        setBusy(true);
        setError('');
        const controller = new AbortController();
        actionRequest.current = controller;
        try {
            const response = await csrfFetch(`/my/store-orders/${order.id}/checkout`, {
                method: 'POST',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    submission_key: crypto.randomUUID(),
                    ...(order.seller_quote
                        ? { accepted_quote_hash: order.seller_quote.acceptance_hash }
                        : {}),
                }),
            });
            const result = await response.json().catch(() => ({}));
            if (controller.signal.aborted) return;
            if (!response.ok)
                throw new Error('Checkout needs review. Refresh your order before trying again.');
            if (result.checkout_url) window.location.assign(result.checkout_url);
            else if (result.status === 'failed')
                setError('The payment provider could not start this payment. Check your details and try again.');
            else setRevision((v) => v + 1);
        } catch (e) {
            if (!controller.signal.aborted) setError(e.message);
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    };
    return (
        <PublicLayout>
            <Head title={receipt ? 'Store receipt' : 'Your store orders'} />
            <main className="mx-auto w-full max-w-4xl px-5 py-8 text-ink sm:py-12">
                <nav
                    aria-label="Orders navigation"
                    className="mb-8 flex flex-wrap gap-5 text-sm text-accent underline"
                >
                    <a href="/my?section=money">My MiConvener</a>
                    <a href="/my/store-orders">All store orders</a>
                </nav>
                <p className="text-xs uppercase tracking-widest text-accent">Your purchases</p>
                <h1 className="mt-3 text-3xl font-semibold tracking-tight">
                    {receipt ? 'Payment receipt' : orderId ? 'Your order' : 'Your store orders'}
                </h1>
                <p className="mt-3 text-sm text-ink-secondary">
                    Private records for your verified email. Orders and receipts are available
                    online only.
                </p>
                {error && (
                    <div role="alert" className="mt-6 space-y-3 border border-border p-5">
                        <p>{error}</p>
                        <a
                            href={`/my?return_to=${encodeURIComponent(window.location.pathname)}`}
                            className="text-accent underline"
                        >
                            Verify your email
                        </a>
                        <button
                            className="ml-4 text-accent underline"
                            onClick={() => setRevision((v) => v + 1)}
                        >
                            Retry
                        </button>
                    </div>
                )}
                {!data && !error && (
                    <p role="status" className="mt-8">
                        Checking your orders…
                    </p>
                )}
                {data && (
                    <div className="mt-8">
                        {receipt ? (
                            <section className="space-y-5 border border-border bg-surface p-5 sm:p-8">
                                <h2 className="text-xl font-semibold">
                                    Receipt {data.receipt.number}
                                </h2>
                                <p className="text-sm text-ink-secondary">
                                    {data.receipt.seller} ·{' '}
                                    {new Date(data.receipt.issued_at).toLocaleString(undefined, {
                                        dateStyle: 'medium',
                                        timeStyle: 'short',
                                    })}
                                </p>
                                <p className="text-3xl font-semibold">
                                    {formatDuesMoney(
                                        data.receipt.total_pesewas,
                                        data.receipt.currency
                                    )}
                                </p>
                                {data.receipt.lines.map((l) => (
                                    <p
                                        key={l.id}
                                        className="break-words border-t border-border pt-3"
                                    >
                                        {l.title} · Qty {l.quantity} ·{' '}
                                        {formatDuesMoney(l.amount_pesewas, data.receipt.currency)}
                                    </p>
                                ))}
                                {data.receipt.charges?.map((charge) => (
                                    <p
                                        key={charge.label}
                                        className="flex flex-wrap justify-between gap-3 text-sm"
                                    >
                                        <span>{charge.label}</span>
                                        <span>
                                            {formatDuesMoney(
                                                charge.amount_pesewas,
                                                data.receipt.currency
                                            )}
                                        </span>
                                    </p>
                                ))}
                                {data.receipt.customisation && (
                                    <section className="wrap-anywhere space-y-2 border-t border-border pt-4">
                                        <h3 className="font-medium">Your agreed specification</h3>
                                        <p className="whitespace-pre-wrap text-sm">
                                            {data.receipt.customisation}
                                        </p>
                                    </section>
                                )}
                                {data.receipt.production_terms && (
                                    <section className="wrap-anywhere space-y-2">
                                        <h3 className="font-medium">Production timeframe</h3>
                                        <p className="whitespace-pre-wrap text-sm">
                                            {data.receipt.production_terms}
                                        </p>
                                    </section>
                                )}
                                {data.receipt.buyer_fee_pesewas != null && (
                                    <p className="text-sm">
                                        Checkout fee included:{' '}
                                        {formatDuesMoney(
                                            data.receipt.buyer_fee_pesewas,
                                            data.receipt.currency
                                        )}
                                    </p>
                                )}
                                <p className="text-sm text-ink-secondary">
                                    {data.receipt.receipt_terms}
                                </p>
                                <p className="text-xs text-ink-secondary">
                                    Payment record only. This is not a VAT invoice or evidence of
                                    collection. Refund status appears on your order.
                                </p>
                                <button className={STORE_BUTTON} onClick={() => window.print()}>
                                    Print / save receipt
                                </button>
                                <a
                                    className="block text-accent underline"
                                    href={`/my/store-orders/${orderId}`}
                                >
                                    Back to order
                                </a>
                            </section>
                        ) : order ? (
                            <section className="border border-border bg-surface p-5 sm:p-8">
                                <OrderSummary order={order} />
                                {order.state === 'confirmed' && (
                                    <div className="mt-6">
                                        <StoreHandoverPass
                                            key={order.id}
                                            order={order}
                                            verifiedUntil={data.verified_until}
                                            onRefresh={() => setRevision((value) => value + 1)}
                                        />
                                    </div>
                                )}
                                {order.checkout_allowed && (
                                    <div className="mt-6 space-y-3">
                                        <QuoteTimer
                                            expires={order.expires_at}
                                            stockHeld={!order.seller_quote}
                                            onExpire={setQuoteExpired}
                                        />
                                        {order.seller_quote && (
                                            <label className="flex items-start gap-3 border border-border p-4 text-sm leading-6">
                                                <input
                                                    type="checkbox"
                                                    checked={acceptedQuote}
                                                    onChange={(event) =>
                                                        setAcceptedQuote(event.target.checked)
                                                    }
                                                    className="mt-1 accent-accent"
                                                />
                                                I accept the quoted specification, production
                                                timeframe, fulfilment arrangements and final payable
                                                total.
                                            </label>
                                        )}
                                        <button
                                            disabled={
                                                busy ||
                                                fulfilmentPending ||
                                                (order.seller_quote && !acceptedQuote) ||
                                                quoteExpired ||
                                                Date.parse(order.expires_at) <= Date.now()
                                            }
                                            className={STORE_BUTTON}
                                            onClick={start}
                                        >
                                            {order.total_pesewas === 0
                                                ? 'Confirm free order'
                                                : 'Continue to payment'}
                                        </button>
                                    </div>
                                )}
                                {!order.seller_quote &&
                                    ['quoted', 'confirmed'].includes(order.state) && (
                                        <StoreFulfilmentSelector
                                            order={order}
                                            onPendingChange={setFulfilmentPending}
                                            onAccepted={(accepted) => {
                                                if (accepted.order_id !== order.id)
                                                    window.location.assign(
                                                        `/my/store-orders/${accepted.order_id}`
                                                    );
                                                else setRevision((value) => value + 1);
                                            }}
                                        />
                                    )}
                                {order.cancel_allowed && (
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={cancel}
                                        className="mt-5 block text-sm text-accent underline"
                                    >
                                        {order.seller_quote
                                            ? 'Decline unpaid quote'
                                            : 'Release unpaid stock hold'}
                                    </button>
                                )}
                                {order.ready_email_state && (
                                    <p className="mt-5 text-xs text-ink-secondary">
                                        Ready notice:{' '}
                                        {
                                            {
                                                sent: 'Sent',
                                                queued: 'Queued',
                                                sending: 'Sending',
                                                retryable: 'Delayed — retry scheduled',
                                                exhausted: 'Delivery needs support',
                                                superseded: 'Replaced by a newer order status',
                                            }[order.ready_email_state]
                                        }
                                        . Check this page for the current order status even if email
                                        is delayed.
                                    </p>
                                )}
                                {order.receipt_url && (
                                    <a
                                        className="mt-6 inline-block text-accent underline"
                                        href={order.receipt_url}
                                    >
                                        Read payment receipt
                                    </a>
                                )}
                                {(!order.seller_quote || order.receipt_url) && (
                                    <p className="mt-5 text-xs text-ink-secondary">
                                        Receipt email:{' '}
                                        {order.email_label ?? order.email_state ?? 'Not queued'}.
                                        You can read the receipt here even if delivery is delayed.
                                    </p>
                                )}
                                <button
                                    onClick={() => setRevision((v) => v + 1)}
                                    className="mt-5 block text-sm text-accent underline"
                                >
                                    Refresh order status
                                </button>
                            </section>
                        ) : (
                            <>
                                <div className="divide-y divide-border border border-border bg-surface">
                                    {data.orders.data.length ? (
                                        data.orders.data.map((row) => (
                                            <a
                                                href={`/my/store-orders/${row.id}`}
                                                key={row.id}
                                                className="block space-y-2 p-5 hover:bg-surface-hover"
                                            >
                                                <p className="font-semibold">{row.seller}</p>
                                                <p className="break-words text-sm">
                                                    Order {row.number} ·{' '}
                                                    {formatDuesMoney(
                                                        row.total_pesewas,
                                                        row.currency
                                                    )}
                                                </p>
                                                <p className="text-xs text-ink-secondary">
                                                    {row.payment_label} · {row.fulfilment_label}
                                                </p>
                                            </a>
                                        ))
                                    ) : (
                                        <p className="p-8 text-ink-secondary">
                                            No orders for this verified email yet. Visit an
                                            organisation’s storefront to explore its items.
                                        </p>
                                    )}
                                </div>
                                <div className="mt-4 flex gap-5">
                                    <button
                                        disabled={page <= 1}
                                        onClick={() => setPage((v) => v - 1)}
                                        className="text-accent disabled:opacity-40"
                                    >
                                        Previous
                                    </button>
                                    <p>
                                        Page {data.orders.current_page} of {data.orders.last_page}
                                    </p>
                                    <button
                                        disabled={page >= data.orders.last_page}
                                        onClick={() => setPage((v) => v + 1)}
                                        className="text-accent disabled:opacity-40"
                                    >
                                        Next
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                )}
            </main>
        </PublicLayout>
    );
}
