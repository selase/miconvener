import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, CalendarDays } from 'lucide-react';
import ChecklistItem from '@/Components/ChecklistItem';
import PageHeader from '@/Components/Console/PageHeader';
import StatusDot from '@/Components/Console/StatusDot';
import StatusPill from '@/Components/Console/StatusPill';
import ConsoleLayout from '@/Layouts/ConsoleLayout';

function money(minor, currency) {
    return `${currency} ${(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function shortDate(iso) {
    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function Stat({ label, value, sub, emphasis = false }) {
    return (
        <div className="border-border px-6 py-5 not-last:border-r max-sm:not-last:border-r-0 max-sm:not-last:border-b">
            <div className="text-[13px] text-ink-secondary">{label}</div>
            <div
                className={`mt-1.5 text-[30px] leading-9 font-semibold tracking-tight tabular-nums ${
                    emphasis ? 'text-accent' : 'text-ink'
                }`}
            >
                {value}
            </div>
            {sub && <div className="mt-1 text-[12.5px] text-ink-secondary">{sub}</div>}
        </div>
    );
}

function Panel({ title, note, children, className = '' }) {
    return (
        <div className={`rounded-lg border border-border ${className}`}>
            <div className="flex items-baseline justify-between gap-4 px-6 pt-5 pb-4">
                <h2 className="text-[15px] font-semibold text-ink">{title}</h2>
                {note && <span className="text-[12.5px] text-ink-secondary">{note}</span>}
            </div>
            {children}
        </div>
    );
}

/** Every bar is a real bucket — no decorative padding. */
function Bars({ series, height = 180 }) {
    const peak = Math.max(...series.map((d) => d.count), 1);

    return (
        <div className="px-6 pb-6">
            <div className="flex items-end gap-1" style={{ height }}>
                {series.map((d, i) => (
                    <div
                        key={i}
                        className="group relative flex-1"
                        title={`${d.label} — ${d.count}`}
                    >
                        <div
                            className="w-full rounded-t-sm bg-accent/75 transition-colors group-hover:bg-accent"
                            style={{ height: Math.max(2, Math.round((d.count / peak) * height)) }}
                        />
                    </div>
                ))}
            </div>
            <div className="mt-2.5 flex justify-between text-[12px] text-ink-secondary tabular-nums">
                <span>{series[0]?.label}</span>
                {series.length > 2 && <span>{series[Math.floor(series.length / 2)]?.label}</span>}
                <span>{series[series.length - 1]?.label}</span>
            </div>
        </div>
    );
}

function Empty({ children }) {
    return <div className="px-6 pb-6 text-[13px] text-ink-secondary">{children}</div>;
}

export default function Dashboard({
    checklist,
    totals,
    money: purse,
    liveEvent,
    nextEvent,
    arrivals,
    needsAPerson,
    registrationTrend,
    events,
    links,
    currency,
}) {
    const { tenant } = usePage().props;
    const setupDone = checklist.onboarding && checklist.team && checklist.branding;
    const hasTrend = registrationTrend.some((d) => d.count > 0);

    return (
        <ConsoleLayout>
            <PageHeader
                title="Overview"
                actions={
                    <Link
                        href={links.events}
                        className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-[13px] text-ink hover:bg-subtle"
                    >
                        All events
                        <ArrowUpRight className="h-3.5 w-3.5" strokeWidth={1.9} />
                    </Link>
                }
            />

            <div className="space-y-6 px-8 py-6">
                <p className="-mt-2 text-[13.5px] text-ink-secondary">
                    {totals.events > 0
                        ? `${tenant?.name ?? 'Your organization'} · ${totals.upcoming_events} upcoming of ${totals.events} event${totals.events === 1 ? '' : 's'}`
                        : `Nothing scheduled yet for ${tenant?.name ?? 'your organization'}.`}
                </p>

                {/* Everything the tenant runs, before any one event. */}
                <div className="grid grid-cols-1 rounded-lg border border-border sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Upcoming events"
                        value={totals.upcoming_events.toLocaleString()}
                        sub={`${totals.events.toLocaleString()} in total`}
                    />
                    <Stat
                        label="Confirmed registrations"
                        value={totals.registrations.toLocaleString()}
                        sub={`${totals.checked_in.toLocaleString()} checked in`}
                    />
                    <Stat
                        label="Collected"
                        value={money(purse.collected, currency)}
                        sub={`${money(purse.commission, currency)} platform commission`}
                    />
                    <Stat
                        label="Awaiting payout"
                        value={money(purse.awaiting_payout, currency)}
                        sub={`${money(purse.settles_to_you, currency)} settles to you`}
                    />
                </div>

                {/* The event-day view, only while something is actually running. */}
                {liveEvent && (
                    <div className="rounded-lg border border-accent/35 bg-accent/[0.04]">
                        <div className="flex flex-wrap items-center justify-between gap-3 px-6 pt-5 pb-4">
                            <div className="flex items-center gap-2.5">
                                <StatusDot status="success" />
                                <h2 className="text-[15px] font-semibold text-ink">
                                    Happening now — {liveEvent.name}
                                </h2>
                            </div>
                            <Link
                                href={links.liveEvent}
                                className="text-[13px] text-accent hover:underline"
                            >
                                Open event →
                            </Link>
                        </div>

                        <div className="grid grid-cols-1 border-t border-accent/25 sm:grid-cols-3">
                            <Stat
                                label="Registered"
                                value={liveEvent.registered.toLocaleString()}
                                sub={
                                    liveEvent.capacity
                                        ? `of ${liveEvent.capacity.toLocaleString()} capacity`
                                        : 'no capacity set'
                                }
                            />
                            <Stat
                                label="Confirmed"
                                value={liveEvent.confirmed.toLocaleString()}
                                sub={`${liveEvent.awaiting_payment.toLocaleString()} awaiting payment`}
                            />
                            <Stat
                                label="Checked in"
                                value={liveEvent.checked_in.toLocaleString()}
                                sub={`${liveEvent.check_in_rate}% of confirmed`}
                                emphasis
                            />
                        </div>

                        <div className="grid grid-cols-1 gap-6 border-t border-accent/25 p-6 lg:grid-cols-2">
                            <div className="rounded-lg border border-border bg-surface">
                                <div className="flex items-baseline justify-between px-6 pt-5 pb-4">
                                    <h3 className="text-[14px] font-semibold text-ink">
                                        Arrivals through the gates
                                    </h3>
                                    {arrivals.step > 0 && (
                                        <span className="text-[12.5px] text-ink-secondary">
                                            {arrivals.step}-minute buckets
                                        </span>
                                    )}
                                </div>
                                {arrivals.series.length > 0 ? (
                                    <Bars series={arrivals.series} />
                                ) : (
                                    <Empty>Nobody has been scanned in yet.</Empty>
                                )}
                            </div>

                            <div className="rounded-lg border border-border bg-surface">
                                <div className="flex items-baseline justify-between px-6 pt-5 pb-4">
                                    <h3 className="text-[14px] font-semibold text-ink">
                                        Needs a person
                                    </h3>
                                    {needsAPerson.length > 0 && (
                                        <span className="text-[12.5px] text-ink-secondary">
                                            Oldest first
                                        </span>
                                    )}
                                </div>
                                {needsAPerson.length > 0 ? (
                                    <div className="divide-y divide-border border-t border-border">
                                        {needsAPerson.map((item) => (
                                            <div
                                                key={`${item.kind}-${item.id}`}
                                                className="flex items-start gap-4 px-6 py-3.5"
                                            >
                                                <span className="w-12 shrink-0 pt-0.5 text-[12.5px] text-warning-fg tabular-nums">
                                                    {item.waited}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="truncate text-[14px] text-ink">
                                                        {item.title}
                                                    </div>
                                                    {item.detail && (
                                                        <div className="truncate text-[12.5px] text-ink-secondary">
                                                            {item.detail}
                                                        </div>
                                                    )}
                                                </div>
                                                <StatusPill status={item.status}>
                                                    {item.tag}
                                                </StatusPill>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <Empty>Nothing is waiting on anyone.</Empty>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {!liveEvent && nextEvent && (
                    <Panel
                        title="Next up"
                        note={
                            <Link href={links.nextEvent} className="text-accent hover:underline">
                                Open event
                            </Link>
                        }
                    >
                        <div className="grid grid-cols-1 border-t border-border sm:grid-cols-3">
                            <Stat
                                label={nextEvent.name}
                                value={
                                    nextEvent.days_until === 0
                                        ? 'Today'
                                        : `${nextEvent.days_until} day${nextEvent.days_until === 1 ? '' : 's'}`
                                }
                                sub={nextEvent.starts_at ? shortDate(nextEvent.starts_at) : null}
                            />
                            <Stat
                                label="Registered"
                                value={nextEvent.registered.toLocaleString()}
                                sub={
                                    nextEvent.capacity
                                        ? `of ${nextEvent.capacity.toLocaleString()} capacity`
                                        : 'no capacity set'
                                }
                            />
                            <Stat
                                label="Awaiting payment"
                                value={nextEvent.awaiting_payment.toLocaleString()}
                                sub={`${nextEvent.confirmed.toLocaleString()} confirmed`}
                            />
                        </div>
                    </Panel>
                )}

                {/* The list, reachable directly — the thing the dashboard was missing. */}
                <Panel
                    title="Events"
                    note={events.length > 0 ? `${events.length} most recent` : null}
                >
                    {events.length > 0 ? (
                        <div className="divide-y divide-border border-t border-border">
                            {events.map((e) => (
                                <Link
                                    key={e.id}
                                    href={e.url}
                                    className="flex items-center gap-4 px-6 py-3.5 hover:bg-subtle"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[14px] text-ink">
                                            {e.name}
                                        </div>
                                        <div className="text-[12.5px] text-ink-secondary">
                                            {e.starts_at ? shortDate(e.starts_at) : '—'}
                                            {' · '}
                                            {e.registered.toLocaleString()} registered
                                            {e.capacity ? ` of ${e.capacity.toLocaleString()}` : ''}
                                            {e.checked_in > 0
                                                ? ` · ${e.checked_in.toLocaleString()} checked in`
                                                : ''}
                                        </div>
                                    </div>
                                    <div className="hidden text-right text-[13.5px] text-ink tabular-nums sm:block">
                                        {money(e.collected, currency)}
                                    </div>
                                    <StatusPill
                                        status={
                                            e.is_live
                                                ? 'success'
                                                : e.status === 'published'
                                                  ? 'neutral'
                                                  : 'pending'
                                        }
                                    >
                                        {e.is_live
                                            ? 'Live'
                                            : e.is_past
                                              ? 'Past'
                                              : e.status === 'published'
                                                ? 'Published'
                                                : 'Draft'}
                                    </StatusPill>
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <div className="px-6 pb-8 text-center">
                            <CalendarDays
                                className="mx-auto h-8 w-8 text-ink-secondary"
                                strokeWidth={1.4}
                            />
                            <p className="mt-3 text-[14px] text-ink">No events yet</p>
                            <p className="mt-1 text-[13px] text-ink-secondary">
                                Create one and this page fills with its numbers.
                            </p>
                            <Link
                                href={links.events}
                                className="mt-5 inline-flex items-center gap-1.5 rounded-md bg-inverse px-3.5 py-2 text-[13px] text-surface"
                            >
                                Create an event
                            </Link>
                        </div>
                    )}
                </Panel>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Panel title="Registrations" note="Across all events, last 30 days">
                        {hasTrend ? (
                            <Bars series={registrationTrend} />
                        ) : (
                            <Empty>No registrations in the last 30 days.</Empty>
                        )}
                    </Panel>

                    <Panel
                        title="Money"
                        note={
                            <Link href={links.finance} className="text-accent hover:underline">
                                Finance
                            </Link>
                        }
                    >
                        <dl className="divide-y divide-border border-t border-border">
                            {[
                                ['Collected', money(purse.collected, currency)],
                                ['Platform commission', money(purse.commission, currency)],
                                ['Refunded', money(purse.refunded, currency)],
                                ['Awaiting payout', money(purse.awaiting_payout, currency)],
                            ].map(([k, v]) => (
                                <div
                                    key={k}
                                    className="flex items-center justify-between px-6 py-3"
                                >
                                    <dt className="text-[13.5px] text-ink-secondary">{k}</dt>
                                    <dd className="text-[14px] text-ink tabular-nums">{v}</dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>
                </div>

                {!setupDone && (
                    <div className="rounded-lg border border-border bg-surface p-6">
                        <h2 className="text-[15px] font-semibold text-ink">Finish setting up</h2>
                        <div className="mt-4 flex flex-wrap gap-8">
                            <ChecklistItem
                                done={checklist.branding}
                                number={1}
                                href={links.branding}
                                label="Customize branding"
                            />
                            <ChecklistItem
                                done={checklist.team}
                                number={2}
                                href={links.team}
                                label="Add your team"
                            />
                            <ChecklistItem
                                done={checklist.onboarding}
                                number={3}
                                label="Mark as setup complete"
                                onClick={() => router.post(links.finishOnboarding)}
                            />
                        </div>
                    </div>
                )}
            </div>
        </ConsoleLayout>
    );
}
