import { useCallback, useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import { sendJson } from '@/lib/dues';

export function messageStatus(status) {
    return (
        {
            draft: 'Draft',
            scheduled: 'Scheduled',
            queued: 'Queued',
            sending: 'Processing',
            completed: 'Processing finished',
            partial: 'Needs review',
            paused: 'Paused',
            cancelled: 'Cancelled',
        }[status] ?? status
    );
}
export function messageTime(value, timezone) {
    return value ? new Date(value).toLocaleString(undefined, { timeZone: timezone }) : '—';
}
export default function MessageDetail({
    id,
    onClose,
    onEdit,
    onChanged,
    timezone,
    reconciliationUrl = null,
}) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const load = useCallback(
        async (url) => {
            const result = await sendJson(
                'GET',
                url ?? route('tenant.community.messages.show', { message: id })
            );
            if (result.ok) {
                setData(result.body);
                setError(null);
            } else setError(result.message);
        },
        [id]
    );
    useEffect(() => {
        load();
    }, [load]);
    const cancel = async () => {
        setBusy(true);
        const result = await sendJson(
            'POST',
            route('tenant.community.messages.cancel', { message: id })
        );
        setBusy(false);
        if (result.ok) {
            setConfirm(false);
            load();
            onChanged();
        } else setError(result.message);
    };
    const message = data?.message;
    return (
        <Modal
            open
            onClose={() => !busy && onClose()}
            title={message?.subject ?? 'Announcement history'}
            className="max-w-3xl"
            fullScreenOnMobile
        >
            {error && (
                <p role="alert" className="mb-4 text-danger-fg">
                    {error} <button onClick={() => load()}>Retry</button>
                </p>
            )}
            {!data && !error && <p role="status">Loading announcement…</p>}
            {message && (
                <div className="space-y-5">
                    <div className="flex flex-wrap gap-2 text-sm">
                        <span className="rounded-md border border-border px-2 py-1 font-medium text-ink">
                            {messageStatus(message.status)}
                        </span>
                        <span className="px-2 py-1 text-ink-secondary">
                            {message.channels.join(' · ')}
                        </span>
                    </div>
                    <p className="text-sm text-ink-secondary">
                        Audience: {message.audience.kind.replaceAll('_', ' ')}.{' '}
                        {message.scheduled_at
                            ? `Scheduled ${messageTime(message.scheduled_at, timezone)} · ${timezone}`
                            : 'Send now'}
                    </p>
                    <p className="whitespace-pre-wrap break-words rounded-md border border-border p-4 text-sm text-ink">
                        {message.body}
                    </p>
                    {message.status === 'paused' && (
                        <p
                            role="status"
                            className="border border-border p-3 text-sm text-ink-secondary"
                        >
                            Processing paused because permissions, the plan, the selected groups or
                            the feature’s availability changed. Ask your organisation owner to
                            review it. Cancel this announcement before creating a replacement.
                        </p>
                    )}
                    <p className="text-sm text-ink-secondary">
                        Accepted means the provider accepted the request. It does not confirm inbox
                        delivery or that the announcement was read. Credits remain held for
                        uncertain outcomes until an application operator verifies provider evidence.
                        There is no automatic resend.
                    </p>
                    {reconciliationUrl && (
                        <a className="text-sm text-ink underline" href={reconciliationUrl}>
                            Review uncertain outcomes
                        </a>
                    )}
                    <h3 className="font-semibold text-ink">Channel outcomes</h3>
                    <ul className="divide-y divide-border">
                        {data.deliveries.data.map((delivery) => (
                            <li
                                key={delivery.id}
                                className="flex flex-wrap justify-between gap-2 py-3 text-sm"
                            >
                                <span className="font-medium text-ink">
                                    {delivery.channel} · {delivery.status}
                                </span>
                                <span className="break-all text-ink-secondary">
                                    {delivery.reason?.replaceAll('_', ' ')}
                                    {delivery.provider_reference
                                        ? ` · ${delivery.provider_reference}`
                                        : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {!data.deliveries.data.length && (
                        <p className="text-sm text-ink-secondary">
                            {['completed', 'partial', 'cancelled'].includes(message.status)
                                ? 'No recipients were selected for this announcement.'
                                : 'Recipients will be selected when processing begins.'}
                        </p>
                    )}
                    <div className="flex flex-wrap gap-3">
                        {data.deliveries.prev_page_url && (
                            <Button onClick={() => load(data.deliveries.prev_page_url)}>
                                Previous outcomes
                            </Button>
                        )}
                        {data.deliveries.next_page_url && (
                            <Button onClick={() => load(data.deliveries.next_page_url)}>
                                More outcomes
                            </Button>
                        )}
                        <Button onClick={() => load()}>Refresh status</Button>
                        {message.status === 'draft' && (
                            <Button onClick={() => onEdit(message)}>Edit draft</Button>
                        )}
                        {['draft', 'scheduled', 'queued', 'sending', 'paused'].includes(
                            message.status
                        ) && (
                            <Button disabled={busy} onClick={() => setConfirm(true)}>
                                Cancel announcement
                            </Button>
                        )}
                    </div>
                    {confirm && (
                        <div className="space-y-3 border border-border p-4">
                            <p className="text-sm text-ink">
                                Stop untouched deliveries? Anything already submitted cannot be
                                recalled.
                            </p>
                            <Button disabled={busy} onClick={cancel}>
                                {busy ? 'Cancelling…' : 'Confirm cancellation'}
                            </Button>
                            <Button disabled={busy} onClick={() => setConfirm(false)}>
                                Keep announcement
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}
