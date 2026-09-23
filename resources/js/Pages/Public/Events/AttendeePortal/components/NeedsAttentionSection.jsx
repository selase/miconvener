import { AlertTriangle, ArrowRight, Building } from 'lucide-react';

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

export default function NeedsAttentionSection({ items }) {
    if (!items || items.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="needs-attention-heading" className="space-y-4">
            <div className="flex items-center gap-2">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-400">
                    <AlertTriangle className="h-3.5 w-3.5" />
                </span>
                <h2 id="needs-attention-heading" className="text-base font-medium text-ink">
                    Needs your attention
                </h2>
                <span className="ml-1 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                    {items.length}
                </span>
            </div>

            <div className="space-y-3">
                {items.map((item) => {
                    const dateStr = formatEventDates(item.event?.starts_at, item.event?.ends_at);

                    return (
                        <div
                            key={item.registration_id}
                            className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-amber-200 bg-amber-50/60 p-4 sm:p-5 dark:border-amber-900/40 dark:bg-amber-950/20"
                        >
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h3 className="text-sm font-semibold text-ink">
                                        {item.event?.name ?? 'Event'}
                                    </h3>
                                    {item.organiser?.name && (
                                        <span className="inline-flex items-center gap-1 text-xs text-ink-secondary">
                                            <Building className="h-3 w-3" />
                                            {item.organiser.name}
                                        </span>
                                    )}
                                </div>

                                {dateStr && (
                                    <p className="text-xs text-ink-secondary">
                                        {dateStr}
                                    </p>
                                )}

                                <p className="text-[13px] font-medium text-amber-900 dark:text-amber-200 pt-0.5">
                                    {item.reason}
                                </p>
                            </div>

                            <div className="shrink-0">
                                <a
                                    href={item.action?.url ?? `/my/events/${item.registration_id}`}
                                    className="inline-flex items-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-[13px] font-medium text-white shadow-sm hover:opacity-90 transition-opacity"
                                >
                                    <span>{item.action?.label ?? 'View details'}</span>
                                    <ArrowRight className="h-3.5 w-3.5" />
                                </a>
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
