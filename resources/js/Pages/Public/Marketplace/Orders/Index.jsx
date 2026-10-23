import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import { formatDuesMoney } from '@/lib/dues';
import { QuoteTimer, STORE_BUTTON } from '@/Components/Marketplace/StoreCheckout';

export function OrderSummary({ order }) {
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
                    <dt className="text-xs text-ink-secondary">Collection</dt>
                    <dd className="mt-1 font-medium">
                        {order.fulfilment_label ?? order.fulfilment_state}
                    </dd>
                </div>
            </dl>
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
                <h3 className="font-semibold">Collection</h3>
                <p className="mt-2 whitespace-pre-line text-sm leading-6 text-ink-secondary">
                    {order.collection_terms}
                </p>
            </section>
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
    const actionRequest = useRef(null);
    useEffect(() => () => actionRequest.current?.abort(), []);
    const [quoteExpired, setQuoteExpired] = useState(false);
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
        if (busy) return;
        setBusy(true);
        setError('');
        const controller = new AbortController();
        actionRequest.current = controller;
        try {
            const response = await csrfFetch(`/my/store-orders/${order.id}/checkout`, {
                method: 'POST',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ submission_key: crypto.randomUUID() }),
            });
            const result = await response.json().catch(() => ({}));
            if (controller.signal.aborted) return;
            if (!response.ok)
                throw new Error('Checkout needs review. Refresh your order before trying again.');
            if (result.checkout_url) window.location.assign(result.checkout_url);
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
                                {order.checkout_allowed && (
                                    <div className="mt-6 space-y-3">
                                        <QuoteTimer
                                            expires={order.expires_at}
                                            onExpire={setQuoteExpired}
                                        />
                                        <button
                                            disabled={
                                                busy ||
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
                                {order.cancel_allowed && (
                                    <button
                                        type="button"
                                        disabled={busy}
                                        onClick={cancel}
                                        className="mt-5 block text-sm text-accent underline"
                                    >
                                        Release unpaid stock hold
                                    </button>
                                )}
                                {order.receipt_url && (
                                    <a
                                        className="mt-6 inline-block text-accent underline"
                                        href={order.receipt_url}
                                    >
                                        Read payment receipt
                                    </a>
                                )}
                                <p className="mt-5 text-xs text-ink-secondary">
                                    Receipt email:{' '}
                                    {order.email_label ?? order.email_state ?? 'Not queued'}. You
                                    can read the receipt here even if delivery is delayed.
                                </p>
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
