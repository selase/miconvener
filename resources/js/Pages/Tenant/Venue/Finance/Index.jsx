import { useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Tr, Th, Td } from '@/Components/Console/Table';
import { formatMoney, newOperationKey, PURPOSE_LABELS } from '@/lib/venueMoney';

const button = 'inline-flex h-control items-center rounded-md border border-border px-3 text-sm text-ink hover:bg-surface-hover disabled:opacity-50';
const primary = 'inline-flex h-control items-center rounded-md border border-accent bg-accent px-3 text-sm font-medium text-accent-ink hover:opacity-90 disabled:opacity-50';

const STEP_LABELS = { verified: 'Confirmed with payment account', retained: 'Kept for investigation', refunded: 'Refund requested', confirmed: 'Confirmed' };

function useAction() {
    const [busy, setBusy] = useState(null);
    const keys = useRef({});
    const run = (id, url, data = {}) => {
        keys.current[id] ??= newOperationKey();
        setBusy(id);
        router.post(url, { operation_key: keys.current[id], ...data }, {
            preserveScroll: true,
            onSuccess: () => delete keys.current[id],
            onFinish: () => setBusy(null),
        });
    };

    return { busy, run };
}

function ReasonAction({ id, can, children }) {
    const [reason, setReason] = useState('');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <label htmlFor={`reason-${id}`} className="sr-only">Reason</label>
            <input id={`reason-${id}`} value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} placeholder="Reason (required)" className="w-full min-w-40 rounded-md sm:w-56 border border-border bg-surface px-3 py-1.5 text-sm text-ink" />
            {children(reason.trim(), can)}
        </div>
    );
}

function Conflicts({ conflicts }) {
    const { busy, run } = useAction();

    if (!conflicts.length) {
        return <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-ink-secondary">No payments are waiting for finance.</p>;
    }

    return (
        <Table>
            <Thead>
                <Th>Booking</Th><Th>Payment</Th><Th>Status</Th><Th>Action</Th>
            </Thead>
            <tbody>
                {conflicts.map((conflict) => (
                    <Tr key={conflict.id}>
                        <Td>
                            <div className="font-mono text-xs">{conflict.booking_reference}</div>
                            <div className="text-xs text-ink-secondary">{conflict.reason?.replaceAll('_', ' ')}</div>
                        </Td>
                        <Td>
                            <div>{formatMoney(conflict.amount_pesewas)}</div>
                            <div className="font-mono text-xs text-ink-secondary">{conflict.reference}</div>
                        </Td>
                        <Td>
                            <ul className="space-y-1 text-xs">
                                {conflict.steps.length === 0 && <li className="text-ink-secondary">Not checked yet</li>}
                                {conflict.steps.map((step, index) => (
                                    <li key={index}>{STEP_LABELS[step.step]}{step.reason ? ` — ${step.reason}` : ''}</li>
                                ))}
                            </ul>
                            {conflict.classification === 'posted_legacy' && (
                                <p className="mt-1 text-xs text-warning-fg">Already booked by the older payment path. It cannot be refunded here yet.</p>
                            )}
                        </Td>
                        <Td>
                            {conflict.resolved ? (
                                <StatusPill status="success">Resolved</StatusPill>
                            ) : !conflict.verified_resolution_id ? (
                                <button type="button" disabled={busy === conflict.id} className={primary} onClick={() => run(conflict.id, route('tenant.venue.finance.conflicts.verify', { action: conflict.id }))}>
                                    {busy === conflict.id ? 'Checking…' : 'Check with payment account'}
                                </button>
                            ) : (
                                <ReasonAction id={conflict.id}>
                                    {(reason) => (
                                        <>
                                            <button type="button" disabled={!reason || busy === `${conflict.id}-retain`} className={button} onClick={() => run(`${conflict.id}-retain`, route('tenant.venue.finance.resolutions.resolve', { resolution: conflict.verified_resolution_id }), { decision: 'retain', reason })}>Keep</button>
                                            <button type="button" disabled={!reason || conflict.classification !== 'unposted' || busy === `${conflict.id}-refund`} className={primary} onClick={() => run(`${conflict.id}-refund`, route('tenant.venue.finance.resolutions.resolve', { resolution: conflict.verified_resolution_id }), { decision: 'refund', reason })}>Refund in full</button>
                                        </>
                                    )}
                                </ReasonAction>
                            )}
                        </Td>
                    </Tr>
                ))}
            </tbody>
        </Table>
    );
}

function Payments({ payments }) {
    const { busy, run } = useAction();

    if (!payments.length) {
        return <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-ink-secondary">No captured venue payments yet.</p>;
    }

    return (
        <Table>
            <Thead>
                <Th>Booking</Th><Th>Payment</Th><Th>State</Th><Th>Action</Th>
            </Thead>
            <tbody>
                {payments.map((payment) => (
                    <Tr key={payment.id}>
                        <Td><span className="font-mono text-xs">{payment.booking_reference}</span></Td>
                        <Td>{PURPOSE_LABELS[payment.purpose]} · {formatMoney(payment.amount_pesewas)}</Td>
                        <Td>
                            <StatusPill status={payment.payment_state === 'paid' ? 'success' : 'pending'}>{payment.payment_state.replaceAll('_', ' ')}</StatusPill>
                            {payment.refund && <div className="mt-1 text-xs text-ink-secondary">Refund: {payment.refund.status.replaceAll('_', ' ')}</div>}
                        </Td>
                        <Td>
                            {payment.held_captures.length > 0 && (
                                <ul className="space-y-2">
                                    {payment.held_captures.map((capture) => (
                                        <li key={capture.id} className="space-y-1">
                                            <div className="text-xs text-ink-secondary">
                                                Held for review: {formatMoney(capture.amount_pesewas)}
                                                {capture.reason ? ` (${capture.reason.replaceAll('_', ' ')})` : ''}
                                            </div>
                                            <ReasonAction id={capture.id}>
                                                {(reason) => (
                                                    <button type="button" disabled={!reason || busy === capture.id} className={primary} onClick={() => run(capture.id, route('tenant.venue.finance.charges.refund', { charge: payment.id }), { reason, observation_id: capture.id })}>
                                                        Refund this payment
                                                    </button>
                                                )}
                                            </ReasonAction>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {payment.held_captures.length === 0 && payment.refund && payment.refund.status !== 'confirmed' && (
                                <button type="button" disabled={busy === payment.refund.id} className={button} onClick={() => run(payment.refund.id, route('tenant.venue.finance.refunds.reconcile', { refund: payment.refund.id }))}>Refresh refund</button>
                            )}
                            {payment.held_captures.length === 0 && payment.payment_state === 'paid' && (
                                <ReasonAction id={payment.id}>
                                    {(reason) => (
                                        <button type="button" disabled={!reason || busy === payment.id} className={primary} onClick={() => run(payment.id, route('tenant.venue.finance.charges.refund', { charge: payment.id }), { reason })}>Refund in full</button>
                                    )}
                                </ReasonAction>
                            )}
                        </Td>
                    </Tr>
                ))}
            </tbody>
        </Table>
    );
}

export default function VenueFinanceIndex({ can, conflicts, payments }) {
    return (
        <ConsoleLayout>
            <div className="space-y-8">
                <PageHeader title="Venue money" />
                {can.reconcile && (
                    <section aria-labelledby="conflicts-heading" className="space-y-3">
                        <h2 id="conflicts-heading" className="text-base font-semibold text-ink">Payments waiting for finance</h2>
                        <p className="text-sm text-ink-secondary">Payments that arrived after the dates were taken or the booking closed. Nothing is paid out or marked paid from here; check the payment, then keep it or refund it in full.</p>
                        <Conflicts conflicts={conflicts} />
                    </section>
                )}
                {can.refunds && (
                    <section aria-labelledby="payments-heading" className="space-y-3">
                        <h2 id="payments-heading" className="text-base font-semibold text-ink">Captured payments</h2>
                        <p className="text-sm text-ink-secondary">Refunds are always for the full amount paid. A refund finishes when the payment provider confirms it.</p>
                        <Payments payments={payments} />
                    </section>
                )}
                {!can.reconcile && !can.refunds && (
                    <p className="text-sm text-ink-secondary">Mark finished events complete from each booking's page to release their earnings.</p>
                )}
            </div>
        </ConsoleLayout>
    );
}
