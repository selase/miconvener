import TicketPass from './TicketPass';
export default function AttendeeHome({
    history,
    eventLoading = false,
    inbox,
    inboxStatus = 'idle',
    onOpenInbox,
    onRetryInbox,
    duesSummary,
    onSectionChange,
    onOpen,
    eventAccess = true,
    onVerifyEmail,
}) {
    const next = history?.live_now?.[0] || history?.passes?.data?.[0];
    const hour = new Date().getHours();
    return (
        <>
            <p className="text-xs uppercase tracking-widest text-accent">Your personal space</p>
            <h1 className="mt-2 text-3xl font-medium tracking-tight">
                Good {hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening'}.
            </h1>
            <p className="mt-3 text-sm text-ink-secondary">
                Your next gathering. Your communities. All together.
            </p>
            <div className="mt-7 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_280px]">
                <div className="space-y-5">
                    <h2 className="text-lg font-medium">
                        {!eventAccess
                            ? 'Your communities'
                            : history?.live_now?.length
                              ? 'Happening now'
                              : 'Coming up next'}
                    </h2>
                    {!eventAccess ? (
                        <div className="border border-border bg-surface p-6 text-sm text-ink-secondary">
                            <p>
                                Your Community access is ready. Verify your email separately to see
                                event tickets and records.
                            </p>
                            <button
                                type="button"
                                onClick={onVerifyEmail}
                                className="mt-3 min-h-11 text-accent underline"
                            >
                                Verify email for event tickets
                            </button>
                        </div>
                    ) : eventLoading ? (
                        <p
                            role="status"
                            className="border border-border p-6 text-sm text-ink-secondary"
                        >
                            Loading your events…
                        </p>
                    ) : next ? (
                        <TicketPass entry={next} onOpen={onOpen} />
                    ) : (
                        <div className="border border-border bg-surface p-6 text-sm text-ink-secondary">
                            Your upcoming events will appear here. Use the same email when
                            registering.
                        </div>
                    )}
                    {history?.needs_attention?.map((item) => (
                        <div
                            key={item.registration_id}
                            className="border-l-2 border-accent bg-accent/5 p-5"
                        >
                            <p className="text-sm font-medium">{item.event.name}</p>
                            <p className="mt-1 text-xs text-ink-secondary">{item.reason}</p>
                            <a
                                href={item.action.url}
                                className="mt-3 inline-flex min-h-11 items-center text-sm text-accent underline"
                            >
                                {item.action.label} →
                            </a>
                        </div>
                    ))}
                    {history?.has_more_attention && (
                        <button
                            type="button"
                            onClick={() => onSectionChange('tickets')}
                            className="min-h-11 text-sm text-accent underline"
                        >
                            See all tickets requiring attention
                        </button>
                    )}
                    {duesSummary?.totals_by_currency?.map(
                        (row) =>
                            row.open_total > 0 && (
                                <div
                                    key={row.currency}
                                    className="flex flex-wrap items-center justify-between gap-3 border-l-2 border-accent bg-accent/5 p-5"
                                >
                                    <div>
                                        <strong className="text-xl font-medium">
                                            {row.currency} {(row.open_total / 100).toFixed(2)} due
                                        </strong>
                                        <p className="mt-1 text-xs text-ink-secondary">
                                            Your authorised memberships
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => onSectionChange('money')}
                                        className="min-h-11 text-sm text-accent underline"
                                    >
                                        Review dues →
                                    </button>
                                </div>
                            )
                    )}
                </div>
                <aside className="space-y-5">
                    {duesSummary?.giving?.total > 0 && (
                        <section className="border border-border bg-surface p-5">
                            <h2 className="text-lg font-medium">Your contributions</h2>
                            <p className="mt-3 text-sm text-ink-secondary">
                                Revisit your giving, recorded contributions and private receipts.
                            </p>
                            <button
                                type="button"
                                onClick={() => onSectionChange('money')}
                                className="mt-3 min-h-11 text-sm text-accent underline"
                            >
                                View contribution records →
                            </button>
                        </section>
                    )}
                    {(inboxStatus === 'loading' ||
                        inboxStatus === 'error' ||
                        inbox?.some((row) => row.unread > 0)) && (
                        <section
                            aria-label="Community messages"
                            className="border border-border bg-surface p-5"
                        >
                            <h2 className="text-lg font-medium">New messages</h2>
                            {inboxStatus === 'loading' && (
                                <p role="status" className="mt-3 text-sm text-ink-secondary">
                                    Checking your messages…
                                </p>
                            )}
                            {inboxStatus === 'error' && (
                                <div role="alert">
                                    <p className="mt-3 text-sm text-ink-secondary">
                                        Messages could not be loaded.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={onRetryInbox}
                                        className="mt-2 min-h-11 text-sm text-accent underline"
                                    >
                                        Retry messages
                                    </button>
                                </div>
                            )}
                            <ul className="mt-3 divide-y divide-border">
                                {inbox
                                    ?.filter((row) => row.unread > 0)
                                    .map((row) => (
                                        <li key={row.slug}>
                                            <button
                                                type="button"
                                                onClick={() => onOpenInbox(row)}
                                                className="flex min-h-11 w-full items-center justify-between gap-3 py-3 text-left text-sm text-ink"
                                            >
                                                <span className="min-w-0 break-words">
                                                    {row.name}
                                                </span>
                                                <span
                                                    className="border border-accent px-2 py-0.5 text-xs font-medium text-accent"
                                                    aria-label={`${row.unread} unread messages`}
                                                >
                                                    {row.unread > 99 ? '99+' : row.unread}
                                                </span>
                                            </button>
                                        </li>
                                    ))}
                            </ul>
                        </section>
                    )}
                    <section className="border border-border bg-surface p-5">
                        <h2 className="text-lg font-medium">Your communities</h2>
                        <p className="mt-2 text-sm text-ink-secondary">
                            Memberships, organisations you follow, and your joining requests.
                        </p>
                        <button
                            type="button"
                            onClick={() => onSectionChange('communities')}
                            className="mt-3 min-h-11 text-sm text-accent underline"
                        >
                            Open your communities →
                        </button>
                    </section>
                    <section className="border border-border bg-surface p-5">
                        <h2 className="text-lg font-medium">Keep everything close.</h2>
                        <p className="mt-2 text-sm text-ink-secondary">
                            Find certificates, abstract submissions and attendance history in
                            Tickets.
                        </p>
                        <button
                            type="button"
                            onClick={() => onSectionChange('tickets')}
                            className="mt-3 min-h-11 text-sm text-accent underline"
                        >
                            Tickets & records →
                        </button>
                    </section>
                </aside>
            </div>
        </>
    );
}
