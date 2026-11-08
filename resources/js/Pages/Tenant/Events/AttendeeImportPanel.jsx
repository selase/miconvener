import { useEffect, useRef, useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';

const fields = [
    'full_name',
    'email',
    'phone',
    'ticket_type',
    'external_reference',
    'payment_status',
    'external_amount',
    'currency',
];
const payment = {
    paid_elsewhere: 'Paid elsewhere',
    complimentary: 'Complimentary',
    free: 'Imported free admission',
};
const input = 'w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink';

export default function AttendeeImportPanel({ event, onImported = () => {} }) {
    const [open, setOpen] = useState(false);
    const [file, setFile] = useState(null);
    const [mapping, setMapping] = useState({});
    const [ticketMapping, setTicketMapping] = useState({});
    const [source, setSource] = useState('csv');
    const [batch, setBatch] = useState(null);
    const [recent, setRecent] = useState([]);
    const [rows, setRows] = useState([]);
    const [recipients, setRecipients] = useState([]);
    const [confirmInvite, setConfirmInvite] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [page, setPage] = useState(0);
    const confirmKey = useRef(null);
    const previewKeys = useRef({});
    const inviteKey = useRef(null);
    const base = `/events/${event.id}/attendee-imports`;
    const receive = (data) => {
        setBatch(data);
        if (data.status === 'preview') {
            previewKeys.current[data.id] ||= crypto.randomUUID();
            confirmKey.current = previewKeys.current[data.id];
            setRows(data.rows.filter((row) => row.status === 'valid').map((row) => row.id));
        }
    };
    const request = async (path, options = {}, upload = null) => {
        const response = upload
            ? await csrfFetchFormData(path, upload)
            : await csrfFetch(path, options);
        const data = await response.json();
        if (!response.ok)
            throw new Error(
                Object.values(data.errors || {})
                    .flat()
                    .join(' ') ||
                    data.message ||
                    'Request failed. Refresh and try again.'
            );
        return data;
    };
    const act = async (operation) => {
        setBusy(true);
        setError('');
        try {
            await operation();
        } catch (failure) {
            setError(
                failure.message || 'Connection interrupted. Refresh the result before retrying.'
            );
        } finally {
            setBusy(false);
        }
    };
    useEffect(() => {
        if (open)
            request(base)
                .then((data) => setRecent(data.imports))
                .catch((failure) => setError(failure.message));
    }, [open]);
    useEffect(() => {
        if (!open || !batch || !['queued', 'running'].includes(batch.status)) return undefined;
        const timer = setTimeout(() => {
            request(`${base}/${batch.id}`)
                .then((data) => {
                    receive(data);
                    if (data.status === 'completed') onImported();
                })
                .catch((failure) => setError(failure.message));
        }, 2000);
        return () => clearTimeout(timer);
    }, [open, batch]);
    const toggle = (id, selected, setter) =>
        setter(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id]);
    const preview = () =>
        act(async () => {
            const body = new FormData();
            body.append('file', file);
            body.append('source_namespace', source);
            Object.entries(mapping)
                .filter(([, value]) => value)
                .forEach(([key, value]) => body.append(`mapping[${key}]`, value));
            Object.entries(ticketMapping)
                .filter(([, value]) => value)
                .forEach(([key, value]) => body.append(`ticket_mapping[${key}]`, value));
            receive(await request(`${base}/preview`, {}, body));
            setPage(0);
            setRecipients([]);
        });
    const confirm = () =>
        act(async () => {
            receive(
                await request(`${base}/${batch.id}/confirm`, {
                    method: 'POST',
                    body: JSON.stringify({ operation_key: confirmKey.current, rows }),
                })
            );
        });
    const invitations = batch?.invitations || {};
    const visible = batch?.rows?.slice(page * 25, (page + 1) * 25) || [];
    const created =
        batch?.rows?.filter((row) => row.status === 'created' && row.registration_id) || [];
    return (
        <>
            <Button onClick={() => setOpen(true)}>Import attendees</Button>
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title="Import attendees"
                className="max-w-4xl"
                fullScreenOnMobile
            >
                <div className="space-y-4 min-w-0">
                    <p className="text-sm text-ink-muted">
                        Import confirmed external attendees without changing native registration,
                        prices or collection settings. Names and emails are required. Shared emails
                        and member-restricted admission need correction before import.
                    </p>
                    <p className="text-sm text-ink-muted">
                        External payments are organiser declarations. They create no MiConvener
                        receipt, commission, refund or payout. Missing profile answers and consent
                        are never invented.
                    </p>
                    {error && (
                        <p
                            role="alert"
                            className="rounded border border-danger-border p-3 text-sm text-danger-fg"
                        >
                            {error}
                        </p>
                    )}
                    {!batch && (
                        <>
                            <a className="text-sm text-accent underline" href={`${base}/template`}>
                                Download CSV template
                            </a>
                            <label className="block text-sm">
                                Source namespace
                                <input
                                    className={input}
                                    value={source}
                                    maxLength={100}
                                    onChange={(e) => setSource(e.target.value)}
                                />
                            </label>
                            <p className="text-xs text-ink-muted">
                                Keep this source name for retries from the same registration system.
                                References are scoped to this event.
                            </p>
                            <label className="block text-sm">
                                CSV file
                                <input
                                    className={input}
                                    type="file"
                                    accept=".csv,text/csv"
                                    onChange={(e) => setFile(e.target.files[0] || null)}
                                />
                            </label>
                            <p className="text-xs text-ink-muted">
                                UTF-8 CSV · 5 MiB · 2,000 rows · 20 columns. Files are parsed
                                privately and not retained.
                            </p>
                            <details>
                                <summary className="cursor-pointer text-sm">
                                    Map custom column headers
                                </summary>
                                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    {fields.map((field) => (
                                        <label key={field} className="block text-sm">
                                            {field}
                                            <input
                                                className={input}
                                                placeholder={field}
                                                value={mapping[field] || ''}
                                                onChange={(e) =>
                                                    setMapping({
                                                        ...mapping,
                                                        [field]: e.target.value,
                                                    })
                                                }
                                            />
                                        </label>
                                    ))}
                                </div>
                            </details>
                            {(event.ticket_types || []).length > 0 && (
                                <details>
                                    <summary className="cursor-pointer text-sm">
                                        Resolve ambiguous ticket labels
                                    </summary>
                                    {(event.ticket_types || []).map((type) => (
                                        <label className="mt-3 block text-sm" key={type.id}>
                                            CSV label to map to {type.name}
                                            <input
                                                className={input}
                                                onChange={(e) =>
                                                    setTicketMapping((current) => {
                                                        const next = Object.fromEntries(
                                                            Object.entries(current).filter(
                                                                ([, id]) => id !== type.id
                                                            )
                                                        );
                                                        if (e.target.value)
                                                            next[e.target.value] = type.id;
                                                        return next;
                                                    })
                                                }
                                            />
                                        </label>
                                    ))}
                                </details>
                            )}
                            <Button disabled={!file || busy} onClick={preview}>
                                {busy ? 'Reading CSV…' : 'Preview CSV'}
                            </Button>
                            {recent.length > 0 && (
                                <div className="border-t border-border pt-3">
                                    <p className="text-sm font-medium">Recent imports</p>
                                    {recent.map((item) => (
                                        <button
                                            key={item.id}
                                            className="block py-2 text-sm text-accent underline"
                                            onClick={() =>
                                                act(async () => {
                                                    receive(await request(`${base}/${item.id}`));
                                                    setPage(0);
                                                })
                                            }
                                        >
                                            {new Date(item.created_at).toLocaleString()} ·{' '}
                                            {item.status}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                    {batch && (
                        <>
                            <div
                                role="status"
                                className="rounded border border-border bg-surface p-3 text-sm"
                            >
                                <strong>{batch.status}</strong> ·{' '}
                                {Object.entries(batch.counts || {})
                                    .map(([status, count]) => `${count} ${status}`)
                                    .join(' · ')}
                                <p>{batch.message}</p>
                                <p>
                                    {Object.keys(invitations).length === 0
                                        ? 'No invitations sent.'
                                        : Object.entries(invitations)
                                              .map(([status, count]) => `${count} ${status}`)
                                              .join(' · ')}
                                </p>
                            </div>
                            {batch.status === 'preview' && (
                                <div className="space-y-2 text-sm">
                                    <p>
                                        Capacity: {event.capacity ?? 'unlimited'} (ticket limits
                                        also apply). Availability and plan allowance are checked
                                        again while admitting rows.
                                    </p>
                                    <p>
                                        Existing registrations and historical paid payment links
                                        reserve places, including cancelled or refunded paid
                                        registrations. Review these with the platform or increase
                                        actual capacity if appropriate. Reservations have no
                                        automatic expiry.
                                    </p>
                                    <p>
                                        Confirmation creates tickets and normal registration
                                        webhooks may notify connected systems. No invitations, SMS,
                                        subscriptions or check-ins are created.
                                    </p>
                                    <p>
                                        Preview expires{' '}
                                        {new Date(batch.expires_at).toLocaleString()}. Correct
                                        invalid/conflicting rows and upload a fresh preview;
                                        existing registrations are never overwritten.
                                    </p>
                                </div>
                            )}
                            <div className="overflow-x-auto rounded border border-border">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="bg-surface-alt">
                                            <th className="p-2">Select</th>
                                            <th className="p-2">Line / attendee</th>
                                            <th className="p-2">Admission source</th>
                                            <th className="p-2">Result</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visible.map((row) => (
                                            <tr key={row.id} className="border-t border-border">
                                                <td className="p-2">
                                                    {batch.status === 'preview' &&
                                                        row.status === 'valid' && (
                                                            <input
                                                                type="checkbox"
                                                                aria-label={`Import line ${row.line}`}
                                                                checked={rows.includes(row.id)}
                                                                onChange={() =>
                                                                    toggle(row.id, rows, setRows)
                                                                }
                                                            />
                                                        )}
                                                    {row.status === 'created' && (
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`Invite ${row.invitation_recipient || row.data?.email || `line ${row.line}`}`}
                                                            checked={recipients.includes(
                                                                row.registration_id
                                                            )}
                                                            onChange={() => {
                                                                toggle(
                                                                    row.registration_id,
                                                                    recipients,
                                                                    setRecipients
                                                                );
                                                                setConfirmInvite(false);
                                                                inviteKey.current = null;
                                                            }}
                                                        />
                                                    )}
                                                </td>
                                                <td className="p-2">
                                                    <p>
                                                        {row.line} ·{' '}
                                                        {row.data?.full_name ||
                                                            'Transient details removed'}
                                                    </p>
                                                    <p className="break-all">{row.data?.email}</p>
                                                </td>
                                                <td className="p-2">
                                                    {payment[row.data?.payment_status] || '—'}
                                                    {row.data?.external_amount != null && (
                                                        <p>
                                                            {row.data.currency}{' '}
                                                            {(
                                                                row.data.external_amount / 100
                                                            ).toFixed(2)}{' '}
                                                            declared
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="p-2">
                                                    {row.status}
                                                    {(row.errors || []).map((message, i) => (
                                                        <p
                                                            key={i}
                                                            className="max-w-sm text-danger-fg"
                                                        >
                                                            {message}
                                                        </p>
                                                    ))}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <Button disabled={page === 0} onClick={() => setPage(page - 1)}>
                                    Previous
                                </Button>
                                <span className="text-xs">
                                    Page {page + 1} of{' '}
                                    {Math.max(1, Math.ceil((batch.rows?.length || 0) / 25))}
                                </span>
                                <Button
                                    disabled={(page + 1) * 25 >= (batch.rows?.length || 0)}
                                    onClick={() => setPage(page + 1)}
                                >
                                    Next
                                </Button>
                            </div>
                            {batch.status === 'preview' && (
                                <Button disabled={busy || rows.length === 0} onClick={confirm}>
                                    Confirm {rows.length}{' '}
                                    {rows.length === 1 ? 'attendee' : 'attendees'}
                                </Button>
                            )}
                            {['queued', 'running', 'stopped'].includes(batch.status) && (
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        act(async () =>
                                            receive(
                                                await request(`${base}/${batch.id}/resume`, {
                                                    method: 'POST',
                                                })
                                            )
                                        )
                                    }
                                >
                                    Resume untouched rows
                                </Button>
                            )}
                            {created.length > 0 && (
                                <div className="space-y-3 border-t border-border pt-3">
                                    <p className="text-sm">
                                        Select imported attendees above to invite. Invitations are
                                        transactional and unmetered. Publish the event before
                                        sending. Sent or uncertain invitations are never
                                        automatically resent.
                                    </p>
                                    {confirmInvite ? (
                                        <div className="space-y-2">
                                            <p className="text-sm">
                                                Send access invitations only to:
                                            </p>
                                            <ul className="text-sm break-all">
                                                {created
                                                    .filter((row) =>
                                                        recipients.includes(row.registration_id)
                                                    )
                                                    .map((row) => (
                                                        <li key={row.id}>
                                                            {row.invitation_recipient ||
                                                                row.data?.email ||
                                                                `Attendee on line ${row.line}`}
                                                        </li>
                                                    ))}
                                            </ul>
                                            <Button
                                                disabled={busy}
                                                onClick={() =>
                                                    act(async () => {
                                                        receive(
                                                            await request(
                                                                `${base}/${batch.id}/invite`,
                                                                {
                                                                    method: 'POST',
                                                                    body: JSON.stringify({
                                                                        operation_key:
                                                                            inviteKey.current,
                                                                        registrations: recipients,
                                                                        revisions:
                                                                            Object.fromEntries(
                                                                                created
                                                                                    .filter((row) =>
                                                                                        recipients.includes(
                                                                                            row.registration_id
                                                                                        )
                                                                                    )
                                                                                    .map((row) => [
                                                                                        row.registration_id,
                                                                                        row.invitation_revision,
                                                                                    ])
                                                                            ),
                                                                    }),
                                                                }
                                                            )
                                                        );
                                                        setConfirmInvite(false);
                                                    })
                                                }
                                            >
                                                Send {recipients.length} access invitation
                                                {recipients.length === 1 ? '' : 's'}
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            disabled={
                                                busy ||
                                                recipients.length === 0 ||
                                                event.status !== 'published'
                                            }
                                            onClick={() => {
                                                inviteKey.current ||= crypto.randomUUID();
                                                setConfirmInvite(true);
                                            }}
                                        >
                                            Review selected invitations
                                        </Button>
                                    )}
                                    <Button
                                        disabled={busy}
                                        onClick={() =>
                                            act(async () =>
                                                receive(
                                                    await request(
                                                        `${base}/${batch.id}/retry-invitations`,
                                                        { method: 'POST' }
                                                    )
                                                )
                                            )
                                        }
                                    >
                                        Retry untouched invitations
                                    </Button>
                                    <p className="text-xs text-ink-muted">
                                        An interrupted email can have been delivered. Check the mail
                                        provider before any manual resend. Attendee and ticket
                                        creation remain safe to retry.
                                    </p>
                                </div>
                            )}
                            <a
                                className="text-sm text-accent underline"
                                href={`${base}/${batch.id}/export`}
                            >
                                Download private row results
                            </a>
                            <Button
                                disabled={busy}
                                onClick={() => {
                                    setBatch(null);
                                    setFile(null);
                                    setRecipients([]);
                                    setPage(0);
                                }}
                            >
                                Start a fresh preview
                            </Button>
                            <Button
                                disabled={busy}
                                onClick={() =>
                                    act(async () => receive(await request(`${base}/${batch.id}`)))
                                }
                            >
                                Refresh result
                            </Button>
                        </>
                    )}
                </div>
            </Modal>
        </>
    );
}
