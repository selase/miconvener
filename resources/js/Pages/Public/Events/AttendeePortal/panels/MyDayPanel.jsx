import { Check, Download, Plus } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

function formatSessionTime(startsAt, endsAt) {
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    const day = start.toLocaleDateString(undefined, { weekday: 'short' });
    const time = `${start.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    return `${day}, ${time}`;
}

export default function MyDayPanel({ event, registration, agendaIds, setAgendaIds }) {
    const toggle = async (session) => {
        const inAgenda = agendaIds.includes(session.id);
        const isFull = session.capacity !== null && session.signup_count >= session.capacity;
        if (!inAgenda && isFull) return;

        const isPlatform =
            typeof window !== 'undefined' && window.location.pathname.startsWith('/my/events');
        const url = isPlatform
            ? `/my/events/${registration.id}/agenda/${session.id}`
            : route(inAgenda ? 'public.events.agenda.remove' : 'public.events.agenda.add', {
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
