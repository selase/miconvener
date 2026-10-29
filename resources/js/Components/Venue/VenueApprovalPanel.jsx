import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import { formatInZone } from '@/lib/venueTime';

const newOperationKey = () =>
    globalThis.crypto?.randomUUID
        ? globalThis.crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
              const r = (Math.random() * 16) | 0;
              return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
          });

const DELIVERY = {
    queued: 'Email queued',
    sending: 'Email sending',
    sent: 'Email sent',
    failed: 'Email could not be sent',
    uncertain: 'Email not confirmed, not resent',
    none: 'No email',
};

const ACTION_LABEL = {
    requested: 'Request received',
    approved: 'Approved, dates held',
    renewed: 'Hold renewed',
    declined: 'Declined',
    released: 'Hold released',
    cancelled: 'Cancelled by requester',
};

const MODES = {
    approve: { route: 'tenant.venue.inquiries.approve', confirm: 'Confirm approval', hours: true },
    renew: { route: 'tenant.venue.inquiries.renew', confirm: 'Confirm renewal', hours: true },
    decline: { route: 'tenant.venue.inquiries.decline', confirm: 'Confirm decline', reason: true },
    release: { route: 'tenant.venue.inquiries.release', confirm: 'Confirm release', reason: true },
};

const money = (pesewas, currency = 'GHS') =>
    `${currency} ${(pesewas / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * A payment the calendar could not honour on a legacy booking. Staff
 * reconcile it outside this page: there is deliberately no confirm, retry
 * or refund control here.
 */
export function VenuePaymentConflictBanner({ conflicts }) {
    if (!conflicts?.length) {
        return null;
    }

    return (
        <div
            role="alert"
            className="rounded-lg border border-danger-fg/40 bg-danger-bg p-4 text-sm text-danger-fg"
        >
            <div className="flex items-center gap-2 font-semibold">
                <AlertTriangle className="h-4 w-4" />
                <span>Payment/calendar conflict — reconciliation required</span>
            </div>
            <p className="mt-1 text-ink-secondary">
                A payment arrived for dates that are no longer free. It is recorded, the dates stay
                with the other booking, and nothing was confirmed, charged again or refunded.
                Contact MiConvener finance to reconcile it.
            </p>
            <ul className="mt-2 space-y-1">
                {conflicts.map((conflict) => (
                    <li key={conflict.id} className="font-mono text-xs text-ink">
                        {conflict.provider_reference} ·{' '}
                        {money(conflict.amount_pesewas ?? 0, conflict.currency ?? 'GHS')}
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function VenueApprovalPanel({ inquiry }) {
    const approval = inquiry.approval;
    const status = approval.effective_status;
    const [mode, setMode] = useState(null);
    const [hours, setHours] = useState(String(approval.default_hold_hours));
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);
    const [operationKey, setOperationKey] = useState(newOperationKey);
    const until = approval.hold_expires_at
        ? formatInZone(approval.hold_expires_at, approval.timezone)
        : null;

    const open = (next) => {
        setMode(next);
        setErrors({});
        setReason('');
        setHours(String(approval.default_hold_hours));
        setOperationKey(newOperationKey());
    };

    const submit = (event) => {
        event.preventDefault();
        if (processing || !mode) {
            return;
        }
        const config = MODES[mode];
        const found = {};
        const wholeHours = Number(hours);
        if (
            config.hours &&
            !(
                Number.isInteger(wholeHours) &&
                wholeHours >= 1 &&
                wholeHours <= approval.max_hold_hours
            )
        ) {
            found.hold_hours = `A hold lasts between 1 and ${approval.max_hold_hours} hours.`;
        }
        if (config.reason && reason.trim() === '') {
            found.reason = 'Give the requester a reason.';
        }
        if (Object.keys(found).length > 0) {
            setErrors(found);

            return;
        }

        setErrors({});
        setProcessing(true);
        router.post(
            route(config.route, { inquiry: inquiry.id }),
            {
                operation_key: operationKey,
                ...(config.hours ? { hold_hours: wholeHours } : {}),
                ...(config.reason ? { reason: reason.trim() } : {}),
            },
            {
                preserveScroll: true,
                onError: (serverErrors) => setErrors(serverErrors),
                onSuccess: () => setMode(null),
                onFinish: () => setProcessing(false),
            }
        );
    };

    return (
        <section
            aria-labelledby="venue-approval-heading"
            className="rounded-xl border border-border bg-surface p-5 shadow-xs space-y-4"
        >
            <h3 id="venue-approval-heading" className="text-sm font-semibold text-ink">
                Venue approval
            </h3>

            {status === 'pending_host_approval' && (
                <p className="text-sm text-ink-secondary">
                    This request does not hold these dates. Other requests and bookings can still
                    take them until you approve.
                </p>
            )}
            {status === 'pending_host_approval' && approval.dates_available === false && (
                <p className="text-sm text-danger-fg">
                    These dates are now taken by another booking, hold or blackout, so this request
                    cannot be approved. You can decline it with a reason.
                </p>
            )}
            {status === 'approved_hold' && (
                <p className="text-sm text-ink-secondary">
                    <span className="font-semibold text-ink">Dates held until {until}.</span> No one
                    else can book them meanwhile. This is a hold, not a confirmed booking, and
                    payment is not available yet.
                </p>
            )}
            {status === 'hold_expired' && (
                <p className="text-sm text-ink-secondary">
                    The hold ended {until}. The dates are no longer held and anyone can take them.
                    Renew only if they are still free.
                </p>
            )}
            {(status === 'declined' || status === 'cancelled') && (
                <p className="text-sm text-ink-secondary">
                    This request is closed.
                    {approval.decision_reason && (
                        <>
                            {' '}
                            <span className="font-semibold text-ink">Reason: </span>
                            {approval.decision_reason}
                        </>
                    )}
                </p>
            )}

            {!mode && (
                <div className="flex flex-wrap gap-2">
                    {status === 'pending_host_approval' && (
                        <>
                            <Button
                                type="button"
                                variant="primary"
                                disabled={approval.dates_available === false}
                                onClick={() => open('approve')}
                            >
                                Approve dates
                            </Button>
                            <Button type="button" onClick={() => open('decline')}>
                                Decline request
                            </Button>
                        </>
                    )}
                    {status === 'approved_hold' && (
                        <Button type="button" onClick={() => open('release')}>
                            Release hold
                        </Button>
                    )}
                    {status === 'hold_expired' && (
                        <>
                            <Button type="button" variant="primary" onClick={() => open('renew')}>
                                Renew hold
                            </Button>
                            <Button type="button" onClick={() => open('decline')}>
                                Decline request
                            </Button>
                        </>
                    )}
                </div>
            )}

            {mode && (
                <form onSubmit={submit} className="space-y-3" noValidate>
                    {MODES[mode].hours && (
                        <Input
                            label="Hold the dates for (hours)"
                            type="number"
                            min="1"
                            max={approval.max_hold_hours}
                            value={hours}
                            onChange={(event) => setHours(event.target.value)}
                            hint={`Between 1 and ${approval.max_hold_hours} hours. The hold ends at the exact deadline.`}
                            error={errors.hold_hours}
                        />
                    )}
                    {MODES[mode].reason && (
                        <div>
                            <label
                                htmlFor="venue-decision-reason"
                                className="mb-1.5 block text-sm font-medium text-ink"
                            >
                                Reason shown to the requester
                            </label>
                            <textarea
                                id="venue-decision-reason"
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                maxLength={1000}
                                rows={3}
                                aria-invalid={errors.reason ? true : undefined}
                                className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                            {errors.reason && (
                                <p className="mt-1 text-sm text-danger-fg">{errors.reason}</p>
                            )}
                        </div>
                    )}
                    {(errors.booking || errors.starts_at || errors.operation_key) && (
                        <p className="text-sm text-danger-fg">
                            {errors.booking || errors.starts_at || errors.operation_key}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Button type="submit" variant="primary" disabled={processing}>
                            {MODES[mode].confirm}
                        </Button>
                        <Button type="button" onClick={() => setMode(null)} disabled={processing}>
                            Back
                        </Button>
                    </div>
                </form>
            )}

            {Object.keys(errors).length > 0 && (
                <div role="alert" className="sr-only">
                    {Object.values(errors).join(' ')}
                </div>
            )}

            {approval.actions?.length > 0 && (
                <div className="border-t border-border pt-3">
                    <h4 className="text-xs font-semibold uppercase tracking-wide text-ink-secondary">
                        History
                    </h4>
                    <ul className="mt-2 space-y-1.5 text-xs text-ink-secondary">
                        {approval.actions.map((action) => (
                            <li key={action.id} className="flex flex-wrap justify-between gap-2">
                                <span>{ACTION_LABEL[action.action] ?? action.action}</span>
                                <span>
                                    {DELIVERY[action.delivery_state] ?? action.delivery_state}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
