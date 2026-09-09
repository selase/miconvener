import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, CalendarDays } from 'lucide-react';
import ChecklistItem from '@/Components/ChecklistItem';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import ConsoleLayout from '@/Layouts/ConsoleLayout';

function money(minor, currency) {
    return `${currency} ${(minor / 100).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
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

/** A bar chart drawn from the data itself — every bar is a real bucket. */
function Bars({ series, height = 200 }) {
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

function EmptyPanelBody({ children }) {
    return <div className="px-6 pb-6 text-[13px] text-ink-secondary">{children}</div>;
}

export default function Dashboard({
    checklist,
    isLive,
    focusEvent,
    money: purse,
    arrivals,
    registrationTrend,
    needsAPerson,
    upcoming,
    links,
    currency,
}) {
    const { tenant } = usePage().props;
    const setupDone = checklist.onboarding && checklist.team && checklist.branding;

    const when = focusEvent?.starts_at
        ? new Date(focusEvent.starts_at).toLocaleDateString(undefined, {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
          })
        : null;

    const countdown = focusEvent
        ? isLive
            ? 'Happening now'
            : focusEvent.days_until === 0
              ? 'Today'
              : `In ${focusEvent.days_until} day${focusEvent.days_until === 1 ? '' : 's'}`
        : null;

    return (
        <ConsoleLayout>
            <PageHeader
                title={focusEvent ? focusEvent.name : 'Overview'}
                actions={
                    links.event && (
                        <Link
                            href={links.event}
                            className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-[13px] text-ink hover:bg-subtle"
                        >
                            Open event
                            <ArrowUpRight className="h-3.5 w-3.5" strokeWidth={1.9} />
                        </Link>
                    )
                }
            />

            <div className="space-y-6 px-8 py-6">
                <p className="-mt-2 text-[13.5px] text-ink-secondary">
                    {focusEvent
                        ? `${countdown} · ${when} · ${focusEvent.timezone}`
                        : `Nothing scheduled yet for ${tenant?.name ?? 'your organization'}.`}
                </p>

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

                {focusEvent ? (
                    <div className="grid grid-cols-1 rounded-lg border border-border sm:grid-cols-2 lg:grid-cols-4">
                        <Stat
                            label="Registered"
                            value={focusEvent.registered.toLocaleString()}
                            sub={
                                focusEvent.capacity
                                    ? `of ${focusEvent.capacity.toLocaleString()} capacity`
                                    : 'no capacity set'
                            }
                        />
                        <Stat
                            label="Confirmed"
                            value={focusEvent.confirmed.toLocaleString()}
                            sub={`${focusEvent.awaiting_payment.toLocaleString()} awaiting payment`}
                        />
                        <Stat
                            label={isLive ? 'Checked in today' : 'Checked in'}
                            value={focusEvent.checked_in.toLocaleString()}
                            sub={`${focusEvent.check_in_rate}% of confirmed`}
                            emphasis={isLive}
                        />
                        <Stat
                            label="Collected"
                            value={money(purse.collected, currency)}
                            sub={`${money(purse.settles_to_you, currency)} settles to you`}
                        />
                    </div>
                ) : (
                    <div className="rounded-lg border border-border px-6 py-10 text-center">
                        <CalendarDays
                            className="mx-auto h-8 w-8 text-ink-secondary"
                            strokeWidth={1.4}
                        />
                        <p className="mt-3 text-[14px] text-ink">No upcoming events</p>
                        <p className="mt-1 text-[13px] text-ink-secondary">
                            Create one and this page fills with the day's numbers.
                        </p>
                        <Link
                            href={links.events}
                            className="mt-5 inline-flex items-center gap-1.5 rounded-md bg-inverse px-3.5 py-2 text-[13px] text-surface"
                        >
                            Create an event
                        </Link>
                    </div>
                )}

                {focusEvent && (
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        {isLive ? (
                            <Panel title="Arrivals through the gates" note="Half-hour buckets">
                                {arrivals.length > 0 ? (
                                    <Bars series={arrivals} />
                                ) : (
                                    <EmptyPanelBody>Nobody has been scanned in yet.</EmptyPanelBody>
                                )}
                            </Panel>
                        ) : (
                            <Panel title="Registrations" note="Last 30 days">
                                {registrationTrend.some((d) => d.count > 0) ? (
                                    <Bars series={registrationTrend} />
                                ) : (
                                    <EmptyPanelBody>
                                        No registrations yet. Share the event link to start filling
                                        this in.
                                    </EmptyPanelBody>
                                )}
                            </Panel>
                        )}

                        <Panel
                            title="Needs a person"
                            note={needsAPerson.length > 0 ? 'Oldest first' : null}
                        >
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
                                            <StatusPill status={item.status}>{item.tag}</StatusPill>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <EmptyPanelBody>Nothing is waiting on anyone. </EmptyPanelBody>
                            )}
                        </Panel>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
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

                    <Panel
                        title="Also coming up"
                        note={
                            <Link href={links.events} className="text-accent hover:underline">
                                All events
                            </Link>
                        }
                    >
                        {upcoming.length > 0 ? (
                            <div className="divide-y divide-border border-t border-border">
                                {upcoming.map((e) => (
                                    <div
                                        key={e.id}
                                        className="flex items-center justify-between gap-4 px-6 py-3.5"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-[14px] text-ink">
                                                {e.name}
                                            </div>
                                            <div className="text-[12.5px] text-ink-secondary">
                                                {new Date(e.starts_at).toLocaleDateString(
                                                    undefined,
                                                    {
                                                        day: 'numeric',
                                                        month: 'short',
                                                    }
                                                )}
                                                {' · '}
                                                {e.confirmed.toLocaleString()} confirmed
                                                {e.capacity
                                                    ? ` of ${e.capacity.toLocaleString()}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <StatusPill
                                            status={
                                                e.status === 'published' ? 'success' : 'neutral'
                                            }
                                        >
                                            {e.status === 'published' ? 'Published' : 'Draft'}
                                        </StatusPill>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <EmptyPanelBody>Nothing else on the calendar.</EmptyPanelBody>
                        )}
                    </Panel>
                </div>
            </div>
        </ConsoleLayout>
    );
}
