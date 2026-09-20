import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, X } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';

/**
 * An event's workspace: its sections grouped by the job the organizer is doing
 * (registration, programme, the day itself...) instead of one row of 25 tabs.
 *
 * From lg up the sections sit in their own column beside the content; below
 * that, one button names the current section and opens the same grouped list.
 * The menu order never changes -- what changes with the event is the overview
 * and the badges -- so it stays learnable.
 */

function dateRange(event) {
    const start = new Date(event.starts_at);
    const end = new Date(event.ends_at);
    const day = { day: 'numeric' };
    const dayMonth = { day: 'numeric', month: 'short' };
    const sameDay = start.toDateString() === end.toDateString();
    if (sameDay) return start.toLocaleDateString(undefined, dayMonth);
    const sameMonth =
        start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear();
    return sameMonth
        ? `${start.toLocaleDateString(undefined, day)}–${end.toLocaleDateString(undefined, dayMonth)}`
        : `${start.toLocaleDateString(undefined, dayMonth)} – ${end.toLocaleDateString(undefined, dayMonth)}`;
}

export function StatusPill({ event, phase }) {
    if (phase === 'live') {
        return (
            <span className="inline-flex w-max items-center gap-1.5 rounded-full bg-success-bg px-2 py-0.5 text-[11.5px] font-medium text-success-fg">
                <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
                Live now
            </span>
        );
    }
    const status =
        phase === 'after' ? 'Ended' : event.status === 'published' ? 'Published' : 'Draft';
    return (
        <span className="inline-flex w-max items-center rounded-full bg-surface-sunken px-2 py-0.5 text-[11.5px] font-medium text-ink-secondary">
            {status} · {dateRange(event)}
        </span>
    );
}

export function SectionBadge({ badge }) {
    if (!badge) return null;
    if (badge.kind === 'live') {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-success-bg px-1.5 text-[11px] font-medium leading-[18px] text-success-fg">
                <span className="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true" />
                {badge.label}
            </span>
        );
    }
    const tone =
        badge.kind === 'attention' ? 'bg-warning-bg text-warning-fg' : 'bg-accent-soft text-accent';
    return (
        <span
            className={`min-w-[18px] rounded-full px-1.5 text-center text-[11px] font-medium leading-[18px] ${tone}`}
            title={badge.title}
        >
            {badge.label}
        </span>
    );
}

function SectionList({ sections, current, hrefFor, badges, onNavigate, large = false }) {
    return (
        <div className="pb-4">
            {sections.map((group) => (
                <div key={group.name ?? 'top'} className="px-2 pt-3">
                    {group.name && (
                        <h2 className="mb-1 px-2.5 text-[11px] font-medium uppercase tracking-[0.06em] text-ink-tertiary">
                            {group.name}
                        </h2>
                    )}
                    {group.sections.map((section) => {
                        const active = section.slug === current;
                        return (
                            <Link
                                key={section.slug}
                                href={hrefFor(section.slug)}
                                onClick={onNavigate}
                                preserveState={false}
                                aria-current={active ? 'page' : undefined}
                                className={`flex w-full items-center justify-between gap-2 rounded-md px-2.5 ${
                                    large ? 'py-2.5 text-sm' : 'py-1.5 text-[13px]'
                                } transition-colors duration-120 ease-out ${
                                    active
                                        ? 'bg-surface-hover font-medium text-ink shadow-[inset_2px_0_0_var(--color-accent)]'
                                        : 'text-ink-secondary hover:bg-surface-hover hover:text-ink'
                                }`}
                            >
                                <span>{section.label}</span>
                                <SectionBadge badge={badges?.[section.slug]} />
                            </Link>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

export default function EventWorkspace({
    event,
    sections,
    current,
    hrefFor,
    badges = {},
    phase,
    title,
    actions,
    children,
}) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const switcherRef = useRef(null);
    const currentLabel =
        sections.flatMap((group) => group.sections).find((s) => s.slug === current)?.label ??
        'Overview';

    useEffect(() => {
        if (!sheetOpen) return undefined;
        const onKey = (e) => {
            if (e.key === 'Escape') {
                setSheetOpen(false);
                switcherRef.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [sheetOpen]);

    return (
        <ConsoleLayout compact>
            <div className="flex min-h-full">
                <nav
                    aria-label="Event sections"
                    className="hidden w-56 shrink-0 overflow-y-auto border-r border-border bg-surface lg:sticky lg:top-0 lg:block lg:h-screen"
                >
                    <div className="grid gap-1.5 border-b border-border px-4 pb-3.5 pt-4">
                        <Link
                            href={route('tenant.events.index')}
                            className="inline-flex w-max items-center gap-1 text-xs text-ink-tertiary hover:text-ink"
                        >
                            <ArrowLeft className="h-3 w-3" strokeWidth={2} />
                            All events
                        </Link>
                        <div className="text-[15px] font-semibold leading-snug text-ink">
                            {event.name}
                        </div>
                        <StatusPill event={event} phase={phase} />
                    </div>
                    <SectionList
                        sections={sections}
                        current={current}
                        hrefFor={hrefFor}
                        badges={badges}
                    />
                </nav>

                <div className="min-w-0 flex-1">
                    {/* Below lg: the event and a switcher that names where you are. */}
                    <div className="grid gap-3 border-b border-border px-4 pb-3.5 pt-4 sm:px-8 lg:hidden">
                        <div className="flex items-start justify-between gap-3">
                            <div className="grid gap-1">
                                <div className="text-lg font-semibold leading-snug text-ink">
                                    {event.name}
                                </div>
                                <StatusPill event={event} phase={phase} />
                            </div>
                            {actions && <div className="shrink-0">{actions}</div>}
                        </div>
                        <button
                            ref={switcherRef}
                            type="button"
                            onClick={() => setSheetOpen(true)}
                            aria-haspopup="dialog"
                            aria-expanded={sheetOpen}
                            className="flex w-full items-center justify-between gap-2 rounded-lg border border-border-strong bg-surface px-3 py-2.5 text-sm font-medium text-ink"
                        >
                            <span className="flex items-center gap-2">
                                {currentLabel}
                                <SectionBadge badge={badges?.[current]} />
                            </span>
                            <span className="flex items-center gap-1 text-xs font-normal text-ink-secondary">
                                Sections
                                <ChevronDown className="h-3.5 w-3.5" strokeWidth={2} />
                            </span>
                        </button>
                    </div>

                    <div className="hidden items-end justify-between gap-4 border-b border-border px-8 pb-4 pt-6 lg:flex">
                        <h1 className="text-[26px] font-bold leading-8 tracking-tight text-ink">
                            {title ?? currentLabel}
                        </h1>
                        {actions && <div className="flex items-center gap-3">{actions}</div>}
                    </div>

                    <div className="px-4 py-6 sm:px-8">{children}</div>
                </div>
            </div>

            {sheetOpen && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label="Event sections"
                    className="fixed inset-0 z-35 flex flex-col bg-surface lg:hidden"
                >
                    <div className="flex items-center justify-between border-b border-border px-4 py-3.5">
                        <span className="text-[15px] font-semibold text-ink">Sections</span>
                        <button
                            type="button"
                            onClick={() => {
                                setSheetOpen(false);
                                switcherRef.current?.focus();
                            }}
                            aria-label="Close sections"
                            className="grid h-9 w-9 place-items-center rounded-md text-ink-secondary hover:bg-surface-hover hover:text-ink"
                        >
                            <X className="h-4 w-4" strokeWidth={1.75} />
                        </button>
                    </div>
                    <div className="overflow-y-auto">
                        <SectionList
                            sections={sections}
                            current={current}
                            hrefFor={hrefFor}
                            badges={badges}
                            onNavigate={() => setSheetOpen(false)}
                            large
                        />
                    </div>
                </div>
            )}
        </ConsoleLayout>
    );
}
