import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';

const LABELS = {
    preparing: 'Payment being prepared',
    open: 'Checkout opened',
    unknown: 'Outcome unknown',
    failed: 'Provider confirmed failure',
    fulfilled: 'Paid · ticket issued',
    paid_review: 'Paid · ticket needs review',
    paid_extra: 'Additional payment · refund review',
    refund_pending: 'Refund awaiting confirmation',
    refund_unknown: 'Refund outcome unknown',
    refunded: 'Refund confirmed',
};
const REASONS = {
    refund_fee_pending: 'Principal refund confirmed; provider fee evidence is still pending.',
    provider_failed:
        'The provider confirmed this payment failed. A new checkout requires fresh verification and acceptance.',
    gateway_fee_pending: 'Provider fee evidence is still pending.',
    capture_late: 'The payment completed after the quote expired.',
    capture_time_unknown: 'The provider has not supplied reliable completion timing.',
    identity_changed: 'The recipient’s identity proof changed.',
    event_unavailable: 'The event or registration is no longer available.',
    capacity_unavailable: 'A place is no longer available.',
    ticket_unavailable: 'This ticket type is no longer available.',
    registration_changed: 'Registration details changed after checkout began.',
    additional_capture: 'Another original checkout also captured a payment.',
    refund_commission_pending:
        'Principal refund confirmed; application-fee return is still pending.',
    provider_evidence_mismatch: 'The original account reported amounts that need reconciliation.',
    initialization_unknown: 'The payment provider did not confirm whether checkout was created.',
};
const money = (amount, currency) => `${currency} ${(Number(amount) / 100).toFixed(2)}`;

export default function MemberPaymentReviewPanel({ event, canManage = false }) {
    const [page, setPage] = useState(null);
    const [busy, setBusy] = useState(null);
    const [message, setMessage] = useState(null);
    const [confirm, setConfirm] = useState(null);
    const [refundIds, setRefundIds] = useState({});
    const endpoint = route('tenant.events.member-payments.index', { event: event.id });
    const load = async (url = endpoint) => {
        const response = await csrfFetch(url, { method: 'GET' });
        if (!response.ok) throw new Error('Could not load payment review.');
        const json = await response.json();
        setPage(json);
    };
    useEffect(() => {
        let current = true;
        csrfFetch(endpoint, { method: 'GET' })
            .then(async (response) => {
                if (!response.ok) throw new Error();
                const json = await response.json();
                if (current) setPage(json);
            })
            .catch(() => {
                if (current)
                    setMessage(
                        'Could not load payment review. Check your connection and try again.'
                    );
            });
        return () => {
            current = false;
        };
    }, [endpoint]);
    const act = async (source, action) => {
        if (busy) return;
        setBusy(source.id);
        setMessage(null);
        try {
            const response = await csrfFetch(
                route(`tenant.events.member-payments.${action}`, {
                    event: event.id,
                    attempt: source.id,
                }),
                {
                    method: 'POST',
                    body: JSON.stringify(
                        action === 'reconcile'
                            ? { provider_refund_id: refundIds[source.id] || null }
                            : {}
                    ),
                }
            );
            const json = await response.json();
            if (!response.ok) {
                setMessage(
                    Object.values(json.errors ?? {}).flat()[0] ??
                        json.message ??
                        'The payment still needs review.'
                );
                return;
            }
            setMessage(json.message ?? 'Original payment checked.');
            setConfirm(null);
            await load();
        } catch {
            setMessage(
                'Could not complete the check. Refresh the payment review before trying again; do not resend an uncertain refund.'
            );
        } finally {
            setBusy(null);
        }
    };
    const move = async (url) => {
        if (!url || busy) return;
        setBusy('page');
        try {
            await load(url);
        } catch {
            setMessage('Could not load that page. Please try again.');
        } finally {
            setBusy(null);
        }
    };
    return (
        <section className="border border-border bg-surface p-5" aria-label="Member payment review">
            <h3 className="text-base font-semibold text-ink">Member payment review</h3>
            <p className="mt-2 text-sm leading-6 text-ink-secondary">
                Money received and tickets issued are separate. Check the original account before
                resolving an uncertain payment or refund. History remains available when member
                benefits are paused.
            </p>
            {message && (
                <p role="status" className="mt-3 text-sm text-ink-secondary">
                    {message}
                </p>
            )}
            {!page ? (
                <p className="mt-4 text-sm text-ink-secondary">Loading payment history…</p>
            ) : (
                <>
                    {page.data.length === 0 && (
                        <p className="mt-4 text-sm text-ink-secondary">
                            No member payment attempts yet.
                        </p>
                    )}
                    <ul className="mt-4 space-y-4">
                        {page.data.map((source) => (
                            <li
                                key={source.id}
                                className="border border-border p-4 text-sm text-ink"
                            >
                                <div className="flex flex-wrap justify-between gap-3">
                                    <strong>{source.registration_name}</strong>
                                    <strong>{money(source.amount, source.currency)}</strong>
                                </div>
                                <p className="mt-2 break-all font-mono text-xs text-ink-secondary">
                                    {source.reference}
                                </p>
                                <p className="mt-2 font-medium">
                                    {LABELS[source.state] ?? 'Payment review required'}
                                </p>
                                <p className="mt-1 text-xs text-ink-secondary">
                                    {source.provider} ·{' '}
                                    {source.settlement_mode === 'platform_default'
                                        ? 'Original platform account'
                                        : 'Original organisation account'}{' '}
                                    ·{' '}
                                    {source.created_at
                                        ? new Date(source.created_at).toLocaleString()
                                        : ''}
                                </p>
                                {REASONS[source.review_reason] && (
                                    <p className="mt-2 text-ink-secondary">
                                        {REASONS[source.review_reason]}
                                    </p>
                                )}
                                {source.observed_payment && (
                                    <p className="mt-2 text-ink-secondary">
                                        Provider evidence:{' '}
                                        {String(source.observed_payment.currency ?? '')}{' '}
                                        {Number.isInteger(source.observed_payment.amount)
                                            ? (source.observed_payment.amount / 100).toFixed(2)
                                            : 'amount unverified'}
                                        . Reconcile this against the original account.
                                    </p>
                                )}
                                {source.recipient_url && (
                                    <a
                                        href={source.recipient_url}
                                        className="mt-3 inline-block text-accent underline"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Recipient verification page
                                    </a>
                                )}
                                {canManage && (
                                    <div className="mt-3 flex flex-wrap items-end gap-3">
                                        {['refund_unknown', 'refund_pending'].includes(
                                            source.state
                                        ) &&
                                            !source.refund_reference && (
                                                <Input
                                                    label="Provider refund ID"
                                                    value={refundIds[source.id] ?? ''}
                                                    maxLength={255}
                                                    onChange={(e) =>
                                                        setRefundIds({
                                                            ...refundIds,
                                                            [source.id]: e.target.value,
                                                        })
                                                    }
                                                    hint="Use the existing refund ID from the original gateway. Checking does not request another refund."
                                                />
                                            )}
                                        {!['fulfilled', 'refunded'].includes(source.state) && (
                                            <Button
                                                type="button"
                                                disabled={Boolean(busy)}
                                                onClick={() => act(source, 'reconcile')}
                                            >
                                                {source.state.startsWith('refund')
                                                    ? 'Check refund'
                                                    : 'Check original payment'}
                                            </Button>
                                        )}
                                        {source.can_refund && (
                                            <Button
                                                type="button"
                                                disabled={Boolean(busy)}
                                                onClick={() => setConfirm(source)}
                                            >
                                                Refund in full
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    {page.last_page > 1 && (
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <Button
                                disabled={Boolean(busy) || !page.prev_page_url}
                                onClick={() => move(page.prev_page_url)}
                            >
                                Previous payments
                            </Button>
                            <span className="text-sm text-ink-secondary">
                                Page {page.current_page} of {page.last_page}
                            </span>
                            <Button
                                disabled={Boolean(busy) || !page.next_page_url}
                                onClick={() => move(page.next_page_url)}
                            >
                                More payments
                            </Button>
                        </div>
                    )}
                </>
            )}
            <ConfirmModal
                open={Boolean(confirm)}
                onClose={() => !busy && setConfirm(null)}
                onConfirm={() => confirm && act(confirm, 'refund')}
                title="Request a full refund?"
                description={
                    confirm
                        ? `Return ${money(confirm.amount, confirm.currency)} for ${confirm.reference}. This cancels its ticket unless this is an additional capture. Provider fees may be retained and reduce the organisation’s balance. An uncertain refund will be held for reconciliation.`
                        : ''
                }
                confirmLabel="Request full refund"
                processing={Boolean(busy)}
                danger
            />
        </section>
    );
}
