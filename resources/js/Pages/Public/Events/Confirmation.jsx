import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    CheckCircle2,
    Clock,
    FileText,
    Download,
    Plus,
    Check,
    ListOrdered,
    XCircle,
} from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

function formatSessionTime(startsAt, endsAt) {
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    const day = start.toLocaleDateString(undefined, { weekday: 'short' });
    const time = `${start.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    return `${day}, ${time}`;
}

function TicketTab({ event, registration }) {
    const [transferring, setTransferring] = useState(false);
    // 'details' collects who it goes to; 'code' confirms it from the current
    // holder's inbox. The ticket does not move until the second step.
    const [stage, setStage] = useState('details');
    const [form, setForm] = useState({ full_name: '', email: '', phone: '' });
    const [code, setCode] = useState('');
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);

    const canTransfer = registration.status !== 'checked_in';

    const post = async (routeName, body) => {
        const response = await csrfFetch(
            route(routeName, { event: event.slug, registration: registration.id }),
            {
                method: 'POST',
                body: JSON.stringify(body),
            }
        );
        return [response, await response.json()];
    };

    const requestTransfer = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        setMessage(null);
        const [response, json] = await post('public.events.registrations.transfer', form);
        setSaving(false);
        if (!response.ok) {
            setError(json.message ?? 'Could not start this transfer.');
            return;
        }
        setMessage(json.message);
        setStage('code');
    };

    const confirmTransfer = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        const [response, json] = await post('public.events.registrations.transfer.confirm', {
            code,
        });
        setSaving(false);
        if (!response.ok) {
            setError(json.message ?? 'Could not complete this transfer.');
            return;
        }
        setTransferring(false);
        setStage('details');
        setCode('');
        setMessage(json.message);
        window.location.reload();
    };

    const cancelTransfer = () => {
        setTransferring(false);
        setStage('details');
        setCode('');
        setError(null);
        setMessage(null);
    };

    return (
        <div className="flex flex-col gap-6 sm:flex-row">
            {registration.qr_image && (
                <img
                    src={registration.qr_image}
                    alt="Your ticket QR code"
                    className="mx-auto h-40 w-40 shrink-0 border border-border p-2 sm:mx-0"
                />
            )}
            <div className="min-w-0 flex-1 text-left">
                <h2 className="text-lg font-medium text-ink">{registration.full_name}</h2>
                <p className="text-sm text-ink-secondary">{event.name}</p>
                <dl className="mt-4 space-y-2 text-[13px]">
                    {[
                        ['Entry code', registration.ticket_code],
                        ['Ticket', registration.ticket_type_name ?? 'General admission'],
                        ['Status', registration.status],
                        ...(registration.seat_label
                            ? [['Seat', `${registration.seat_label} · ${registration.room_name}`]]
                            : []),
                    ].map(([k, v]) => (
                        <div key={k} className="flex justify-between border-b border-border pb-2">
                            <dt className="text-ink-secondary">{k}</dt>
                            <dd className="font-mono text-ink">{v}</dd>
                        </div>
                    ))}
                </dl>

                <div className="mt-4 flex gap-2">
                    <button
                        disabled
                        className="inline-flex h-control items-center gap-1.5 border border-border px-4 text-sm text-ink-tertiary opacity-60"
                    >
                        <Download className="h-4 w-4" strokeWidth={1.75} />
                        Add to wallet
                    </button>
                    {canTransfer && (
                        <button
                            onClick={() => setTransferring(!transferring)}
                            className="inline-flex h-control items-center border border-border px-4 text-sm text-ink hover:border-accent"
                        >
                            Transfer
                        </button>
                    )}
                </div>

                {message && <p className="mt-3 text-[13px] text-ink-secondary">{message}</p>}
                {error && <p className="mt-3 text-[13px] text-danger-fg">{error}</p>}

                {transferring && stage === 'details' && (
                    <form
                        onSubmit={requestTransfer}
                        className="mt-4 space-y-2.5 border border-border p-4"
                    >
                        <p className="text-xs text-ink-secondary">
                            Give this ticket to someone else. We'll email a code to your address
                            first, so nobody can move it without you.
                        </p>
                        <input
                            value={form.full_name}
                            onChange={(e) => setForm({ ...form, full_name: e.target.value })}
                            placeholder="Their full name"
                            required
                            className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                        />
                        <input
                            type="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                            placeholder="Their email"
                            required
                            className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                        />
                        <input
                            value={form.phone}
                            onChange={(e) => setForm({ ...form, phone: e.target.value })}
                            placeholder="Their phone (optional)"
                            className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                        />
                        <div className="flex gap-2">
                            <button
                                type="submit"
                                disabled={saving}
                                className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink"
                            >
                                {saving ? 'Sending code…' : 'Send me a code'}
                            </button>
                            <button
                                type="button"
                                onClick={cancelTransfer}
                                className="inline-flex h-control items-center border border-border px-4 text-sm text-ink"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                )}

                {transferring && stage === 'code' && (
                    <form
                        onSubmit={confirmTransfer}
                        className="mt-4 space-y-2.5 border border-border p-4"
                    >
                        <p className="text-xs text-ink-secondary">
                            Enter the code we emailed you to hand this ticket to{' '}
                            <span className="text-ink">{form.full_name}</span> ({form.email}). This
                            cannot be undone.
                        </p>
                        <input
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            placeholder="6-digit code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            required
                            className="w-full border border-border bg-surface px-3 py-2 font-mono text-[15px] tracking-[0.3em] text-ink focus:border-accent focus:outline-none"
                        />
                        <div className="flex gap-2">
                            <button
                                type="submit"
                                disabled={saving}
                                className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink"
                            >
                                {saving ? 'Transferring…' : 'Complete transfer'}
                            </button>
                            <button
                                type="button"
                                onClick={cancelTransfer}
                                className="inline-flex h-control items-center border border-border px-4 text-sm text-ink"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}

function AgendaTab({ event, registration, agendaIds, setAgendaIds }) {
    const toggle = async (session) => {
        const inAgenda = agendaIds.includes(session.id);
        const isFull = session.capacity !== null && session.signup_count >= session.capacity;
        if (!inAgenda && isFull) return;

        const url = route(inAgenda ? 'public.events.agenda.remove' : 'public.events.agenda.add', {
            event: event.slug,
            registration: registration.id,
            session: session.id,
        });
        await csrfFetch(url, { method: inAgenda ? 'DELETE' : 'POST' });
        setAgendaIds((prev) =>
            inAgenda ? prev.filter((id) => id !== session.id) : [...prev, session.id]
        );
    };

    return (
        <div className="text-left">
            <ul className="mb-4 space-y-2">
                {event.sessions.map((session) => {
                    const added = agendaIds.includes(session.id);
                    const isFull =
                        session.capacity !== null && session.signup_count >= session.capacity;
                    return (
                        <li
                            key={session.id}
                            className="flex items-center gap-4 border border-border px-4 py-3"
                        >
                            <span className="w-24 shrink-0 font-mono text-[12px] text-ink-secondary">
                                {formatSessionTime(session.starts_at, session.ends_at)}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[13.5px] text-ink">{session.title}</div>
                                <div className="text-xs text-ink-secondary">
                                    {session.location}
                                    {session.capacity && !added
                                        ? `${session.location ? ' · ' : ''}${Math.max(session.capacity - session.signup_count, 0)} seats left`
                                        : ''}
                                </div>
                            </div>
                            <button
                                onClick={() => toggle(session)}
                                disabled={!added && isFull}
                                className={`flex shrink-0 items-center gap-1 border px-2.5 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-50 ${added ? 'border-accent text-accent' : 'border-border text-ink-secondary'}`}
                            >
                                {added ? (
                                    <Check className="h-3 w-3" strokeWidth={2} />
                                ) : (
                                    <Plus className="h-3 w-3" strokeWidth={2} />
                                )}
                                {added ? 'In my day' : isFull ? 'Full' : 'Add'}
                            </button>
                        </li>
                    );
                })}
            </ul>
            <a
                href={route('public.events.agenda.ics', {
                    event: event.slug,
                    registration: registration.id,
                })}
                className="inline-flex h-control items-center gap-1.5 border border-border px-3 text-xs text-ink-secondary hover:border-accent hover:text-accent"
            >
                <Download className="h-3.5 w-3.5" strokeWidth={1.75} />
                Add my day to calendar
            </a>
        </div>
    );
}

const HELP_TYPES = [
    ['refreshment', 'Water'],
    ['assistance', 'Help finding my seat'],
    ['technical', 'Technical'],
    ['accessibility', 'Step-free access'],
    ['medical', 'First aid'],
    ['other', 'Something else'],
];

function HelpTab({ event, registration }) {
    const [type, setType] = useState('refreshment');
    const [location, setLocation] = useState(
        registration.seat_label ? `${registration.seat_label}, ${registration.room_name}` : ''
    );
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [sent, setSent] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        const response = await csrfFetch(
            route('public.events.service-requests.store', {
                event: event.slug,
                registration: registration.id,
            }),
            {
                method: 'POST',
                body: JSON.stringify({ type, location: location || null, note: note || null }),
            }
        );
        const json = await response.json();
        setSaving(false);
        setSent(json);
    };

    if (sent) {
        return (
            <div className="border border-border p-5 text-left">
                <div className="flex items-center gap-2 text-ink">
                    <Check className="h-4 w-4 text-accent" strokeWidth={2} />
                    <b>Someone is coming</b>
                </div>
                <p className="mt-2 text-[13px] text-ink-secondary">
                    Raised at{' '}
                    {new Date(sent.created_at).toLocaleTimeString(undefined, {
                        hour: 'numeric',
                        minute: '2-digit',
                    })}
                    {location ? ` for ${location}` : ''}. Usually under five minutes.
                </p>
                <button
                    onClick={() => setSent(null)}
                    className="mt-3 text-xs text-ink-secondary underline hover:text-accent"
                >
                    Raise another request
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-4 text-left">
            <p className="text-sm text-ink-secondary">Goes straight to the floor team.</p>

            <div>
                <label className="mb-1.5 block text-sm font-medium text-ink">
                    What do you need
                </label>
                <div className="flex flex-wrap gap-2">
                    {HELP_TYPES.map(([value, label]) => (
                        <button
                            type="button"
                            key={value}
                            onClick={() => setType(value)}
                            className={`border px-3 py-1.5 text-xs font-medium ${type === value ? 'border-accent bg-accent-soft text-accent' : 'border-border text-ink-secondary'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium text-ink">Where you are</label>
                <input
                    value={location}
                    onChange={(e) => setLocation(e.target.value)}
                    placeholder="e.g. Grand Ballroom, near the entrance"
                    className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                />
            </div>

            <div>
                <label className="mb-1.5 block text-sm font-medium text-ink">Anything else</label>
                <textarea
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={2}
                    placeholder="Optional"
                    className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                />
            </div>

            <button
                type="submit"
                disabled={saving}
                className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink"
            >
                {saving ? 'Sending…' : 'Ask for help'}
            </button>
        </form>
    );
}

function DownloadsTab({ materials }) {
    if (materials.length === 0) {
        return (
            <p className="text-left text-sm text-ink-secondary">
                Materials released by the organizers will appear here.
            </p>
        );
    }

    return (
        <ul className="divide-y divide-border border border-border text-left">
            {materials.map((m) => (
                <li key={m.id} className="flex items-center justify-between gap-3 px-4 py-3">
                    <div className="flex min-w-0 items-center gap-2.5">
                        <FileText
                            className="h-4 w-4 shrink-0 text-ink-secondary"
                            strokeWidth={1.5}
                        />
                        <div className="min-w-0">
                            <div className="truncate text-[13.5px] text-ink">{m.title}</div>
                            <div className="text-xs text-ink-secondary">
                                {m.remaining_attempts} of your attempts left
                            </div>
                        </div>
                    </div>
                    {m.remaining_attempts > 0 ? (
                        <a
                            href={m.download_url}
                            className="flex shrink-0 items-center gap-1.5 border border-border px-3 py-1.5 text-xs text-ink hover:border-accent hover:text-accent"
                        >
                            <Download className="h-3.5 w-3.5" strokeWidth={1.75} />
                            Download
                        </a>
                    ) : (
                        <span className="shrink-0 text-xs text-ink-secondary">
                            No attempts left
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

const STATUS_NOTICE = {
    pending_payment: {
        icon: Clock,
        color: 'text-warning-fg',
        title: 'Payment pending',
        description: (event) =>
            `We're confirming your payment for ${event.name}. This page will show your ticket once it's confirmed — check your email shortly.`,
    },
    pending_approval: {
        icon: Clock,
        color: 'text-warning-fg',
        title: 'Awaiting approval',
        description: (event) =>
            `The organizers of ${event.name} review registrations before confirming a spot. We'll email you as soon as yours is reviewed.`,
    },
    waitlisted: {
        icon: ListOrdered,
        color: 'text-warning-fg',
        title: "You're on the waitlist",
        description: (event, registration) =>
            `${event.name} is at capacity — you're number ${registration.waitlist_position} in line. We'll email you the moment a spot opens up.`,
    },
    rejected: {
        icon: XCircle,
        color: 'text-danger-fg',
        title: 'Registration not approved',
        description: (event, registration) =>
            registration.approval_note ||
            `The organizers of ${event.name} weren't able to confirm this registration.`,
    },
    cancelled: {
        icon: XCircle,
        color: 'text-danger-fg',
        title: 'Registration cancelled',
        description: (event) => `This registration for ${event.name} has been cancelled.`,
    },
};

export default function Confirmation({
    event,
    registration,
    materials = [],
    canRequestHelp = false,
}) {
    const notice = STATUS_NOTICE[registration.status];
    const [tab, setTab] = useState('ticket');
    const [agendaIds, setAgendaIds] = useState(registration.agenda_session_ids ?? []);

    // Nothing on these tabs is usable until the ticket exists, and it does not
    // exist until the address behind a free registration has been confirmed.
    const verified = registration.email_verified !== false;
    const tabs = verified ? [['ticket', 'My ticket']] : [];
    if (verified && event.sessions.length > 0) tabs.push(['agenda', 'My day']);
    // Only offer what can actually be acted on: help while the event is
    // running, downloads once something has been released.
    if (verified && canRequestHelp) tabs.push(['help', 'Get help']);
    if (verified && materials.length > 0) tabs.push(['downloads', 'Downloads']);

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                {notice ? (
                    <div className="text-center">
                        <notice.icon
                            className={`mx-auto h-10 w-10 ${notice.color}`}
                            strokeWidth={1.5}
                        />
                        <h1 className="mt-5 text-2xl font-normal tracking-tight text-ink">
                            {notice.title}
                        </h1>
                        <p className="mt-3 text-[13.5px] text-ink-secondary">
                            {notice.description(event, registration)}
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="text-center">
                            <CheckCircle2
                                className="mx-auto h-10 w-10 text-accent"
                                strokeWidth={1.5}
                            />
                            <h1 className="mt-5 text-2xl font-normal tracking-tight text-ink">
                                {verified
                                    ? `You're confirmed, ${registration.full_name}!`
                                    : `Almost there, ${registration.full_name}`}
                            </h1>
                            <p className="mt-3 text-[13.5px] text-ink-secondary">
                                {verified
                                    ? `Your ticket for ${event.name} is ready. We've also emailed it to you.`
                                    : `We've emailed ${registration.email}. Confirm your address there and your ticket is issued straight away.`}
                            </p>
                            {!verified && (
                                <p className="mt-5 border border-border bg-surface px-5 py-4 text-[13px] text-ink-secondary">
                                    Can't find it? Check your spam folder, or register again with
                                    the same address and we'll send the link once more.
                                </p>
                            )}
                        </div>

                        <nav
                            className={`mt-9 mb-7 flex justify-center gap-1 ${tabs.length > 0 ? 'border-b border-border' : ''}`}
                        >
                            {tabs.map(([key, label]) => (
                                <button
                                    key={key}
                                    onClick={() => setTab(key)}
                                    className={`-mb-px border-b px-3.5 py-2.5 text-[13px] ${tab === key ? 'border-accent text-accent' : 'border-transparent text-ink-secondary hover:text-ink'}`}
                                >
                                    {label}
                                </button>
                            ))}
                        </nav>

                        {verified && tab === 'ticket' && (
                            <TicketTab event={event} registration={registration} />
                        )}
                        {tab === 'agenda' && (
                            <AgendaTab
                                event={event}
                                registration={registration}
                                agendaIds={agendaIds}
                                setAgendaIds={setAgendaIds}
                            />
                        )}
                        {tab === 'help' && <HelpTab event={event} registration={registration} />}
                        {tab === 'downloads' && <DownloadsTab materials={materials} />}
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
