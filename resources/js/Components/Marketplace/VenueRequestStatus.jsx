import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock, XCircle } from 'lucide-react';
import { formatInZone } from '@/lib/venueTime';

const newOperationKey = () =>
    globalThis.crypto?.randomUUID
        ? globalThis.crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
              const r = (Math.random() * 16) | 0;
              return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });

const STATES = {
    pending_host_approval: { label: 'Awaiting host approval', tone: 'blue', Icon: Clock },
    approved_hold: { label: 'Dates held', tone: 'amber', Icon: CheckCircle2 },
    hold_expired: { label: 'Hold expired', tone: 'slate', Icon: AlertCircle },
    declined: { label: 'Declined', tone: 'rose', Icon: XCircle },
    cancelled: { label: 'Cancelled', tone: 'slate', Icon: XCircle },
};

const TONES = {
    blue: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    rose: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
    slate: 'bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

const LIVE_STATES = ['pending_host_approval', 'approved_hold'];
const MAX_TIMER_MS = 2 ** 31 - 1;

/**
 * Keeps an open page honest: once a hold's deadline passes it reads as expired
 * without a manual refresh, and the page asks the server for the real state
 * then and whenever the tab becomes visible again.
 */
export function useLiveApproval(approval) {
    const [now, setNow] = useState(() => Date.now());
    const status = approval?.effective_status;
    const deadline = approval?.hold_expires_at ? new Date(approval.hold_expires_at).getTime() : null;

    useEffect(() => {
        if (!LIVE_STATES.includes(status)) return undefined;

        const refresh = () => {
            setNow(Date.now());
            router.reload({ only: ['booking'] });
        };
        const onVisible = () => {
            if (document.visibilityState === 'visible') refresh();
        };
        const timer =
            status === 'approved_hold' && deadline !== null
                ? setTimeout(refresh, Math.min(Math.max(deadline - Date.now(), 0) + 1000, MAX_TIMER_MS))
                : null;

        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            if (timer !== null) clearTimeout(timer);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [status, deadline]);

    if (status === 'approved_hold' && deadline !== null && now >= deadline) {
        return { ...approval, effective_status: 'hold_expired', can_cancel: false };
    }

    return approval;
}

/** The status pill for an approval-first venue request. */
export function VenueRequestBadge({ approval }) {
    const state = STATES[approval.effective_status] ?? STATES.pending_host_approval;
    const { Icon } = state;

    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold ${TONES[state.tone]}`}
        >
            <Icon className="h-3.5 w-3.5" />
            <span>{state.label}</span>
        </span>
    );
}

/**
 * What the requester can rely on right now: whether the dates are reserved,
 * until when, and why a request ended. Never a confirmation or a payment.
 */
export default function VenueRequestStatus({ booking }) {
    const { approval } = booking;
    const [operationKey] = useState(newOperationKey);
    const status = approval.effective_status;
    const until = approval.hold_expires_at
        ? formatInZone(approval.hold_expires_at, approval.timezone)
        : null;

    return (
        <section
            aria-labelledby="venue-request-status"
            className="rounded-xl border border-border bg-canvas/50 p-5 space-y-3 text-xs"
        >
            <h2 id="venue-request-status" className="text-sm font-semibold text-ink">
                Where your request stands
            </h2>

            {status === 'pending_host_approval' && (
                <p className="text-ink-secondary leading-relaxed">
                    Your dates are not reserved until the host approves. We will email you when the
                    host decides, and this page always shows the latest answer.
                </p>
            )}
            {status === 'approved_hold' && (
                <p className="text-ink-secondary leading-relaxed">
                    <span className="font-semibold text-ink">Dates held until {until}.</span> This
                    is a hold, not a confirmed booking, and payment is not available yet.
                </p>
            )}
            {status === 'hold_expired' && (
                <p className="text-ink-secondary leading-relaxed">
                    The hold ended {until}, so the dates are no longer reserved. Contact the host if
                    you still want them.
                </p>
            )}
            {(status === 'declined' || status === 'cancelled') && (
                <p className="text-ink-secondary leading-relaxed">
                    {status === 'declined'
                        ? 'The host could not accept this request.'
                        : 'This request is closed.'}
                    {approval.decision_reason && (
                        <>
                            {' '}
                            <span className="font-semibold text-ink">Reason: </span>
                            {approval.decision_reason}
                        </>
                    )}
                </p>
            )}

            {approval.price_is_indicative && (
                <p className="text-ink-tertiary">
                    The price shown is indicative. The host confirms the final terms.
                </p>
            )}

            {approval.can_cancel && (
                <button
                    type="button"
                    onClick={() =>
                        router.post(
                            route('marketplace.bookings.cancel', booking.booking_reference),
                            {
                                operation_key: operationKey,
                            }
                        )
                    }
                    className="rounded-lg border border-border bg-surface px-3 py-2 font-medium text-ink hover:bg-surface-hover transition-colors"
                >
                    Cancel this request
                </button>
            )}
        </section>
    );
}
