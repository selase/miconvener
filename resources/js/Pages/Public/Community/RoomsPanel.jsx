import { useEffect, useRef, useState } from 'react';
import { communityRequest } from '@/lib/community';

const dayLabel = (date) =>
    date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
const timeLabel = (iso) =>
    new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
const dateKey = (date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** The busy times of one room, by day, so staff can see what is free. */
function Week({ room, from, days }) {
    const busy = room.busy.map((slot) => ({
        start: new Date(slot.starts_at),
        end: new Date(slot.ends_at),
    }));

    return (
        <ul className="mt-3 divide-y divide-border border border-border text-sm">
            {Array.from({ length: days }, (_, index) => {
                const day = new Date(from);
                day.setDate(day.getDate() + index);
                const dayStart = new Date(day);
                dayStart.setHours(0, 0, 0, 0);
                const dayEnd = new Date(day);
                dayEnd.setHours(23, 59, 59, 999);
                const taken = busy.filter((slot) => slot.start <= dayEnd && slot.end >= dayStart);

                return (
                    <li
                        key={dateKey(day)}
                        className="flex flex-wrap items-baseline gap-x-4 px-3 py-2"
                    >
                        <span className="w-28 shrink-0 font-medium text-ink">{dayLabel(day)}</span>
                        {taken.length === 0 ? (
                            <span className="text-ink-secondary">Free all day</span>
                        ) : (
                            <span className="text-ink">
                                Busy{' '}
                                {taken
                                    .map(
                                        (slot) => `${timeLabel(slot.start)}-${timeLabel(slot.end)}`
                                    )
                                    .join(', ')}
                            </span>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

export default function RoomsPanel({ tenantSlug, onExpired, onClose }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [from, setFrom] = useState(() => dateKey(new Date()));
    const [revision, setRevision] = useState(0);
    const [requesting, setRequesting] = useState(null);
    const [form, setForm] = useState({ starts_at: '', ends_at: '', purpose: '', department: '' });
    const [busy, setBusy] = useState(false);
    const callbacks = useRef({ onExpired });
    callbacks.current = { onExpired };
    const base = `/my/communities/${encodeURIComponent(tenantSlug)}`;

    useEffect(() => {
        let disposed = false;
        const controller = new AbortController();
        setError('');
        communityRequest(`${base}/rooms?from=${from}&days=7`, 'GET', undefined, {
            signal: controller.signal,
        })
            .then((result) => {
                if (!disposed) setData(result);
            })
            .catch((failure) => {
                if (!disposed && failure.name !== 'AbortError') {
                    setError(failure.message);
                    if (failure.status === 401) callbacks.current.onExpired?.();
                }
            });
        return () => {
            disposed = true;
            controller.abort();
        };
    }, [base, from, revision]);

    const shift = (days) => {
        const next = new Date(`${from}T00:00:00`);
        next.setDate(next.getDate() + days);
        setFrom(dateKey(next));
    };

    const set = (field) => (event) => setForm({ ...form, [field]: event.target.value });

    const submit = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await communityRequest(
                `${base}/rooms/${requesting.id}/requests`,
                'POST',
                {
                    starts_at: new Date(form.starts_at).toISOString(),
                    ends_at: new Date(form.ends_at).toISOString(),
                    purpose: form.purpose,
                    department: form.department || null,
                }
            );
            setNotice(
                result.booking.status === 'confirmed'
                    ? 'Your room is booked.'
                    : 'Your request was sent. You will be emailed when it is decided.'
            );
            setRequesting(null);
            setForm({ starts_at: '', ends_at: '', purpose: '', department: '' });
            setRevision((n) => n + 1);
        } catch (failure) {
            setError(failure.message);
            if (failure.status === 401) onExpired?.();
        } finally {
            setBusy(false);
        }
    };

    const cancel = async (booking) => {
        setError('');
        setNotice('');
        try {
            await communityRequest(`${base}/room-requests/${booking.id}`, 'DELETE');
            setNotice('Cancelled.');
            setRevision((n) => n + 1);
        } catch (failure) {
            setError(failure.message);
        }
    };

    return (
        <section className="space-y-6" aria-label="Book a room">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-5">
                <div>
                    <p className="text-xs font-medium uppercase tracking-widest text-ink-secondary">
                        Your organisation
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold text-ink">
                        {data?.organisation ? `Book a room at ${data.organisation}` : 'Book a room'}
                    </h2>
                    <p className="mt-1 text-sm text-ink-secondary">Free for staff.</p>
                </div>
                {onClose && (
                    <button className="min-h-11 text-accent underline" onClick={onClose}>
                        Back to Communities
                    </button>
                )}
            </div>

            {error && (
                <div role="alert" className="border border-border p-4">
                    <p>{error}</p>
                </div>
            )}
            {notice && (
                <p role="status" className="border border-border p-4 text-ink">
                    {notice}
                </p>
            )}
            {!error && !data && (
                <p role="status" className="text-ink-secondary">
                    Loading rooms…
                </p>
            )}

            {data?.mine?.length > 0 && (
                <div className="space-y-2">
                    <h3 className="font-medium text-ink">Your bookings</h3>
                    <ul className="divide-y divide-border border border-border">
                        {data.mine.map((booking) => (
                            <li
                                key={booking.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                <span>
                                    <span className="font-medium text-ink">{booking.room}</span>{' '}
                                    {dayLabel(new Date(booking.starts_at))},{' '}
                                    {timeLabel(booking.starts_at)}-{timeLabel(booking.ends_at)}{' '}
                                    <span className="text-ink-secondary">
                                        (
                                        {booking.status === 'confirmed'
                                            ? 'booked'
                                            : 'waiting for approval'}
                                        )
                                    </span>
                                </span>
                                <button
                                    className="min-h-11 text-accent underline"
                                    onClick={() => cancel(booking)}
                                >
                                    Cancel
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {data && (
                <div className="flex flex-wrap items-center gap-3">
                    <button
                        className="min-h-11 border border-border px-4 text-ink"
                        onClick={() => shift(-7)}
                    >
                        Earlier
                    </button>
                    <span className="text-sm text-ink-secondary" aria-live="polite">
                        {dayLabel(new Date(`${data.from}T00:00:00`))} to{' '}
                        {dayLabel(new Date(`${data.to}T00:00:00`))}
                    </span>
                    <button
                        className="min-h-11 border border-border px-4 text-ink"
                        onClick={() => shift(7)}
                    >
                        Later
                    </button>
                </div>
            )}

            {data?.rooms?.length === 0 && (
                <p className="text-ink-secondary">There are no rooms to book yet.</p>
            )}
            {data?.rooms?.map((room) => (
                <article key={room.id} className="space-y-3 border border-border bg-surface p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="text-lg font-semibold text-ink">
                            {room.title}
                            {room.audience === 'staff' && (
                                <span className="ml-2 border border-border px-2 py-0.5 text-xs font-normal text-ink-secondary">
                                    Staff only
                                </span>
                            )}
                        </h3>
                        <button
                            className="min-h-11 bg-accent px-5 font-medium text-accent-ink"
                            onClick={() => setRequesting(requesting?.id === room.id ? null : room)}
                            aria-expanded={requesting?.id === room.id}
                        >
                            Request this room
                        </button>
                    </div>
                    <Week room={room} from={new Date(`${data.from}T00:00:00`)} days={7} />
                    {requesting?.id === room.id && (
                        <form onSubmit={submit} className="space-y-3 border-t border-border pt-4">
                            <p className="text-sm text-ink-secondary">
                                {room.requires_approval
                                    ? 'A facility manager will approve your request.'
                                    : 'This room is booked as soon as you send the request.'}
                            </p>
                            <label className="block text-sm font-medium text-ink">
                                Starts
                                <input
                                    required
                                    type="datetime-local"
                                    value={form.starts_at}
                                    onChange={set('starts_at')}
                                    className="mt-1 block min-h-11 w-full border border-border px-3"
                                />
                            </label>
                            <label className="block text-sm font-medium text-ink">
                                Ends
                                <input
                                    required
                                    type="datetime-local"
                                    value={form.ends_at}
                                    onChange={set('ends_at')}
                                    className="mt-1 block min-h-11 w-full border border-border px-3"
                                />
                            </label>
                            <label className="block text-sm font-medium text-ink">
                                What it is for
                                <input
                                    required
                                    value={form.purpose}
                                    onChange={set('purpose')}
                                    className="mt-1 block min-h-11 w-full border border-border px-3"
                                />
                            </label>
                            <label className="block text-sm font-medium text-ink">
                                Department (optional)
                                <input
                                    value={form.department}
                                    onChange={set('department')}
                                    className="mt-1 block min-h-11 w-full border border-border px-3"
                                />
                            </label>
                            <button
                                type="submit"
                                disabled={busy}
                                className="min-h-11 bg-accent px-5 font-medium text-accent-ink disabled:opacity-50"
                            >
                                Send request
                            </button>
                        </form>
                    )}
                </article>
            ))}
        </section>
    );
}
