import { useEffect, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

/**
 * Entries per day, and tickets admitted twice in one day. A second admission
 * only happens while a door is offline, so the list is normally empty; on a
 * single-day event with no double entries the panel shows nothing at all.
 */
export default function DoorScansPanel({ event }) {
    const [data, setData] = useState(null);

    useEffect(() => {
        const load = () =>
            csrfFetch(route('tenant.events.door-scans.index', { event: event.id }))
                .then((response) => (response.ok ? response.json() : null))
                .then(setData)
                .catch(() => {});
        load();
        const timer = setInterval(load, 30000);
        return () => clearInterval(timer);
    }, [event.id]);

    if (!data || (data.days.length < 2 && data.used_twice.length === 0)) {
        return null;
    }

    return (
        <section className="space-y-3 border border-border p-4">
            {data.days.length > 1 && (
                <div className="flex flex-wrap gap-4 text-sm">
                    {data.days.map((day) => (
                        <span key={day.day}>
                            <b className="text-ink">Day {day.number}</b>{' '}
                            <span className="num text-ink-secondary">
                                {day.admitted.toLocaleString()} in
                            </span>
                        </span>
                    ))}
                </div>
            )}
            {data.used_twice.length > 0 && (
                <div>
                    <h3 className="text-sm font-semibold text-ink">
                        Used twice in a day ({data.used_twice.length})
                    </h3>
                    <p className="text-xs text-ink-secondary">
                        Both admitted while a door was offline. Listed for your records, with the
                        ticket holder's name.
                    </p>
                    <ul className="mt-2 divide-y divide-border text-sm">
                        {data.used_twice.map((ticket) => (
                            <li key={`${ticket.registration_id}-${ticket.day}`} className="py-2">
                                <b className="text-ink">{ticket.name}</b>{' '}
                                <span className="font-mono text-xs text-ink-secondary">
                                    {ticket.ticket_code}
                                </span>
                                <span className="text-ink-secondary"> · Day {ticket.number}: </span>
                                {ticket.entries
                                    .map(
                                        (entry) =>
                                            `${entry.at} ${entry.by}${entry.offline ? ' (offline)' : ''}`
                                    )
                                    .join(', ')}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
