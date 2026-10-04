import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, Bell, KeyRound, MapPin, ScanLine } from 'lucide-react';
import CheckInPanel from '@/Pages/Tenant/Events/CheckInPanel';
import csrfFetch from '@/lib/csrfFetch';

const TYPE_LABEL = {
    refreshment: 'Water / refreshments',
    assistance: 'General assistance',
    technical: 'Technical issue',
    accessibility: 'Step-free / accessibility',
    medical: 'Medical / first aid',
    other: 'Something else',
};

const ACTIVE = ['open', 'acknowledged', 'in_progress'];

function Shell({ title, children }) {
    return (
        <div className="flex min-h-screen flex-col bg-canvas font-console text-ink">
            <Head title={title} />
            {children}
        </div>
    );
}

function Closed({ event }) {
    return (
        <Shell title="Staff link closed">
            <main className="m-auto max-w-sm px-6 text-center">
                <h1 className="text-lg font-semibold">This staff link is closed</h1>
                <p className="mt-2 text-sm text-ink-secondary">
                    {event.name ? `${event.name} is over, or ` : ''}the organizer has switched this
                    link off. Ask them for a new one if you still need it.
                </p>
            </main>
        </Shell>
    );
}

function Pin({ token, link, event }) {
    const form = useForm({ pin: '' });

    return (
        <Shell title={`${event.name} · staff`}>
            <main className="m-auto w-full max-w-xs px-6">
                <KeyRound className="mx-auto h-8 w-8 text-ink-tertiary" strokeWidth={1.5} />
                <h1 className="mt-3 text-center text-lg font-semibold">{event.name}</h1>
                <p className="mt-1 text-center text-sm text-ink-secondary">
                    {link.name}. Enter the PIN the organizer gave you.
                </p>
                <form
                    className="mt-6 space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('public.staff.unlock', { token }));
                    }}
                >
                    <input
                        type="password"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        value={form.data.pin}
                        onChange={(e) => form.setData('pin', e.target.value)}
                        className="w-full rounded-md border border-border bg-surface px-3 py-3 text-center text-xl tracking-[0.5em] text-ink focus:border-accent focus:outline-none"
                        aria-label="PIN"
                    />
                    {form.errors.pin && (
                        <p className="text-center text-sm text-danger-fg">{form.errors.pin}</p>
                    )}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-md bg-accent px-4 py-3 text-sm font-semibold text-accent-ink disabled:opacity-50"
                    >
                        Open
                    </button>
                </form>
            </main>
        </Shell>
    );
}

/**
 * A short chime and a buzz, so a request is noticed by someone whose phone is
 * in their hand at a busy door rather than only by someone watching it.
 */
function alertNewRequest(isMedical) {
    try {
        navigator.vibrate?.(isMedical ? [300, 120, 300, 120, 300] : [200]);
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;
        const ctx = new AudioContext();
        const tone = ctx.createOscillator();
        const gain = ctx.createGain();
        tone.frequency.value = isMedical ? 880 : 660;
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
        tone.connect(gain).connect(ctx.destination);
        tone.start();
        tone.stop(ctx.currentTime + 0.6);
    } catch {
        // Sound and vibration are a courtesy; the banner is what matters.
    }
}

function Requests({ token, onCount }) {
    const [requests, setRequests] = useState([]);
    const [arrived, setArrived] = useState(null);
    const seen = useRef(null);

    const load = useCallback(async () => {
        const response = await csrfFetch(route('public.staff.requests', { token }));
        if (!response.ok) return;
        const data = await response.json();
        const openIds = data.filter((r) => r.status === 'open').map((r) => r.id);

        // The first load is what was already waiting, not something new.
        if (seen.current !== null) {
            const fresh = data.find((r) => r.status === 'open' && !seen.current.has(r.id));
            if (fresh) {
                setArrived(fresh);
                alertNewRequest(fresh.is_medical);
            }
        }
        seen.current = new Set([...(seen.current ?? []), ...openIds]);

        setRequests(data);
        onCount(data.filter((r) => r.status === 'open').length);
    }, [token, onCount]);

    useEffect(() => {
        load();
        const timer = setInterval(load, 10000);
        return () => clearInterval(timer);
    }, [load]);

    const act = async (request, path, body) => {
        await csrfFetch(route(path, { token, serviceRequest: request.id }), {
            method: 'PATCH',
            body: body ? JSON.stringify(body) : undefined,
        });
        load();
    };

    const active = requests
        .filter((r) => ACTIVE.includes(r.status))
        .sort((a, b) =>
            a.is_medical !== b.is_medical ? (a.is_medical ? -1 : 1) : b.age_minutes - a.age_minutes
        );

    return (
        <div className="space-y-3">
            {arrived && (
                <div
                    role="alert"
                    className={`flex items-start justify-between gap-3 rounded-md border p-3 text-sm ${
                        arrived.is_medical
                            ? 'border-danger-fg bg-danger-bg text-danger-fg'
                            : 'border-accent bg-accent/5 text-ink'
                    }`}
                >
                    <span>
                        <b>{arrived.is_medical ? 'First aid requested' : 'New request'}:</b>{' '}
                        {TYPE_LABEL[arrived.type] ?? arrived.type}
                        {arrived.seat_label && `, seat ${arrived.seat_label}`}
                        {arrived.room_name && ` in ${arrived.room_name}`}
                    </span>
                    <button
                        type="button"
                        onClick={() => setArrived(null)}
                        className="shrink-0 text-xs underline"
                    >
                        OK
                    </button>
                </div>
            )}

            {active.length === 0 && (
                <p className="py-10 text-center text-sm text-ink-secondary">
                    No one needs help right now. New requests appear here and your phone will buzz.
                </p>
            )}

            <ul className="space-y-2">
                {active.map((request) => (
                    <li
                        key={request.id}
                        className={`rounded-md border p-3 ${request.is_medical ? 'border-danger-fg/50 bg-danger-bg/40' : 'border-border bg-surface'}`}
                    >
                        <div className="flex items-center gap-2">
                            {request.is_medical && (
                                <AlertTriangle
                                    className="h-4 w-4 text-danger-fg"
                                    strokeWidth={1.75}
                                />
                            )}
                            <b className="text-sm">{TYPE_LABEL[request.type] ?? request.type}</b>
                            <span className="ml-auto font-mono text-xs text-ink-secondary">
                                {request.age_minutes}m
                            </span>
                        </div>
                        <div className="mt-1 text-xs text-ink-secondary">
                            {[request.location, request.registrant_name]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                        {request.seat_label && (
                            <div className="mt-1.5 inline-flex items-center gap-1.5 rounded border border-border px-2 py-0.5 text-sm font-medium">
                                <MapPin
                                    className="h-3.5 w-3.5 text-ink-secondary"
                                    strokeWidth={1.75}
                                />
                                Seat {request.seat_label}
                                {request.room_name && (
                                    <span className="font-normal text-ink-secondary">
                                        · {request.room_name}
                                    </span>
                                )}
                            </div>
                        )}
                        {request.note && <p className="mt-1.5 text-sm">{request.note}</p>}
                        {request.assignee_name && request.status !== 'open' && (
                            <div className="mt-1.5 text-xs text-ink-tertiary">
                                {request.assignee_name} is on it
                            </div>
                        )}
                        <div className="mt-3 flex gap-2">
                            {request.status === 'open' ? (
                                <button
                                    type="button"
                                    onClick={() => act(request, 'public.staff.requests.claim')}
                                    className="flex-1 rounded-md border border-border bg-surface px-3 py-2.5 text-sm font-medium"
                                >
                                    I’m on it
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() =>
                                        act(request, 'public.staff.requests.status', {
                                            status: 'resolved',
                                        })
                                    }
                                    className="flex-1 rounded-md bg-accent px-3 py-2.5 text-sm font-semibold text-accent-ink"
                                >
                                    Done
                                </button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Ready({ token, link, event, counts: initialCounts }) {
    const [tab, setTab] = useState(link.can_check_in ? 'scan' : 'requests');
    const [counts, setCounts] = useState(initialCounts);
    const [openRequests, setOpenRequests] = useState(0);

    useEffect(() => {
        if (!link.can_check_in) return undefined;
        const timer = setInterval(async () => {
            const response = await csrfFetch(route('public.staff.counts', { token }));
            if (response.ok) setCounts(await response.json());
        }, 30000);
        return () => clearInterval(timer);
    }, [link.can_check_in, token]);

    const percent =
        counts && counts.expected > 0 ? Math.round((counts.checked_in / counts.expected) * 100) : 0;

    return (
        <Shell title={`${event.name} · ${link.name}`}>
            <header className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                <div className="min-w-0">
                    <div className="truncate text-sm font-semibold">{event.name}</div>
                    <div className="truncate text-xs text-ink-secondary">{link.name}</div>
                </div>
                {counts && (
                    <div className="shrink-0 text-right">
                        <div className="num text-lg font-semibold leading-none">
                            {counts.checked_in.toLocaleString()}
                            <span className="text-sm font-normal text-ink-tertiary">
                                {' '}
                                / {counts.expected.toLocaleString()}
                            </span>
                        </div>
                        <div className="num text-[11px] text-ink-secondary">{percent}% in</div>
                    </div>
                )}
            </header>

            {link.can_check_in && link.can_handle_requests && (
                <nav className="grid grid-cols-2 border-b border-border text-sm">
                    {[
                        { key: 'scan', label: 'Scan tickets', icon: ScanLine },
                        { key: 'requests', label: 'Requests', icon: Bell },
                    ].map(({ key, label, icon: Icon }) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setTab(key)}
                            className={`flex items-center justify-center gap-2 py-3 font-medium ${
                                tab === key
                                    ? 'border-b-2 border-accent text-ink'
                                    : 'text-ink-secondary'
                            }`}
                        >
                            <Icon className="h-4 w-4" strokeWidth={1.75} />
                            {label}
                            {key === 'requests' && openRequests > 0 && (
                                <span className="rounded-full bg-danger-fg px-1.5 text-[11px] font-semibold text-white">
                                    {openRequests}
                                </span>
                            )}
                        </button>
                    ))}
                </nav>
            )}

            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-5">
                {link.can_check_in && (
                    <div hidden={tab !== 'scan'}>
                        <CheckInPanel
                            event={event}
                            sessions={[]}
                            scanUrl={route('public.staff.checkin.scan', { token })}
                            searchUrl={route('public.staff.checkin.search', { token })}
                            checkInUrlFor={(registrationId) =>
                                route('public.staff.checkin', {
                                    token,
                                    registration: registrationId,
                                })
                            }
                        />
                    </div>
                )}
                {/* Kept mounted while scanning, so requests still buzz the phone. */}
                {link.can_handle_requests && (
                    <div hidden={tab !== 'requests'}>
                        <Requests token={token} onCount={setOpenRequests} />
                    </div>
                )}
            </main>
        </Shell>
    );
}

export default function StaffShow(props) {
    if (props.state === 'closed') return <Closed {...props} />;
    if (props.state === 'pin') return <Pin {...props} />;
    return <Ready {...props} />;
}
