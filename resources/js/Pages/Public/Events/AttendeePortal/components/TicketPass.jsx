import { CalendarDays, Ticket, ArrowUpRight } from 'lucide-react';
export default function TicketPass({ entry, onOpen }) {
    const event = entry.event;
    return (
        <article className="overflow-hidden border border-border bg-surface">
            {event.hero_image_url && (
                <img
                    src={event.hero_image_url}
                    alt=""
                    className="h-40 w-full object-cover"
                    onError={(e) => {
                        e.currentTarget.hidden = true;
                    }}
                />
            )}
            <div className="border-t-2 border-accent p-5">
                <p className="mb-2 text-xs uppercase tracking-wider text-accent">
                    {entry.organiser?.name}
                </p>
                <h2 className="text-xl font-medium tracking-tight">{event.name}</h2>
                <p className="mt-2 flex items-center gap-2 text-xs text-ink-secondary">
                    <CalendarDays className="h-4 w-4" aria-hidden="true" />
                    {new Date(event.starts_at).toLocaleDateString(undefined, {
                        timeZone: event.timezone || undefined,
                        dateStyle: 'medium',
                    })}
                    {event.location ? ` · ${event.location}` : ''}
                </p>
                <div className="my-4 flex items-center justify-between gap-3 border-y border-dashed border-border py-4">
                    <span className="flex items-center gap-2 text-sm">
                        <Ticket className="h-4 w-4" aria-hidden="true" />
                        {entry.ticket?.type || 'Admission'}
                    </span>
                    <span className="text-xs text-ink-secondary">
                        {entry.registration_status?.replaceAll('_', ' ')}
                    </span>
                </div>
                <div className="flex flex-wrap items-center gap-4">
                    {entry.available_actions?.includes('view_ticket') && (
                        <button
                            type="button"
                            onClick={() => onOpen(entry)}
                            className="app-primary min-h-11 px-4 py-2.5 text-sm font-medium"
                        >
                            Open ticket
                        </button>
                    )}
                    <a
                        href={entry.event_workspace_url}
                        className="inline-flex min-h-11 items-center gap-2 text-sm text-accent underline underline-offset-4"
                    >
                        {entry.registration_status === 'pending_payment'
                            ? 'Complete payment'
                            : 'Event workspace'}
                        <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
                    </a>
                </div>
            </div>
        </article>
    );
}
