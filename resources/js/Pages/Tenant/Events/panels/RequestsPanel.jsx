import { useEffect, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
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
        <li className={`flex items-start gap-4 border px-4 py-3 ${request.is_medical ? 'border-danger-fg/40 bg-danger-bg/40' : 'border-border'}`}>
            <span className="w-10 shrink-0 pt-0.5 font-mono text-xs text-ink-secondary">{request.age_minutes}m</span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <b className="text-[13.5px] text-ink">{TYPE_LABEL[request.type] ?? request.type}</b>
                    {request.is_medical && <AlertTriangle className="h-3.5 w-3.5 text-danger-fg" strokeWidth={1.75} />}
                    <StatusPill status={STATUS_VARIANT[request.status]}>{STATUS_LABEL[request.status]}</StatusPill>
                </div>
                <div className="mt-0.5 text-xs text-ink-secondary">
                    {[request.location, request.registrant_name].filter(Boolean).join(' · ')}
                </div>
                {request.note && <p className="mt-1.5 text-[13px] text-ink">{request.note}</p>}
                {request.assignee_name && <div className="mt-1.5 text-xs text-ink-tertiary">Assigned to {request.assignee_name}</div>}
            </div>
            {ACTIVE_STATUSES.includes(request.status) && (
                <div className="flex shrink-0 gap-1.5">
                    {request.status === 'open' && (
                        <Button onClick={() => act('tenant.events.service-requests.claim')}>Assign to me</Button>
                    )}
                    {request.status === 'acknowledged' && (
                        <Button onClick={() => act('tenant.events.service-requests.status', 'PATCH', { status: 'in_progress' })}>Start</Button>
                    )}
                    <Button variant="primary" onClick={() => act('tenant.events.service-requests.status', 'PATCH', { status: 'resolved' })}>Resolve</Button>
                    <button
                        onClick={() => act('tenant.events.service-requests.status', 'PATCH', { status: 'cancelled' })}
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

    const load = () => {
        csrfFetch(route('tenant.events.service-requests.index', { event: event.id }))
            .then((r) => r.json())
            .then(setRequests);
    };

    useEffect(() => {
        load();
        const interval = setInterval(load, 8000);
        return () => clearInterval(interval);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [event.id]);

    const active = sortActive(requests.filter((r) => ACTIVE_STATUSES.includes(r.status)));
    const closed = requests
        .filter((r) => !ACTIVE_STATUSES.includes(r.status))
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

    return (
        <div className="max-w-2xl space-y-6">
            <p className="text-sm text-ink-secondary">Requests raised by attendees from their ticket page, newest waits and medical requests first.</p>

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
                    <b className="mb-2 block text-xs uppercase tracking-wide text-ink-secondary">Closed</b>
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
