import { useState } from 'react';
import {
    HeartHandshake,
    Download,
    Building2,
    MessageSquareQuote,
    EyeOff,
    CheckCircle2,
    Clock,
    Calendar,
    Receipt,
    Sparkles,
} from 'lucide-react';
import EditTributeModal from './EditTributeModal';

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

export default function ContributionsSection({ contributions = [], onContributionUpdated }) {
    const [editingContribution, setEditingContribution] = useState(null);

    if (!contributions || contributions.length === 0) {
        return (
            <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center space-y-2">
                <HeartHandshake className="mx-auto h-8 w-8 text-ink-secondary/60" strokeWidth={1.5} />
                <h3 className="text-sm font-medium text-ink">No giving history found</h3>
                <p className="text-xs text-ink-secondary max-w-sm mx-auto">
                    Voluntary contributions, offerings, and tributes you make across MiConvener events will appear here, each with a receipt.
                </p>
            </div>
        );
    }

    // Aggregate statistics
    const totalMinor = contributions.reduce((acc, c) => acc + (Number(c.amount) || 0), 0);
    const currency = contributions[0]?.currency || 'GHS';
    const totalFormatted = `${currency} ${(totalMinor / 100).toFixed(2)}`;

    const uniqueEventsCount = new Set(
        contributions.map((c) => c.event?.id).filter(Boolean)
    ).size;

    const tributesCount = contributions.filter((c) => c.tribute_message && c.tribute_message.trim() !== '').length;

    const handleTributeUpdated = (updated) => {
        if (onContributionUpdated) {
            onContributionUpdated(updated);
        }
        setEditingContribution((prev) => (prev ? { ...prev, ...updated } : null));
    };

    return (
        <section aria-labelledby="contributions-heading" className="space-y-6">
            <div className="flex items-center justify-between border-b border-border pb-3">
                <div className="flex items-center gap-2">
                    <HeartHandshake className="h-5 w-5 text-accent" />
                    <h2 id="contributions-heading" className="text-base font-medium text-ink">
                        Giving &amp; Tributes
                    </h2>
                    <span className="rounded-full bg-surface-subtle border border-border px-2 py-0.5 text-xs font-semibold text-ink-secondary">
                        {contributions.length}
                    </span>
                </div>
                <p className="text-xs text-ink-secondary hidden sm:block">
                    Your donations, offerings, and tributes across all events
                </p>
            </div>

            {/* Summary Stat Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="rounded-xl border border-border bg-surface p-4 shadow-2xs space-y-1">
                    <span className="text-[11px] font-medium uppercase tracking-wider text-ink-secondary">
                        Total Contributed
                    </span>
                    <div className="text-lg font-bold text-ink">{totalFormatted}</div>
                    <span className="text-[11px] text-ink-secondary flex items-center gap-1">
                        <Sparkles className="h-3 w-3 text-accent" />
                        Verified via Paystack
                    </span>
                </div>

                <div className="rounded-xl border border-border bg-surface p-4 shadow-2xs space-y-1">
                    <span className="text-[11px] font-medium uppercase tracking-wider text-ink-secondary">
                        Causes &amp; Events
                    </span>
                    <div className="text-lg font-bold text-ink">{uniqueEventsCount}</div>
                    <span className="text-[11px] text-ink-secondary">
                        Organizations supported
                    </span>
                </div>

                <div className="rounded-xl border border-border bg-surface p-4 shadow-2xs space-y-1">
                    <span className="text-[11px] font-medium uppercase tracking-wider text-ink-secondary">
                        Tributes &amp; Notes
                    </span>
                    <div className="text-lg font-bold text-ink">{tributesCount}</div>
                    <span className="text-[11px] text-ink-secondary">
                        Messages on event walls
                    </span>
                </div>
            </div>

            {/* Contribution Cards */}
            <div className="space-y-4">
                {contributions.map((contribution) => {
                    const hasTribute = Boolean(contribution.tribute_message && contribution.tribute_message.trim() !== '');

                    return (
                        <div
                            key={contribution.id}
                            className="rounded-xl border border-border bg-surface p-5 shadow-2xs hover:border-border/80 transition-colors space-y-4"
                        >
                            <div className="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                                <div className="space-y-1.5 flex-1 min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="inline-flex items-center rounded-md bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                                            {contribution.event?.contribution_title || 'Voluntary Contribution'}
                                        </span>
                                        {contribution.event?.category && contribution.event.category !== 'general' && (
                                            <span className="inline-flex items-center rounded-md border border-border bg-surface-subtle px-2 py-0.5 text-xs font-medium text-ink-secondary capitalize">
                                                {contribution.event.category}
                                            </span>
                                        )}
                                        {contribution.paid_at && (
                                            <span className="inline-flex items-center gap-1 text-xs text-ink-secondary">
                                                <Calendar className="h-3 w-3" />
                                                {formatDate(contribution.paid_at)}
                                            </span>
                                        )}
                                    </div>

                                    <h3 className="text-base font-medium text-ink pt-0.5 leading-snug">
                                        {contribution.event?.url ? (
                                            <a
                                                href={contribution.event.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="hover:text-accent transition-colors"
                                            >
                                                {contribution.event.name}
                                            </a>
                                        ) : (
                                            contribution.event?.name || 'Event Contribution'
                                        )}
                                    </h3>

                                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-secondary">
                                        {contribution.organiser?.name && (
                                            <span className="inline-flex items-center gap-1">
                                                <Building2 className="h-3.5 w-3.5" />
                                                {contribution.organiser.name}
                                            </span>
                                        )}
                                        <span className="font-mono text-[11px]">
                                            Ref: {contribution.payment_reference}
                                        </span>
                                    </div>
                                </div>

                                <div className="text-left sm:text-right shrink-0 pt-1 sm:pt-0">
                                    <div className="text-lg font-bold text-ink">
                                        {contribution.formatted_amount}
                                    </div>
                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                                        <CheckCircle2 className="h-3 w-3" />
                                        Confirmed
                                    </span>
                                </div>
                            </div>

                            {/* Tribute Box if message exists */}
                            {hasTribute && (
                                <div className="rounded-lg border-l-2 border-accent bg-surface-subtle p-3.5 space-y-2">
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-1.5 text-xs font-semibold text-accent">
                                            <MessageSquareQuote className="h-3.5 w-3.5" />
                                            <span>Your Message on Tribute Wall</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {contribution.is_anonymous ? (
                                                <span className="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                                    <EyeOff className="h-3 w-3" />
                                                    Anonymous
                                                </span>
                                            ) : (
                                                <span className="text-[11px] text-ink-secondary">
                                                    Signed as: <strong>{contribution.contributor_name || 'Supporter'}</strong>
                                                </span>
                                            )}
                                            {contribution.is_approved ? (
                                                <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                                    <CheckCircle2 className="h-3 w-3" />
                                                    Published
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 text-[11px] font-medium text-amber-600 dark:text-amber-400">
                                                    <Clock className="h-3 w-3" />
                                                    Pending Review
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    <p className="text-xs text-ink italic leading-relaxed">
                                        &ldquo;{contribution.tribute_message}&rdquo;
                                    </p>
                                </div>
                            )}

                            {/* Action Buttons Row */}
                            <div className="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-border">
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditingContribution(contribution)}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-subtle transition-colors cursor-pointer"
                                    >
                                        <MessageSquareQuote className="h-3.5 w-3.5 text-ink-secondary" />
                                        <span>{hasTribute ? 'Edit Tribute & Preferences' : 'Add Tribute / Note'}</span>
                                    </button>
                                </div>

                                <div className="flex items-center gap-2">
                                    {contribution.receipt_url && (
                                        <a
                                            href={contribution.receipt_url}
                                            download
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-accent/30 bg-accent/5 px-3 py-1.5 text-xs font-medium text-accent hover:bg-accent/10 transition-colors cursor-pointer"
                                        >
                                            <Receipt className="h-3.5 w-3.5" />
                                            <span>Download Receipt (PDF)</span>
                                            <Download className="h-3 w-3 opacity-70" />
                                        </a>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* Modal for editing tribute */}
            {editingContribution && (
                <EditTributeModal
                    open={Boolean(editingContribution)}
                    onClose={() => setEditingContribution(null)}
                    contribution={editingContribution}
                    onSuccess={handleTributeUpdated}
                />
            )}
        </section>
    );
}

