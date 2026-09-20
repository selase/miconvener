import { Link, router } from '@inertiajs/react';
import { Check, X, ScanLine } from 'lucide-react';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';
import { sectionHref } from './sections';

/**
 * What the overview leads with, given where the event is in its life.
 *
 * The section menu stays in the same order whatever the date -- people learn
 * where things are -- so the event's timing shows up here instead: approvals
 * and readiness before the doors open, arrivals and help requests on the day,
 * certificates and exports afterwards.
 */

function money(amount, currency) {
    return amount > 0 ? `${currency} ${(amount / 100).toFixed(2)}` : 'Free';
}

function Card({ title, aside, children }) {
    return (
        <section className="rounded-lg border border-border">
            <header className="flex items-center justify-between gap-3 border-b border-border bg-surface px-4 py-2.5">
                <h3 className="text-[13px] font-semibold text-ink">{title}</h3>
                {aside && <span className="text-xs text-ink-secondary">{aside}</span>}
            </header>
            {children}
        </section>
    );
}

function ChecklistRow({ item, eventId }) {
    return (
        <div className="flex items-center gap-3 border-t border-border px-4 py-2.5 text-[13px] first:border-t-0">
            <span
                aria-hidden="true"
                className={`grid h-[18px] w-[18px] shrink-0 place-items-center rounded-full text-[11px] ${
                    item.done
                        ? 'bg-success-bg text-success-fg'
                        : 'border border-border-strong text-transparent'
                }`}
            >
                ✓
            </span>
            <span className={item.done ? 'text-ink' : 'font-medium text-ink'}>{item.label}</span>
            <span className="ml-auto flex items-center gap-3 text-xs text-ink-tertiary">
                <span>{item.detail}</span>
                {!item.done && item.section && (
                    <Link
                        href={sectionHref(eventId, item.section)}
                        className="font-medium text-accent hover:underline"
                    >
                        Set up
                    </Link>
                )}
            </span>
        </div>
    );
}

function ApprovalQueue({ event, waiting }) {
    const act = async (registration, action, body = {}) => {
        await csrfFetch(
            route(`tenant.events.registrations.${action}`, {
                event: event.id,
                registration: registration.id,
            }),
            { method: 'POST', body: JSON.stringify(body) }
        );
        router.reload();
    };

    return (
        <Card
            title="Waiting for your approval"
            aside={`${waiting.length} ${waiting.length === 1 ? 'person' : 'people'}`}
        >
            {waiting.map((person) => (
                <div
                    key={person.id}
                    className="flex items-center justify-between gap-3 border-t border-border px-4 py-2.5 first:border-t-0"
                >
                    <div className="min-w-0">
                        <div className="text-[13px] font-medium text-ink">{person.full_name}</div>
                        <div className="truncate text-xs text-ink-secondary">
                            {[
                                person.ticket_type_name,
                                money(person.amount, person.currency),
                                person.waiting_since,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </div>
                    </div>
                    <div className="flex shrink-0 gap-1.5">
                        <button
                            type="button"
                            onClick={() => act(person, 'approve')}
                            title={`Approve ${person.full_name}`}
                            className="grid h-8 w-8 place-items-center rounded-md border border-border text-ink-secondary hover:border-success-fg hover:text-success-fg"
                        >
                            <Check className="h-4 w-4" strokeWidth={1.75} />
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                act(person, 'reject', {
                                    note:
                                        window.prompt(
                                            'Reason (optional, shared with the registrant):'
                                        ) || null,
                                })
                            }
                            title={`Decline ${person.full_name}`}
                            className="grid h-8 w-8 place-items-center rounded-md border border-border text-ink-secondary hover:border-danger-fg hover:text-danger-fg"
                        >
                            <X className="h-4 w-4" strokeWidth={1.75} />
                        </button>
                    </div>
                </div>
            ))}
        </Card>
    );
}

export default function OverviewNow({ event, overview }) {
    if (!overview) return null;
    const { phase } = overview;

    if (phase === 'live') {
        const {
            checked_in: checkedIn,
            expected,
            open_requests: requests,
            waiting_approval,
        } = overview;
        const percent = expected > 0 ? Math.round((checkedIn / expected) * 100) : 0;

        return (
            <div className="grid gap-4">
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg bg-success-bg px-4 py-3.5">
                    <div className="text-sm text-success-fg">
                        <b className="num text-base">{checkedIn.toLocaleString()}</b> of{' '}
                        {expected.toLocaleString()} checked in
                    </div>
                    {overview.can_check_in && (
                        <Link
                            href={route('tenant.events.checkin.door', { event: event.id })}
                            className="inline-flex items-center gap-2 rounded-md bg-accent px-4 py-2.5 text-sm font-medium text-accent-ink hover:opacity-90"
                        >
                            <ScanLine className="h-4 w-4" strokeWidth={1.75} />
                            Open check-in
                        </Link>
                    )}
                </div>
                <div
                    className="h-2 overflow-hidden rounded-full bg-surface-sunken"
                    aria-hidden="true"
                >
                    <div
                        className="h-full rounded-full bg-accent"
                        style={{ width: `${percent}%` }}
                    />
                </div>

                {requests.length > 0 && (
                    <Card title="Needs someone now" aside="oldest first">
                        {requests.map((request) => (
                            <div
                                key={request.id}
                                className="flex items-center justify-between gap-3 border-t border-border px-4 py-2.5 text-[13px] first:border-t-0"
                            >
                                <div className="min-w-0">
                                    <div className="font-medium text-ink">
                                        {request.type}
                                        {request.location ? `, ${request.location}` : ''}
                                    </div>
                                    <div className="text-xs text-ink-secondary">
                                        {request.waiting_since}
                                    </div>
                                </div>
                                <Link
                                    href={sectionHref(event.id, 'help-requests')}
                                    className="shrink-0 text-xs font-medium text-accent hover:underline"
                                >
                                    View
                                </Link>
                            </div>
                        ))}
                    </Card>
                )}

                {waiting_approval.length > 0 && (
                    <ApprovalQueue event={event} waiting={waiting_approval} />
                )}
            </div>
        );
    }

    if (phase === 'after') {
        const { attended, expected, wrap_up: wrapUp } = overview;
        const rate = expected > 0 ? Math.round((attended / expected) * 100) : 0;

        return (
            <div className="grid gap-4">
                <div className="rounded-lg border border-border px-4 py-3.5 text-sm text-ink-secondary">
                    <b className="num text-base text-ink">{attended.toLocaleString()}</b> of{' '}
                    {expected.toLocaleString()} attended
                    {expected > 0 && <span className="num"> · {rate}%</span>}
                </div>
                {wrapUp.length > 0 && (
                    <Card title="To wrap up">
                        {wrapUp.map((item) => (
                            <ChecklistRow key={item.key} item={item} eventId={event.id} />
                        ))}
                    </Card>
                )}
            </div>
        );
    }

    const { opens_in_days: days, readiness, waiting_approval } = overview;
    const outstanding = readiness.filter((item) => !item.done).length;

    return (
        <div className="grid gap-4">
            {waiting_approval.length > 0 && (
                <ApprovalQueue event={event} waiting={waiting_approval} />
            )}
            <Card
                title={days > 0 ? `Before the doors open in ${days} days` : 'Before the doors open'}
                aside={
                    outstanding === 0
                        ? 'all done'
                        : `${readiness.length - outstanding} of ${readiness.length} done`
                }
            >
                {readiness.map((item) => (
                    <ChecklistRow key={item.key} item={item} eventId={event.id} />
                ))}
            </Card>
        </div>
    );
}

export function DoorModeLink({ event }) {
    return (
        <Button href={route('tenant.events.checkin.door', { event: event.id })} icon={ScanLine}>
            Door mode
        </Button>
    );
}
