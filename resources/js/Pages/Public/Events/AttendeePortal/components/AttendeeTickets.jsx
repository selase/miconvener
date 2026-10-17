import TicketPass from './TicketPass';
export default function AttendeeTickets({
    history,
    offlineTickets = [],
    onFilterChange,
    onRecord,
    onOpen,
    period = 'upcoming',
    offline = false,
}) {
    const passes = history?.passes;
    return (
        <>
            <p className="text-xs font-medium uppercase tracking-widest text-accent">
                Tickets & records
            </p>
            <h1 className="mt-2 text-3xl font-medium tracking-tight">Your next good experience.</h1>
            <p className="mt-3 text-sm text-ink-secondary">
                Keep your entry pass close. Find your event records here.
            </p>
            <div className="my-6 flex flex-wrap gap-2">
                {[
                    ['upcoming', 'Upcoming'],
                    ['past', 'Past'],
                    ['saved', 'Saved offline'],
                ].map(([key, label]) => (
                    <button
                        type="button"
                        key={key}
                        disabled={offline && key !== 'saved'}
                        onClick={() => onFilterChange(key, 1)}
                        aria-pressed={period === key}
                        className={`min-h-11 border px-4 py-2 text-xs disabled:opacity-50 ${period === key ? 'border-accent bg-accent/10 text-accent' : 'border-border bg-surface text-ink-secondary'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {period === 'saved' ? (
                <div className="space-y-4">
                    {offlineTickets.length ? (
                        offlineTickets.map((pass) => (
                            <article
                                key={pass.registration_id}
                                className="border border-border bg-surface p-5"
                            >
                                <h2 className="text-xl font-medium">{pass.event_name}</h2>
                                <p className="my-2 text-sm text-ink-secondary">
                                    {pass.attendee_name} · {pass.ticket.type}
                                </p>
                                <img
                                    src={pass.qr_image}
                                    alt="Ticket QR code"
                                    className="h-40 w-40 bg-white p-2"
                                />
                                <p className="mt-3 text-xs text-ink-secondary">
                                    Last verified{' '}
                                    {new Date(pass.last_confirmed_at).toLocaleString()}. Reconnect
                                    when verification expires.
                                </p>
                            </article>
                        ))
                    ) : (
                        <p className="border border-border bg-surface p-6 text-sm text-ink-secondary">
                            No saved tickets on this device. Open a confirmed ticket while online to
                            save its pass.
                        </p>
                    )}
                </div>
            ) : (
                <div className="grid gap-5 lg:grid-cols-2">
                    {passes?.data?.length ? (
                        passes.data.map((entry) => (
                            <TicketPass key={entry.registration_id} entry={entry} onOpen={onOpen} />
                        ))
                    ) : (
                        <p className="border border-border bg-surface p-6 text-sm text-ink-secondary">
                            No {period} tickets found. Register for an event using your verified
                            email to see it here.
                        </p>
                    )}
                </div>
            )}
            {period !== 'saved' && passes?.last_page > 1 && (
                <nav aria-label="Ticket pages" className="mt-5 flex items-center gap-4">
                    <button
                        type="button"
                        className="min-h-11 text-sm underline disabled:opacity-40"
                        disabled={passes.current_page <= 1}
                        onClick={() => onFilterChange(period, passes.current_page - 1)}
                    >
                        Previous
                    </button>
                    <span className="text-xs text-ink-secondary">
                        Page {passes.current_page} of {passes.last_page}
                    </span>
                    <button
                        type="button"
                        className="min-h-11 text-sm underline disabled:opacity-40"
                        disabled={passes.current_page >= passes.last_page}
                        onClick={() => onFilterChange(period, passes.current_page + 1)}
                    >
                        Next
                    </button>
                </nav>
            )}
            {!offline && (
                <section className="mt-7 border-t border-border pt-5">
                    <h2 className="mb-3 text-lg font-medium">Your records</h2>
                    <div className="flex flex-wrap gap-5">
                        {[
                            ['certificates', 'Certificates'],
                            ['abstracts', 'Abstracts'],
                            ['attendance', 'Attendance history'],
                        ].map(([key, label]) => (
                            <button
                                type="button"
                                key={key}
                                onClick={() => onRecord(key)}
                                className="min-h-11 text-sm text-accent underline underline-offset-4"
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </section>
            )}
        </>
    );
}
