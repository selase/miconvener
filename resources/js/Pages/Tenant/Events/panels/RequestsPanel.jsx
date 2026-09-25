import { useEffect, useState } from 'react';
import { AlertTriangle, MapPin } from 'lucide-react';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import csrfFetch from '@/lib/csrfFetch';

const TYPE_LABEL = {
    refreshment: 'Water / refreshments',
    assistance: 'General assistance',
    technical: 'Technical issue',
    accessibility: 'Step-free / accessibility',
    medical: 'Medical / first aid',
    other: 'Something else',
};

const STATUS_LABEL = {
    open: 'Open',
    acknowledged: 'Acknowledged',
    in_progress: 'In progress',
    resolved: 'Resolved',
    cancelled: 'Cancelled',
};

const STATUS_VARIANT = {
    open: 'pending',
    acknowledged: 'neutral',
    in_progress: 'neutral',
    resolved: 'success',
    cancelled: 'failed',
};

const ACTIVE_STATUSES = ['open', 'acknowledged', 'in_progress'];

function sortActive(requests) {
    return [...requests].sort((a, b) => {
        if (a.is_medical !== b.is_medical) return a.is_medical ? -1 : 1;
        return b.age_minutes - a.age_minutes;
    });
}

function RequestCard({ event, request, onChange }) {
    const act = async (path, method = 'PATCH', body) => {
        await csrfFetch(route(path, { event: event.id, serviceRequest: request.id }), {
            method,
            body: body ? JSON.stringify(body) : undefined,
        });
        onChange();
    };

    return (
        <li
            className={`flex items-start gap-4 border px-4 py-3 ${request.is_medical ? 'border-danger-fg/40 bg-danger-bg/40' : 'border-border'}`}
        >
            <span className="w-10 shrink-0 pt-0.5 font-mono text-xs text-ink-secondary">
                {request.age_minutes}m
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <b className="text-[13.5px] text-ink">
                        {TYPE_LABEL[request.type] ?? request.type}
                    </b>
                    {request.is_medical && (
                        <AlertTriangle className="h-3.5 w-3.5 text-danger-fg" strokeWidth={1.75} />
                    )}
                    <StatusPill status={STATUS_VARIANT[request.status]}>
                        {STATUS_LABEL[request.status]}
                    </StatusPill>
                </div>
                <div className="mt-0.5 text-xs text-ink-secondary">
                    {[request.location, request.registrant_name].filter(Boolean).join(' · ')}
                </div>
                {request.seat_label && (
                    <div className="mt-1 inline-flex items-center gap-1.5 border border-border px-2 py-0.5 text-xs font-medium text-ink">
                        <MapPin className="h-3 w-3 text-ink-secondary" strokeWidth={1.75} />
                        Seat {request.seat_label}
                        {request.room_name && (
                            <span className="font-normal text-ink-secondary">· {request.room_name}</span>
                        )}
                    </div>
                )}
                {request.note && <p className="mt-1.5 text-[13px] text-ink">{request.note}</p>}
                {request.assignee_name && (
                    <div className="mt-1.5 text-xs text-ink-tertiary">
                        Assigned to {request.assignee_name}
                    </div>
                )}
            </div>
            {ACTIVE_STATUSES.includes(request.status) && (
                <div className="flex shrink-0 gap-1.5">
                    {request.status === 'open' && (
                        <Button onClick={() => act('tenant.events.service-requests.claim')}>
                            Assign to me
                        </Button>
                    )}
                    {request.status === 'acknowledged' && (
                        <Button
                            onClick={() =>
                                act('tenant.events.service-requests.status', 'PATCH', {
                                    status: 'in_progress',
                                })
                            }
                        >
                            Start
                        </Button>
                    )}
                    <Button
                        variant="primary"
                        onClick={() =>
                            act('tenant.events.service-requests.status', 'PATCH', {
                                status: 'resolved',
                            })
                        }
                    >
                        Resolve
                    </Button>
                    <button
                        onClick={() =>
                            act('tenant.events.service-requests.status', 'PATCH', {
                                status: 'cancelled',
                            })
                        }
                        className="text-xs text-ink-secondary hover:text-danger-fg"
                    >
                        Cancel
                    </button>
                </div>
            )}
        </li>
    );
}

export default function RequestsPanel({ event }) {
    const [requests, setRequests] = useState([]);
    const [arrived, setArrived] = useState(null);

    const load = () => {
        csrfFetch(route('tenant.events.service-requests.index', { event: event.id }))
            .then((r) => r.json())
            .then(setRequests);
    };

    useEffect(() => {
        load();
        // Polling stays as the floor beneath the broadcast: a console that
        // loses its socket keeps working, eight seconds behind.
        const interval = setInterval(load, 8000);
        return () => clearInterval(interval);
        // Dependencies deliberately limited to the ids above (re-run only when they change).
    }, [event.id]);

    // A request is somebody waiting, so it should land on the screen when it is
    // made rather than whenever the next poll happens to come round.
    useEffect(() => {
        if (typeof window === 'undefined' || !window.Echo) {
            return undefined;
        }

        const channel = window.Echo.private(`event.${event.id}.service-requests`);

        channel.listen('.ServiceRequestRaised', (incoming) => {
            setRequests((current) =>
                current.some((r) => r.id === incoming.id) ? current : [incoming, ...current]
            );
            setArrived(incoming);
        });

        return () => {
            channel.stopListening('.ServiceRequestRaised');
            window.Echo.leave(`event.${event.id}.service-requests`);
        };
    }, [event.id]);

    // The banner stays until it is dismissed or another arrives: an urgent
    // request that faded out while nobody was looking would be worse than none.
    const dismissArrival = () => setArrived(null);

    const active = sortActive(requests.filter((r) => ACTIVE_STATUSES.includes(r.status)));
    const closed = requests
        .filter((r) => !ACTIVE_STATUSES.includes(r.status))
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

    return (
        <div className="max-w-2xl space-y-6">
            <p className="text-sm text-ink-secondary">
                Requests raised by attendees from their ticket page, newest waits and medical
                requests first.
            </p>

            {arrived && (
                <div
                    role="alert"
                    className={`flex items-start justify-between gap-3 border p-3 text-sm ${
                        arrived.is_medical
                            ? 'border-danger-fg bg-danger-bg text-danger-fg'
                            : 'border-accent bg-accent/5 text-ink'
                    }`}
                >
                    <div className="flex items-start gap-2">
                        {arrived.is_medical && (
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" strokeWidth={1.75} />
                        )}
                        <div>
                            <span className="font-medium">
                                {arrived.is_medical ? 'First aid requested' : 'New request'}
                            </span>{' '}
                            {TYPE_LABEL[arrived.type] ?? arrived.type}
                            {arrived.registrant_name && ` from ${arrived.registrant_name}`}
                            {arrived.seat_label && `, seat ${arrived.seat_label}`}
                            {arrived.room_name && ` in ${arrived.room_name}`}.
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={dismissArrival}
                        className="shrink-0 text-xs underline opacity-80 hover:opacity-100"
                    >
                        Dismiss
                    </button>
                </div>
            )}

            {active.length > 0 ? (
                <ul className="space-y-2">
                    {active.map((r) => (
                        <RequestCard key={r.id} event={event} request={r} onChange={load} />
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-ink-secondary">No open requests right now.</p>
            )}

            {closed.length > 0 && (
                <div>
                    <b className="mb-2 block text-xs uppercase tracking-wide text-ink-secondary">
                        Closed
                    </b>
                    <ul className="space-y-2 opacity-70">
                        {closed.slice(0, 20).map((r) => (
                            <RequestCard key={r.id} event={event} request={r} onChange={load} />
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
