import { FileText, Building2, Calendar, Tag } from 'lucide-react';

function formatDate(isoString) {
    if (!isoString) return '';
    try {
        const date = new Date(isoString);
        return date.toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return isoString;
    }
}

const STATUS_CONFIG = {
    accepted_oral: {
        label: 'Accepted: Oral Presentation',
        classes: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
    },
    accepted_poster: {
        label: 'Accepted: Poster Presentation',
        classes: 'border-teal-200 bg-teal-50 text-teal-800 dark:border-teal-800 dark:bg-teal-950/40 dark:text-teal-300',
    },
    under_review: {
        label: 'Under Review',
        classes: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
    },
    submitted: {
        label: 'Submitted',
        classes: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300',
    },
    rejected: {
        label: 'Not Accepted',
        classes: 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300',
    },
    withdrawn: {
        label: 'Withdrawn',
        classes: 'border-zinc-200 bg-zinc-100 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400',
    },
};

const PREFERENCE_LABELS = {
    oral: 'Oral presentation preference',
    poster: 'Poster presentation preference',
    either: 'Oral or Poster preference',
};

/**
 * Abstracts Section
 * Displays abstract submissions and co-authorships across organisers,
 * deduplicated per abstract, showing human-readable status, presentation preferences,
 * and event context.
 */
export default function AbstractsSection({ abstracts = [] }) {
    if (!abstracts || abstracts.length === 0) {
        return (
            <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center space-y-2">
                <FileText className="mx-auto h-8 w-8 text-ink-secondary/60" strokeWidth={1.5} />
                <h3 className="text-sm font-medium text-ink">No abstract submissions found</h3>
                <p className="text-xs text-ink-secondary max-w-sm mx-auto">
                    Abstracts you submit or co-author for scientific and academic conferences will appear here.
                </p>
            </div>
        );
    }

    return (
        <section aria-labelledby="abstracts-heading" className="space-y-4">
            <div className="flex items-center justify-between border-b border-border pb-3">
                <div className="flex items-center gap-2">
                    <FileText className="h-5 w-5 text-accent" />
                    <h2 id="abstracts-heading" className="text-base font-medium text-ink">
                        Abstract Submissions & Co-authorships
                    </h2>
                    <span className="rounded-full bg-surface-subtle border border-border px-2 py-0.5 text-xs font-semibold text-ink-secondary">
                        {abstracts.length}
                    </span>
                </div>
                <p className="text-xs text-ink-secondary hidden sm:block">
                    Your research contributions and co-authored papers
                </p>
            </div>

            <div className="grid grid-cols-1 gap-4">
                {abstracts.map((item) => {
                    const statusConfig = STATUS_CONFIG[item.status] || {
                        label: item.status?.replace('_', ' ') || 'Submitted',
                        classes: 'border-border bg-surface-subtle text-ink-secondary',
                    };
                    const preferenceLabel = PREFERENCE_LABELS[item.presentation_preference];

                    return (
                        <div
                            key={item.id}
                            className="rounded-xl border border-border bg-surface p-5 shadow-xs hover:border-border/80 transition-colors space-y-3"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    {item.code && (
                                        <span className="inline-flex items-center rounded-md border border-border bg-surface-subtle px-2 py-0.5 font-mono text-xs font-semibold text-ink">
                                            {item.code}
                                        </span>
                                    )}
                                    <span
                                        className={`inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium ${statusConfig.classes}`}
                                    >
                                        {statusConfig.label}
                                    </span>
                                    {preferenceLabel && (
                                        <span className="inline-flex items-center gap-1 text-xs text-ink-secondary">
                                            <Tag className="h-3 w-3" />
                                            {preferenceLabel}
                                        </span>
                                    )}
                                </div>

                                {item.created_at && (
                                    <span className="inline-flex items-center gap-1 text-xs text-ink-secondary">
                                        <Calendar className="h-3 w-3" />
                                        Submitted {formatDate(item.created_at)}
                                    </span>
                                )}
                            </div>

                            <h3 className="text-base font-medium text-ink leading-snug">
                                {item.title}
                            </h3>

                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary pt-1 border-t border-border/50">
                                {item.event_name && (
                                    <span className="font-medium text-ink">
                                        {item.event_name}
                                    </span>
                                )}
                                {item.organiser_name && (
                                    <span className="inline-flex items-center gap-1">
                                        <Building2 className="h-3 w-3" />
                                        {item.organiser_name}
                                    </span>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}
