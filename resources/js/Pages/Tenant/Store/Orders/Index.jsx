import { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import csrfFetch from '@/lib/csrfFetch';
import { formatDuesMoney } from '@/lib/dues';
import { OrderSummary } from '@/Pages/Public/Marketplace/Orders/Index';
import { STORE_BUTTON } from '@/Components/Marketplace/StoreCheckout';
const input =
    'mt-2 block min-h-11 w-full border border-border bg-canvas px-3 py-2 text-sm text-ink';
function Pages({ rows, param = 'page' }) {
    return (
        <nav aria-label="Pagination" className="mt-4 flex flex-wrap gap-5 text-sm">
            {rows.current_page > 1 && (
                <a href={`?${param}=${rows.current_page - 1}`} className="text-accent underline">
                    Previous
                </a>
            )}
            <p>
                Page {rows.current_page} of {rows.last_page}
            </p>
            {rows.current_page < rows.last_page && (
                <a href={`?${param}=${rows.current_page + 1}`} className="text-accent underline">
                    Next
                </a>
            )}
        </nav>
    );
}
export default function SellerStore({
    finance = false,
    orders = { data: [] },
    available_pesewas = 0,
    accounts = [],
    payouts = { data: [] },
    refunds = { data: [] },
}) {
    const can = usePage().props.auth?.can ?? {};
    const catalogEnabled = usePage().props.tenant?.features?.commerce_catalog ?? false;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [open, setOpen] = useState(null);
    const [reason, setReason] = useState('');
    const [capture, setCapture] = useState('');
    const [amount, setAmount] = useState('');
    const [account, setAccount] = useState(accounts[0]?.id ?? '');
    const [otp, setOtp] = useState('');
    const keys = useRef({});
    const alert = useRef(null);
    useEffect(() => {
        if (error) alert.current?.focus();
    }, [error]);
    const operation = (scope) => keys.current[scope] ?? (keys.current[scope] = crypto.randomUUID());
    const post = async (url, body) => {
        if (busy) return;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const response = await csrfFetch(url, { method: 'POST', body: JSON.stringify(body) });
            const data = await response.json().catch(() => ({}));
            if (!response.ok)
                throw new Error(
                    Object.values(data.errors ?? {}).flat()[0] ||
                        data.message ||
                        'This action could not be completed. Refresh its status before retrying.'
                );
            setMessage(
                data.message || `Request recorded: ${data.status ?? 'check refreshed status'}`
            );
            setOtp('');
            router.reload({ preserveScroll: true });
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };
    const reserve = (e) => {
        e.preventDefault();
        if (!/^\d{1,12}(\.\d{1,2})?$/.test(amount)) {
            setError('Enter a GHS amount with up to two decimal places.');
            return;
        }
        const [whole, fraction = ''] = amount.split('.');
        post(route('tenant.store.payouts.create'), {
            operation_key: operation('reserve:' + amount + ':' + account),
            amount: Number(whole) * 100 + Number(fraction.padEnd(2, '0')),
            currency: 'GHS',
            payout_account_id: account,
        });
    };
    return (
        <ConsoleLayout>
            <Head title={finance ? 'Store finance' : 'Store orders'} />
            <PageHeader title={finance ? 'Store finance' : 'Store orders'} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-5 text-ink sm:p-8">
                <nav className="flex flex-wrap gap-5 text-sm text-accent underline">
                    {can.manage_store_catalog && catalogEnabled && (
                        <a href={route('tenant.store.catalog.index')}>Catalog</a>
                    )}
                    {finance && <a href={route('tenant.store.orders.index')}>Orders</a>}
                </nav>
                <p className="max-w-3xl text-sm leading-6 text-ink-secondary">
                    {finance
                        ? 'New commerce funds only. Refunds, transfer fees and approved reserves reduce the amount available. Legacy venue, dues and Giving balances are separate.'
                        : 'Payment and collection are separate. Collection handover is not enabled in this release. Use the order’s recorded support and collection terms.'}
                </p>
                {error && (
                    <p
                        role="alert"
                        tabIndex={-1}
                        ref={alert}
                        className="break-words border-l-2 border-danger bg-surface p-4"
                    >
                        {error}
                    </p>
                )}
                {message && (
                    <p role="status" className="break-words border-l-2 border-accent p-4">
                        {message}
                    </p>
                )}
                {finance ? (
                    <>
                        <section className="grid gap-6 border border-border bg-surface p-5 md:grid-cols-2">
                            <div>
                                <h2 className="font-semibold">Available for payout</h2>
                                <p className="mt-3 text-3xl font-semibold">
                                    {formatDuesMoney(available_pesewas, 'GHS')}
                                </p>
                                <p className="mt-3 text-sm text-ink-secondary">
                                    Reserve a payout, then send it once. An OTP or unknown result
                                    stays attached to that original transfer.
                                </p>
                            </div>
                            <form onSubmit={reserve} className="space-y-4">
                                <label className="block text-sm">
                                    Amount (GHS)
                                    <input
                                        required
                                        inputMode="decimal"
                                        value={amount}
                                        onChange={(e) => setAmount(e.target.value)}
                                        className={input}
                                    />
                                </label>
                                <label className="block text-sm">
                                    Verified payout account
                                    <select
                                        required
                                        value={account}
                                        onChange={(e) => setAccount(e.target.value)}
                                        className={input}
                                    >
                                        <option value="">Choose account</option>
                                        {accounts.map((row) => (
                                            <option key={row.id} value={row.id}>
                                                {row.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <button
                                    className={STORE_BUTTON}
                                    disabled={busy || !accounts.length}
                                >
                                    Reserve payout
                                </button>
                                {!accounts.length && (
                                    <p className="text-sm text-ink-secondary">
                                        Add and verify a payout account in Finance first.
                                    </p>
                                )}
                            </form>
                        </section>
                        <section className="space-y-4">
                            <h2 className="text-xl font-semibold">Payouts</h2>
                            {payouts.data.length ? (
                                payouts.data.map((row) => (
                                    <article
                                        key={row.id}
                                        className="space-y-3 break-words border border-border bg-surface p-5"
                                    >
                                        <p className="font-semibold">
                                            {formatDuesMoney(row.amount, 'GHS')} · {row.status}
                                        </p>
                                        <p className="text-sm">{row.destination}</p>
                                        {row.message && (
                                            <p className="text-sm text-ink-secondary">
                                                {row.message}
                                            </p>
                                        )}
                                        <div className="flex flex-wrap items-end gap-3">
                                            {row.status === 'scheduled' && (
                                                <button
                                                    disabled={busy}
                                                    className={STORE_BUTTON}
                                                    onClick={() =>
                                                        post(
                                                            route('tenant.store.payouts.send', {
                                                                payout: row.id,
                                                            }),
                                                            {}
                                                        )
                                                    }
                                                >
                                                    Send reserved payout once
                                                </button>
                                            )}
                                            {row.status === 'scheduled' && (
                                                <button
                                                    disabled={busy}
                                                    className="min-h-11 text-accent underline"
                                                    onClick={() =>
                                                        post(
                                                            route('tenant.store.payouts.cancel', {
                                                                payout: row.id,
                                                            }),
                                                            {}
                                                        )
                                                    }
                                                >
                                                    Cancel unsent reservation
                                                </button>
                                            )}
                                            {row.status === 'awaiting_otp' && (
                                                <form
                                                    onSubmit={(e) => {
                                                        e.preventDefault();
                                                        post(
                                                            route('tenant.store.payouts.finalize', {
                                                                payout: row.id,
                                                            }),
                                                            { otp }
                                                        );
                                                    }}
                                                    className="flex flex-wrap items-end gap-3"
                                                >
                                                    <label className="text-sm">
                                                        Transfer OTP
                                                        <input
                                                            required
                                                            inputMode="numeric"
                                                            autoComplete="one-time-code"
                                                            type="password"
                                                            minLength={4}
                                                            maxLength={12}
                                                            pattern="[0-9]{4,12}"
                                                            value={otp}
                                                            onChange={(e) => setOtp(e.target.value)}
                                                            className={input}
                                                        />
                                                    </label>
                                                    <button
                                                        disabled={busy}
                                                        className={STORE_BUTTON}
                                                    >
                                                        Release original transfer
                                                    </button>
                                                </form>
                                            )}
                                            {['processing', 'awaiting_otp'].includes(
                                                row.status
                                            ) && (
                                                <button
                                                    disabled={busy}
                                                    className="min-h-11 text-accent underline"
                                                    onClick={() =>
                                                        post(
                                                            route(
                                                                'tenant.store.payouts.reconcile',
                                                                { payout: row.id }
                                                            ),
                                                            {}
                                                        )
                                                    }
                                                >
                                                    Refresh original transfer
                                                </button>
                                            )}
                                        </div>
                                    </article>
                                ))
                            ) : (
                                <p className="border border-border p-6 text-sm text-ink-secondary">
                                    No commerce payouts requested.
                                </p>
                            )}
                            <Pages rows={payouts} param="payout_page" />
                        </section>
                        <section className="space-y-4">
                            <h2 className="text-xl font-semibold">Refund requests</h2>
                            {refunds.data.length ? (
                                refunds.data.map((row) => (
                                    <article
                                        key={row.id}
                                        className="space-y-3 break-words border border-border bg-surface p-5"
                                    >
                                        <p className="font-semibold">
                                            {formatDuesMoney(row.amount, 'GHS')} · {row.status}
                                        </p>
                                        <p className="text-sm">{row.reason}</p>
                                        <p className="text-sm text-ink-secondary">
                                            {row.status === 'needs_funding'
                                                ? 'This full refund needs additional commerce funds. No provider refund has been sent. Contact platform support to agree funding.'
                                                : row.message}
                                        </p>
                                        {row.status !== 'confirmed' && (
                                            <button
                                                disabled={busy}
                                                className="min-h-11 text-accent underline"
                                                onClick={() =>
                                                    post(
                                                        route('tenant.store.refunds.reconcile', {
                                                            refund: row.id,
                                                        }),
                                                        {}
                                                    )
                                                }
                                            >
                                                Reconcile original refund
                                            </button>
                                        )}
                                    </article>
                                ))
                            ) : (
                                <p className="border border-border p-6 text-sm text-ink-secondary">
                                    No refund requests.
                                </p>
                            )}
                            <Pages rows={refunds} param="refund_page" />
                        </section>
                    </>
                ) : (
                    <>
                        <div className="space-y-4">
                            {orders.data.length ? (
                                orders.data.map((row) => (
                                    <article
                                        key={row.id}
                                        className="min-w-0 border border-border bg-surface p-5"
                                    >
                                        <button
                                            className="flex w-full flex-wrap justify-between gap-3 text-left"
                                            aria-expanded={open === row.id}
                                            onClick={() => {
                                                setOpen(open === row.id ? null : row.id);
                                                setReason('');
                                                setCapture('');
                                            }}
                                        >
                                            <span className="min-w-0 break-words">
                                                <strong>{row.number}</strong>
                                                <span className="mt-2 block text-sm text-ink-secondary">
                                                    {row.buyer_email}
                                                </span>
                                            </span>
                                            <span className="text-sm">
                                                {row.payment_label} ·{' '}
                                                {formatDuesMoney(row.total_pesewas, 'GHS')}
                                            </span>
                                        </button>
                                        {open === row.id && (
                                            <div className="mt-6 border-t border-border pt-6">
                                                <OrderSummary order={row} />
                                                {row.review_reason && (
                                                    <p className="mt-4 break-words text-sm">
                                                        Review reason: {row.review_reason}
                                                    </p>
                                                )}
                                                <p className="mt-4 text-xs text-ink-secondary">
                                                    Receipt email:{' '}
                                                    {row.email_label ??
                                                        row.email_state ??
                                                        'Not queued'}
                                                </p>
                                                {row.can_reconcile && row.total_pesewas > 0 && (
                                                    <button
                                                        type="button"
                                                        disabled={busy}
                                                        className="mt-4 block min-h-11 text-sm text-accent underline"
                                                        onClick={() =>
                                                            post(
                                                                route(
                                                                    'tenant.store.orders.reconcile',
                                                                    { order: row.id }
                                                                ),
                                                                {}
                                                            )
                                                        }
                                                    >
                                                        Reconcile original payment
                                                    </button>
                                                )}
                                                {(row.can_refund || row.can_cancel_free) && (
                                                    <form
                                                        onSubmit={(e) => {
                                                            e.preventDefault();
                                                            post(
                                                                route(
                                                                    row.can_cancel_free
                                                                        ? 'tenant.store.orders.cancel-free'
                                                                        : 'tenant.store.refunds.create',
                                                                    { order: row.id }
                                                                ),
                                                                {
                                                                    operation_key: operation(
                                                                        'refund:' + row.id
                                                                    ),
                                                                    reason,
                                                                    ...(capture
                                                                        ? {
                                                                              observation_id:
                                                                                  capture,
                                                                          }
                                                                        : {}),
                                                                }
                                                            );
                                                        }}
                                                        className="mt-5 space-y-3 border-t border-border pt-5"
                                                    >
                                                        {row.captures?.length > 0 && (
                                                            <label className="block text-sm">
                                                                Captured payment
                                                                <select
                                                                    value={capture}
                                                                    onChange={(e) =>
                                                                        setCapture(e.target.value)
                                                                    }
                                                                    className={input}
                                                                >
                                                                    <option value="">
                                                                        Original paid order
                                                                    </option>
                                                                    {row.captures.map((c) => (
                                                                        <option
                                                                            key={c.id}
                                                                            value={c.id}
                                                                        >
                                                                            {formatDuesMoney(
                                                                                c.amount_pesewas,
                                                                                'GHS'
                                                                            )}{' '}
                                                                            · {c.state} ·{' '}
                                                                            {c.reason ??
                                                                                'Original sale'}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </label>
                                                        )}
                                                        <label className="block text-sm">
                                                            Reason for full cancellation / refund
                                                            <textarea
                                                                required
                                                                minLength={3}
                                                                maxLength={500}
                                                                value={reason}
                                                                onChange={(e) =>
                                                                    setReason(e.target.value)
                                                                }
                                                                className={input}
                                                            />
                                                        </label>
                                                        <p className="text-xs text-ink-secondary">
                                                            Uncollected orders only. Paid refunds
                                                            depend on verified completion and
                                                            available funding before goods return to
                                                            stock.
                                                        </p>
                                                        <button
                                                            disabled={busy}
                                                            className={STORE_BUTTON}
                                                        >
                                                            {row.can_cancel_free
                                                                ? 'Cancel free order'
                                                                : 'Request full refund'}
                                                        </button>
                                                    </form>
                                                )}
                                            </div>
                                        )}
                                    </article>
                                ))
                            ) : (
                                <p className="border border-border bg-surface p-8 text-ink-secondary">
                                    No orders yet. Your catalog remains available for browsing when
                                    checkout is off.
                                </p>
                            )}
                        </div>
                        <Pages rows={orders} />
                    </>
                )}
            </div>
        </ConsoleLayout>
    );
}
