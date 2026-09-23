import { useState } from 'react';
import {
    ArrowRight,
    Building,
    Calendar,
    ChevronDown,
    ChevronUp,
    Download,
    MapPin,
    Ticket,
    X,
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

function statusBadge(status) {
    switch (status) {
        case 'confirmed':
            return (
                <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                    Confirmed
                </span>
            );
        case 'pending_payment':
            return (
                <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                    Payment pending
                </span>
            );
        case 'pending_approval':
            return (
                <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                    Awaiting approval
                </span>
            );
        case 'waitlisted':
            return (
                <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700 dark:bg-blue-950/40 dark:text-blue-400">
                    Waitlisted
                </span>
            );
        case 'checked_in':
            return (
                <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                    Checked in
                </span>
            );
        default:
            return (
                <span className="rounded-full bg-surface-subtle px-2 py-0.5 text-[11px] font-medium text-ink-secondary">
                    {status}
                </span>
            );
    }
}

export default function OrganiserEventsSection({
    organisers = [],
    hasUrgentItems = false,
    selectedOrganiser = null,
    onClearFilter = null,
}) {
    // Past events accordion state keyed by organiser ID.
    // If urgent items exist, default to collapsed; otherwise expand if no upcoming events.
    const [expandedPast, setExpandedPast] = useState({});

    const togglePast = (orgId) => {
        setExpandedPast((prev) => ({
            ...prev,
            [orgId]: !prev[orgId],
        }));
    };

    if (!organisers || organisers.length === 0) {
        return null;
    }

    return (
        <div className="space-y-8">
            {selectedOrganiser && (
                <div className="flex items-center justify-between rounded-lg border border-border bg-surface-subtle px-4 py-2.5">
                    <div className="flex items-center gap-2 text-xs text-ink">
                        <Building className="h-3.5 w-3.5 text-ink-secondary" />
                        <span>
                            Filtered to events by{' '}
                            <strong className="font-semibold">{selectedOrganiser.name}</strong>
                        </span>
                    </div>
                    {onClearFilter && (
                        <button
                            type="button"
                            onClick={onClearFilter}
                            className="inline-flex items-center gap-1 text-xs text-accent hover:underline cursor-pointer"
                        >
                            <span>See all events</span>
                            <X className="h-3 w-3" />
                        </button>
                    )}
                </div>
            )}

            {organisers.map((org) => {
                const upcoming = org.upcoming ?? [];
                const past = org.past ?? [];
                const isPastExpanded =
                    expandedPast[org.id] ?? (!hasUrgentItems && upcoming.length === 0);

                if (upcoming.length === 0 && past.length === 0) {
                    return null;
                }

                return (
                    <section
                        key={org.id}
                        aria-labelledby={`org-heading-${org.id}`}
                        className="space-y-4"
                    >
                        <div className="flex items-center justify-between border-b border-border pb-2.5">
                            <h2
                                id={`org-heading-${org.id}`}
                                className="text-base font-semibold text-ink flex items-center gap-2"
                            >
                                <Building className="h-4 w-4 text-ink-secondary" />
                                {org.name}
                            </h2>
                            <span className="text-xs text-ink-secondary">
                                {upcoming.length} upcoming
                                {past.length > 0 ? ` · ${past.length} past` : ''}
                            </span>
                        </div>

                        {/* Upcoming Events */}
                        {upcoming.length > 0 && (
                            <div className="space-y-3">
                                {upcoming.map((item) => {
                                    const dateStr = formatEventDates(
                                        item.event?.starts_at,
                                        item.event?.ends_at
                                    );
                                    const materialsCount = item.released_materials?.length ?? 0;

                                    return (
                                        <div
                                            key={item.registration_id}
                                            className="rounded-xl border border-border bg-surface p-4 sm:p-5 shadow-xs hover:border-accent/40 transition-colors"
                                        >
                                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                                <div className="space-y-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="text-sm font-semibold text-ink">
                                                            {item.event?.name}
                                                        </h3>
                                                        {statusBadge(item.registration_status)}
                                                    </div>

                                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary">
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

                                                    <div className="flex flex-wrap items-center gap-2 pt-0.5">
                                                        <div className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface-subtle px-2 py-0.5 text-xs text-ink">
                                                            <Ticket className="h-3 w-3 text-ink-secondary" />
                                                            <span>
                                                                {item.ticket?.type ?? 'Admission'}
                                                            </span>
                                                            <span className="font-mono text-ink-secondary text-[11px]">
                                                                #{item.ticket?.code}
                                                            </span>
                                                        </div>

                                                        {item.ticket?.seat && (
                                                            <span className="rounded-md border border-border bg-surface-subtle px-2 py-0.5 text-xs text-ink">
                                                                Seat: {item.ticket.seat}
                                                            </span>
                                                        )}

                                                        {materialsCount > 0 && (
                                                            <span className="inline-flex items-center gap-1 text-xs text-ink-secondary">
                                                                <Download className="h-3 w-3" />
                                                                {materialsCount} material
                                                                {materialsCount > 1 ? 's' : ''}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="shrink-0">
                                                    <a
                                                        href={item.event_workspace_url}
                                                        className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3.5 py-2 text-xs font-medium text-ink hover:border-accent hover:text-accent transition-colors"
                                                    >
                                                        <span>Open workspace</span>
                                                        <ArrowRight className="h-3.5 w-3.5" />
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}

                        {/* Past Events Collapsible */}
                        {past.length > 0 && (
                            <div className="pt-2">
                                <button
                                    type="button"
                                    onClick={() => togglePast(org.id)}
                                    className="flex w-full items-center justify-between rounded-lg border border-border bg-surface-subtle px-4 py-2 text-xs font-medium text-ink-secondary hover:text-ink transition-colors cursor-pointer"
                                >
                                    <span>Past events ({past.length})</span>
                                    {isPastExpanded ? (
                                        <ChevronUp className="h-3.5 w-3.5" />
                                    ) : (
                                        <ChevronDown className="h-3.5 w-3.5" />
                                    )}
                                </button>

                                {isPastExpanded && (
                                    <div className="mt-3 space-y-2.5 pl-2 border-l-2 border-border">
                                        {past.map((item) => {
                                            const dateStr = formatEventDates(
                                                item.event?.starts_at,
                                                item.event?.ends_at
                                            );

                                            return (
                                                <div
                                                    key={item.registration_id}
                                                    className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-lg border border-border/80 bg-surface/80 p-3.5 text-xs text-ink-secondary hover:bg-surface transition-colors"
                                                >
                                                    <div className="space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <h4 className="font-medium text-ink">
                                                                {item.event?.name}
                                                            </h4>
                                                            {statusBadge(item.registration_status)}
                                                        </div>
                                                        <div className="flex items-center gap-3">
                                                            {dateStr && <span>{dateStr}</span>}
                                                            <span className="font-mono text-[11px]">
                                                                #{item.ticket?.code}
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <a
                                                        href={item.event_workspace_url}
                                                        className="inline-flex items-center gap-1 text-xs text-accent hover:underline shrink-0"
                                                    >
                                                        <span>Workspace</span>
                                                        <ArrowRight className="h-3 w-3" />
                                                    </a>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </div>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
