import {
    ArrowRight,
    Building,
    Calendar,
    CheckCircle2,
    HelpCircle,
    MapPin,
    Ticket,
} from 'lucide-react';

function formatEventDates(startsAt, endsAt) {
    if (!startsAt) return null;
    const start = new Date(startsAt);
    const startStr = start.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
    if (!endsAt) return startStr;
    const end = new Date(endsAt);
    const endStr = end.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
    return startStr === endStr ? startStr : `${startStr} – ${endStr}`;
}

export default function LiveNowSection({ items }) {
    if (!items || items.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="live-now-heading" className="space-y-4">
            <div className="flex items-center gap-2">
                <span className="relative flex h-2.5 w-2.5">
                    <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500" />
                </span>
                <h2 id="live-now-heading" className="text-base font-medium text-ink">
                    Live now
                </h2>
                <span className="ml-1 inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                    {items.length}
                </span>
            </div>

            <div className="space-y-4">
                {items.map((item) => {
                    const dateStr = formatEventDates(item.event?.starts_at, item.event?.ends_at);
                    const isCheckedIn = item.registration_status === 'checked_in';

                    return (
                        <div
                            key={item.registration_id}
                            className="rounded-xl border border-emerald-200 bg-surface p-5 shadow-sm dark:border-emerald-900/50"
                        >
                            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="text-base font-semibold text-ink">
                                            {item.event?.name}
                                        </h3>
                                        {isCheckedIn ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                <CheckCircle2 className="h-3 w-3" />
                                                Checked In
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                Live Event
                                            </span>
                                        )}
                                    </div>

                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary">
                                        {item.organiser?.name && (
                                            <span className="inline-flex items-center gap-1">
                                                <Building className="h-3.5 w-3.5" />
                                                {item.organiser.name}
                                            </span>
                                        )}
                                        {dateStr && (
                                            <span className="inline-flex items-center gap-1">
                                                <Calendar className="h-3.5 w-3.5" />
                                                {dateStr}
                                            </span>
                                        )}
                                        {item.event?.location && (
                                            <span className="inline-flex items-center gap-1">
                                                <MapPin className="h-3.5 w-3.5" />
                                                {item.event.location}
                                            </span>
                                        )}
                                    </div>

                                    {/* Ticket summary */}
                                    <div className="flex flex-wrap items-center gap-2 pt-1">
                                        <div className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface-subtle px-2.5 py-1 text-xs text-ink">
                                            <Ticket className="h-3.5 w-3.5 text-ink-secondary" />
                                            <span className="font-medium">
                                                {item.ticket?.type ?? 'Admission'}
                                            </span>
                                            <span className="font-mono text-ink-secondary text-[11px]">
                                                #{item.ticket?.code}
                                            </span>
                                        </div>

                                        {item.ticket?.seat && (
                                            <span className="rounded-lg border border-border bg-surface-subtle px-2.5 py-1 text-xs text-ink">
                                                Seat:{' '}
                                                <span className="font-medium">
                                                    {item.ticket.seat}
                                                </span>
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-wrap sm:flex-col items-center sm:items-end gap-2 shrink-0">
                                    <a
                                        href={item.event_workspace_url}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-[13px] font-medium text-white shadow-sm hover:opacity-90 transition-opacity"
                                    >
                                        <span>Open workspace</span>
                                        <ArrowRight className="h-3.5 w-3.5" />
                                    </a>

                                    <div className="flex items-center gap-2">
                                        {item.available_actions?.includes('agenda') && (
                                            <a
                                                href={`${item.event_workspace_url}#agenda`}
                                                className="inline-flex items-center gap-1 text-xs text-ink-secondary hover:text-ink underline"
                                            >
                                                <Calendar className="h-3 w-3" />
                                                My day
                                            </a>
                                        )}
                                        {item.available_actions?.includes('request_service') && (
                                            <a
                                                href={`${item.event_workspace_url}#help`}
                                                className="inline-flex items-center gap-1 text-xs text-ink-secondary hover:text-ink underline"
                                            >
                                                <HelpCircle className="h-3 w-3" />
                                                Get help
                                            </a>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
