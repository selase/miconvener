import { useEffect, useRef, useState } from 'react';
import Button from '@/Components/Console/Button';

export default function MemberBenefitPayment({
    benefit,
    paystackAvailable,
    stripeAvailable,
    busy = false,
    onPay,
    onAcceptPrice,
    onVerify,
    onReassess,
    error,
}) {
    const [acceptedKey, setAcceptedKey] = useState(null);
    const [localBusy, setLocalBusy] = useState(false);
    const submitting = useRef(false);
    const [clock, setClock] = useState(() => Date.now());
    const total = `${benefit.currency} ${(Number(benefit.current_total) / 100).toFixed(2)}`;
    const key = `${benefit.currency}:${benefit.current_total}:${benefit.expires_at}`;
    const state = benefit.payment_state;
    const quoteExpired =
        state === 'open' &&
        benefit.expires_at &&
        new Date(benefit.expires_at).getTime() <= Math.max(clock, Date.now());
    useEffect(() => {
        if (!benefit.expires_at || state !== 'open') return undefined;
        const timer = setTimeout(
            () => setClock(Date.now()),
            Math.max(0, new Date(benefit.expires_at).getTime() - Date.now())
        );
        return () => clearTimeout(timer);
    }, [benefit.expires_at, state]);
    useEffect(() => {
        if (!busy) {
            submitting.current = false;
            setLocalBusy(false);
        }
    }, [busy, error, key]);
    const pay = (provider) => {
        if (submitting.current || busy || acceptedKey !== key) return;
        submitting.current = true;
        setLocalBusy(true);
        try {
            onPay(provider);
        } catch {
            submitting.current = false;
            setLocalBusy(false);
        }
    };
    const fulfilled = state === 'fulfilled';
    const refund = ['refund_pending', 'refund_unknown', 'refunded'].includes(state);
    const held =
        quoteExpired ||
        ['paid_review', 'paid_extra', 'unknown', 'preparing'].includes(state) ||
        benefit.extra_captures > 0;
    const canAcceptPrice =
        !fulfilled && !refund && !held && benefit.eligible && Boolean(onAcceptPrice);
    const canPay = !fulfilled && !refund && !held && benefit.eligible && benefit.current_total > 0;
    return (
        <section
            className="border border-border bg-surface p-5 text-ink"
            aria-label="Member ticket payment"
        >
            <h2 className="text-lg font-semibold">
                {refund
                    ? 'Refund status'
                    : held
                      ? 'Payment review'
                      : fulfilled
                        ? 'Payment confirmed'
                        : 'Your member ticket'}
            </h2>
            <p className="mt-2 text-xl font-semibold">{total}</p>
            {fulfilled && !held ? (
                <p role="status" className="mt-3 text-sm text-ink-secondary">
                    Your payment is confirmed. Your issued ticket remains available in your ticket
                    workspace.
                </p>
            ) : refund ? (
                <p role="status" className="mt-3 text-sm leading-6 text-ink-secondary">
                    {state === 'refunded'
                        ? 'Your refund is confirmed.'
                        : 'The refund is awaiting provider confirmation.'}{' '}
                    Do not pay again.
                </p>
            ) : held ? (
                <p role="status" className="mt-3 text-sm leading-6 text-ink-secondary">
                    {state === 'paid_review' || state === 'paid_extra' || benefit.extra_captures > 0
                        ? 'A payment was received and needs review.'
                        : 'The original payment outcome is not confirmed.'}{' '}
                    Do not pay again. Contact the organiser for reconciliation or a refund.
                </p>
            ) : (
                <p className="mt-3 text-sm leading-6 text-ink-secondary">
                    {benefit.eligible
                        ? 'Your active personal membership has been verified.'
                        : 'Verify your own active membership before continuing.'}{' '}
                    A price quote does not reserve a place.
                </p>
            )}
            {benefit.reference && (
                <p className="mt-2 break-all text-xs text-ink-secondary">
                    Payment reference: {benefit.reference}
                </p>
            )}
            {benefit.expires_at && !refund && !fulfilled && (
                <p className="mt-2 text-xs text-ink-secondary">
                    Quoted price expires {new Date(benefit.expires_at).toLocaleString()}. Payment
                    completion and identity checks still apply.
                </p>
            )}
            {!refund && !fulfilled && onVerify && (
                <button
                    type="button"
                    onClick={onVerify}
                    disabled={busy}
                    className="mt-4 text-sm font-medium text-accent underline"
                >
                    Verify membership
                </button>
            )}
            {state === 'paid_review' && onReassess && (
                <Button type="button" onClick={onReassess} disabled={busy} className="mt-4">
                    Recheck membership
                </Button>
            )}
            {state === 'failed' && (
                <p role="status" className="mt-3 text-sm text-ink-secondary">
                    The provider confirmed the previous payment failed. Verify your membership and
                    accept the current total to start a new checkout.
                </p>
            )}
            {(canPay || canAcceptPrice) && (
                <div className="mt-4 space-y-4">
                    <label className="flex items-start gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={acceptedKey === key}
                            disabled={busy || localBusy}
                            onChange={(e) => setAcceptedKey(e.target.checked ? key : null)}
                            className="mt-1"
                        />
                        I accept the total of {total}.
                    </label>
                    <div className="flex flex-wrap gap-3">
                        {canAcceptPrice && (
                            <Button
                                type="button"
                                disabled={busy || localBusy || acceptedKey !== key}
                                onClick={() => {
                                    if (submitting.current) return;
                                    submitting.current = true;
                                    setLocalBusy(true);
                                    onAcceptPrice();
                                }}
                            >
                                {benefit.current_total === 0
                                    ? 'Complete free registration'
                                    : 'Use this total for offline payment'}
                            </Button>
                        )}

                        {canPay && paystackAvailable && (
                            <Button
                                type="button"
                                disabled={busy || localBusy || acceptedKey !== key}
                                onClick={() => pay('paystack')}
                            >
                                Pay with Paystack
                            </Button>
                        )}
                        {canPay && stripeAvailable && (
                            <Button
                                type="button"
                                disabled={busy || localBusy || acceptedKey !== key}
                                onClick={() => pay('stripe')}
                            >
                                Pay with Stripe
                            </Button>
                        )}
                    </div>
                </div>
            )}
            {!held && !refund && !fulfilled && benefit.current_total === 0 && (
                <p className="mt-3 text-sm text-ink-secondary">
                    No online payment is required. Complete any membership verification or organiser
                    review.
                </p>
            )}
            {error && (
                <p role="alert" className="mt-3 text-sm text-danger-fg">
                    {error}
                </p>
            )}
        </section>
    );
}
