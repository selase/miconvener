import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock, Receipt } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';
import { formatInZone } from '@/lib/venueTime';
import { CHARGE_STATES, PURPOSE_LABELS, formatMoney, newOperationKey } from '@/lib/venueMoney';

const TONES = {
    success: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    pending: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    failed: 'bg-rose-500/10 text-rose-700 dark:text-rose-400',
    neutral: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

const MAX_TIMER_MS = 2 ** 31 - 1;

/** Refresh the booking when the tab regains focus and at the next money deadline. */
function useMoneyRefresh(financial) {
    const next = [
        financial?.quote?.status === 'open' ? financial.quote.expires_at : null,
        ...(financial?.charges ?? []).map((charge) => charge.due_at),
        ...(financial?.charges ?? []).map((charge) => charge.expires_at),
    ]
        .filter(Boolean)
        .map((iso) => new Date(iso).getTime())
        .filter((time) => time > Date.now())
        .sort((a, b) => a - b)[0];

    useEffect(() => {
        const refresh = () => router.reload({ only: ['booking'] });
        const onVisible = () => {
            if (document.visibilityState === 'visible') refresh();
        };
        const timer =
            next === undefined
                ? null
                : setTimeout(refresh, Math.min(next - Date.now() + 1000, MAX_TIMER_MS));
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            if (timer !== null) clearTimeout(timer);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [next]);
}

function Line({ label, value }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-sm">
            <dt className="text-ink-secondary">{label}</dt>
            <dd className="font-medium text-ink">{value}</dd>
        </div>
    );
}

/** The buyer's final quote, acceptance and payments for an approval-first venue booking. */
export default function VenueQuotePanel({ booking }) {
    const financial = booking.financial;
    const [busy, setBusy] = useState(null);
    const [message, setMessage] = useState(null);
    const [receipt, setReceipt] = useState(null);
    const messageRef = useRef(null);
    const acceptKey = useRef(newOperationKey());
    useMoneyRefresh(financial);

    useEffect(() => {
        if (message) messageRef.current?.focus();
    }, [message]);

    if (!financial) return null;
    const { quote, charges, totals } = financial;
    const zone = quote.timezone;
    const at = (iso) => (iso ? formatInZone(iso, zone) : null);

    const accept = () => {
        setBusy('accept');
        router.post(
            route('marketplace.bookings.quote.accept', booking.booking_reference),
            { operation_key: acceptKey.current, accepted_quote_hash: quote.terms_hash },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(null);
                    acceptKey.current = newOperationKey();
                },
            }
        );
    };

    const pay = async (charge) => {
        setBusy(charge.id);
        setMessage(null);
        try {
            const response = await csrfFetch(route('venue.charges.checkout', charge.id), {
                method: 'POST',
                body: JSON.stringify({
                    submission_key: newOperationKey(),
                    accepted_quote_hash: quote.accepted_terms_hash ?? '',
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data.checkout_url) {
                window.location.assign(data.checkout_url);
                return;
            }
            setMessage(
                response.ok
                    ? 'Your payment is being prepared. Reload this page in a moment; do not pay twice.'
                    : data.message || 'This payment is not available right now.'
            );
        } catch {
            setMessage('We could not reach the payment service. Check your connection and try again.');
        } finally {
            setBusy(null);
        }
    };

    const showReceipt = async (charge) => {
        setBusy(`receipt-${charge.id}`);
        try {
            const response = await csrfFetch(route('venue.charges.receipt', charge.id));
            const data = await response.json().catch(() => ({}));
            setReceipt(response.ok ? data.receipt : null);
            if (!response.ok) setMessage('This receipt is not available.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <section aria-labelledby="venue-quote-heading" className="space-y-5 rounded-xl border border-border bg-surface-sunken p-5">
            <div className="flex items-center justify-between gap-3">
                <h2 id="venue-quote-heading" className="text-base font-semibold text-ink">
                    Final quote
                </h2>
                <span className="text-xs text-ink-secondary">Version {quote.version}</span>
            </div>

            {message && (
                <p ref={messageRef} tabIndex={-1} role="alert" className="flex items-start gap-2 rounded-lg bg-rose-500/10 p-3 text-sm text-rose-700 dark:text-rose-400">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{message}</span>
                </p>
            )}

            {quote.status === 'withdrawn' && (
                <p className="text-sm text-ink-secondary">
                    The venue withdrew this quote{quote.withdrawn_reason ? `: ${quote.withdrawn_reason}` : '.'} Wait for a new quote; you have not been charged.
                </p>
            )}
            {quote.status === 'expired' && (
                <p className="text-sm text-ink-secondary">
                    This quote expired on {at(quote.expires_at)}. Ask the venue for a new one; nothing was charged.
                </p>
            )}

            {['open', 'accepted'].includes(quote.status) && (
                <dl className="space-y-2">
                    <Line label="Hire and extras" value={formatMoney(quote.contract_total_pesewas)} />
                    <Line label={PURPOSE_LABELS.rental_initial} value={formatMoney(quote.initial_pesewas)} />
                    {quote.balance_pesewas > 0 && (
                        <Line label={`${PURPOSE_LABELS.rental_balance}, due ${at(quote.balance_due_at)}`} value={formatMoney(quote.balance_pesewas)} />
                    )}
                    {quote.security_pesewas > 0 && (
                        <Line label={`${PURPOSE_LABELS.security}, due ${at(quote.security_due_at)}`} value={formatMoney(quote.security_pesewas)} />
                    )}
                </dl>
            )}
            {['open', 'accepted'].includes(quote.status) && (
                <div className="space-y-1 text-xs text-ink-secondary">
                    <p>Cancellation and refunds: {quote.cancellation_terms}</p>
                    <p>Questions: {quote.support_contact}</p>
                    {quote.status === 'open' && <p>Accept by {at(quote.expires_at)}.</p>}
                </div>
            )}

            {quote.status === 'open' && (
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        onClick={accept}
                        disabled={busy === 'accept'}
                        className="inline-flex h-control items-center gap-2 rounded-md border border-accent bg-accent px-4 text-sm font-medium text-accent-ink hover:opacity-90 disabled:opacity-50"
                    >
                        <CheckCircle2 className="h-4 w-4" />
                        {busy === 'accept' ? 'Accepting…' : 'Accept these terms'}
                    </button>
                    <span className="text-xs text-ink-secondary">Accepting does not take any payment.</span>
                </div>
            )}

            {charges.length > 0 && (
                <ul className="space-y-3" aria-label="Payments">
                    {charges.map((charge) => {
                        const state = CHARGE_STATES[charge.state] ?? CHARGE_STATES.due;

                        return (
                            <li key={charge.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface p-3">
                                <div className="space-y-0.5">
                                    <div className="text-sm font-medium text-ink">
                                        {PURPOSE_LABELS[charge.purpose]} · {formatMoney(charge.amount_pesewas)}
                                    </div>
                                    <div className="flex items-center gap-2 text-xs text-ink-secondary">
                                        <span className={`inline-block rounded-full px-2 py-0.5 font-medium ${TONES[state.tone]}`}>{state.label}</span>
                                        {charge.state === 'paid' && charge.paid_at && <span>on {at(charge.paid_at)}</span>}
                                        {['due', 'overdue'].includes(charge.state) && charge.due_at && (
                                            <span className="inline-flex items-center gap-1">
                                                <Clock className="h-3 w-3" />
                                                due {at(charge.due_at)}
                                            </span>
                                        )}
                                        {charge.state === 'under_review' && <span>The venue's finance team is checking this payment.</span>}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {charge.has_receipt && (
                                        <button
                                            type="button"
                                            onClick={() => showReceipt(charge)}
                                            disabled={busy === `receipt-${charge.id}`}
                                            className="inline-flex h-control items-center gap-1.5 rounded-md border border-border px-3 text-sm text-ink hover:bg-surface-hover disabled:opacity-50"
                                        >
                                            <Receipt className="h-4 w-4" />
                                            Receipt
                                        </button>
                                    )}
                                    {charge.can_pay && (
                                        <button
                                            type="button"
                                            onClick={() => pay(charge)}
                                            disabled={busy === charge.id}
                                            className="inline-flex h-control items-center rounded-md border border-accent bg-accent px-4 text-sm font-medium text-accent-ink hover:opacity-90 disabled:opacity-50"
                                        >
                                            {busy === charge.id ? 'Opening…' : 'Pay now'}
                                        </button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
            {charges.length > 0 && !financial.payments_open && (
                <p className="text-xs text-ink-secondary">Online payment is not open for this venue yet. The venue will contact you.</p>
            )}

            {charges.some((charge) => charge.state === 'paid' || charge.state === 'refunded') && (
                <dl className="space-y-1 border-t border-border pt-3">
                    <Line label="Received for hire" value={formatMoney(totals.rental_received_pesewas)} />
                    <Line label="Refundable security held" value={formatMoney(totals.security_held_pesewas)} />
                    {totals.refunded_pesewas > 0 && <Line label="Refunded" value={formatMoney(totals.refunded_pesewas)} />}
                </dl>
            )}

            {receipt && (
                <div className="space-y-2 rounded-lg border border-border bg-surface p-4 text-sm" role="region" aria-label="Receipt">
                    <div className="flex items-center justify-between">
                        <strong className="text-ink">Receipt {receipt.number}</strong>
                        <button type="button" onClick={() => setReceipt(null)} className="text-xs text-ink-secondary underline">
                            Close
                        </button>
                    </div>
                    {receipt.lines?.map((line) => (
                        <div key={line.title} className="flex justify-between gap-3">
                            <span>{line.title}</span>
                            <span>{formatMoney(line.line_amount_pesewas)}</span>
                        </div>
                    ))}
                    <div className="flex justify-between border-t border-border pt-2 font-semibold">
                        <span>Total</span>
                        <span>{formatMoney(receipt.total_pesewas)}</span>
                    </div>
                </div>
            )}
        </section>
    );
}
