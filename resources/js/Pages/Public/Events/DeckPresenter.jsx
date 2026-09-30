import { useCallback, useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import PollChart from '@/Components/Polls/PollChart';
import csrfFetch from '@/lib/csrfFetch';

/**
 * The presenter's own screen for one deck.
 *
 * Built for a laptop at a lectern, driven by whoever holds the link -- often a
 * speaker with no console account. It shows what the room is looking at, how
 * far through the deck they are, and what comes next, and it answers to a
 * presentation clicker as readily as to a mouse, since that is what is in the
 * presenter's hand.
 *
 * Like the wall, it is exactly one screen tall.
 */
export default function DeckPresenter({ event, organiser, deck: initialDeck, stateUrl, actionUrl, wallUrl }) {
    const [deck, setDeck] = useState(initialDeck);
    const [error, setError] = useState(null);
    const [confirmingEnd, setConfirmingEnd] = useState(false);
    const busy = useRef(false);
    const [pending, setPending] = useState(false);

    const act = useCallback(
        async (action) => {
            // One press at a time. A clicker double-fires more often than a
            // mouse, and two advances in a row skip a question.
            if (busy.current) {
                return;
            }
            busy.current = true;
            setPending(true);
            setError(null);

            try {
                const response = await csrfFetch(`${actionUrl}/${action}`, {
                    method: 'POST',
                    body: JSON.stringify({}),
                });
                const data = await response.json().catch(() => null);

                if (response.ok && data) {
                    setDeck(data);
                } else if (response.status === 404) {
                    setError('This presenter link has been replaced. Ask the organiser for the new one.');
                } else {
                    setError(data?.message || 'That did not go through. Try again.');
                }
            } catch {
                setError('Lost the connection. Check the network and try again.');
            } finally {
                busy.current = false;
                setPending(false);
            }
        },
        [actionUrl]
    );

    // Re-read the deck in case it moved elsewhere -- an organiser pressing Next
    // in the console, or a second presenter screen. Three seconds, because this
    // is the screen someone is actively driving.
    useEffect(() => {
        const interval = setInterval(() => {
            fetch(stateUrl, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => data && setDeck(data))
                .catch(() => {});
        }, 3000);

        return () => clearInterval(interval);
    }, [stateUrl]);

    // Live counts as the room answers, when a socket is available.
    useEffect(() => {
        if (typeof window === 'undefined' || !window.Echo) {
            return undefined;
        }

        const name = `event.${event.id}.poll`;
        const channel = window.Echo.channel(name);
        channel.listen('.PollResultsUpdated', (incoming) => {
            setDeck((current) =>
                current.current?.id === incoming.id ? { ...current, current: incoming } : current
            );
        });

        return () => {
            channel.stopListening('.PollResultsUpdated');
            window.Echo.leave(name);
        };
    }, [event.id]);

    // Presentation clickers send PageDown and PageUp; keyboards send arrows.
    useEffect(() => {
        const onKey = (e) => {
            // A focused button already answers Space and Enter itself, and
            // handling them here as well would fire the action twice.
            if (e.target.closest?.('button, a, input, textarea, select')) {
                return;
            }

            if (['ArrowRight', 'PageDown', ' '].includes(e.key)) {
                e.preventDefault();
                if (deck.status === 'draft') {
                    act('start');
                } else if (deck.status === 'live' && deck.can_advance) {
                    act('advance');
                }
            } else if (['ArrowLeft', 'PageUp'].includes(e.key)) {
                e.preventDefault();
                if (deck.status === 'live' && deck.can_go_back) {
                    act('previous');
                }
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [act, deck.status, deck.can_advance, deck.can_go_back]);

    // Ending cannot be undone, so it takes a second press within a few seconds.
    useEffect(() => {
        if (!confirmingEnd) {
            return undefined;
        }
        const timer = setTimeout(() => setConfirmingEnd(false), 4000);

        return () => clearTimeout(timer);
    }, [confirmingEnd]);

    const endDeck = () => {
        if (!confirmingEnd) {
            setConfirmingEnd(true);
            return;
        }
        setConfirmingEnd(false);
        act('end');
    };

    const current = deck.current;

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-neutral-950 text-white">
            <Head title={`${deck.title} — presenter`} />

            <header className="flex shrink-0 items-center justify-between gap-6 border-b border-white/10 px-8 py-4">
                <div className="min-w-0">
                    <p className="truncate text-xs uppercase tracking-[0.2em] text-white/40">
                        {organiser.name} · {event.name}
                    </p>
                    <p className="truncate text-lg">{deck.title}</p>
                </div>
                <div className="flex shrink-0 items-center gap-5 text-sm text-white/60">
                    {deck.status === 'live' && (
                        <span className="tabular-nums">
                            Question {deck.position} of {deck.total}
                        </span>
                    )}
                    {current && (
                        <span className="tabular-nums">
                            {current.total_responses}{' '}
                            {current.total_responses === 1 ? 'response' : 'responses'}
                        </span>
                    )}
                    {wallUrl && (
                        <a
                            href={wallUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded border border-white/20 px-3 py-1.5 text-white/80 hover:border-white/50"
                        >
                            Open the wall ↗
                        </a>
                    )}
                </div>
            </header>

            {deck.status === 'draft' && (
                <Waiting
                    title="Ready when you are"
                    detail={`${deck.total} question${deck.total === 1 ? '' : 's'} in this deck. Start opens the first one to the room.`}
                    questions={deck.questions}
                />
            )}

            {deck.status === 'ended' && (
                <Waiting
                    title="This deck has ended"
                    detail="Voting is closed on every question. The results stay in the organiser's console."
                    questions={deck.questions}
                />
            )}

            {deck.status === 'live' && (
                <main className="grid min-h-0 flex-1 grid-cols-[1fr_18rem] gap-8 px-8 py-6">
                    <section className="flex min-h-0 flex-col">
                        {current ? (
                            <>
                                <h1 className="line-clamp-2 shrink-0 text-3xl font-light leading-tight">
                                    {current.question}
                                </h1>
                                {current.status === 'closed' && (
                                    <p className="mt-2 shrink-0 text-xs uppercase tracking-[0.2em] text-amber-300">
                                        Voting closed
                                    </p>
                                )}
                                <div className="mt-6 min-h-0 flex-1">
                                    <PollChart poll={current} />
                                </div>
                            </>
                        ) : (
                            <p className="text-white/50">No question is open.</p>
                        )}
                    </section>

                    <aside className="flex min-h-0 flex-col gap-6 border-l border-white/10 pl-8">
                        <div>
                            <p className="text-xs uppercase tracking-[0.2em] text-white/40">Next up</p>
                            <p className="mt-2 text-base text-white/80">
                                {deck.next ? deck.next.question : 'That was the last question.'}
                            </p>
                        </div>
                        <ol className="min-h-0 space-y-1.5 overflow-hidden text-sm">
                            {deck.questions.map((question, index) => (
                                <li
                                    key={question + index}
                                    className={`truncate ${
                                        index + 1 === deck.position ? 'text-white' : 'text-white/35'
                                    }`}
                                >
                                    <span className="mr-2 tabular-nums">{index + 1}.</span>
                                    {question}
                                </li>
                            ))}
                        </ol>
                    </aside>
                </main>
            )}

            <footer className="shrink-0 border-t border-white/10 px-8 py-4">
                {error && <p className="mb-3 text-sm text-red-300">{error}</p>}

                <div className="flex items-center justify-between gap-4">
                    <div className="flex gap-3">
                        {deck.status === 'live' && (
                            <>
                                <ControlButton onClick={() => act('previous')} disabled={pending || !deck.can_go_back}>
                                    ← Back
                                </ControlButton>
                                <ControlButton
                                    onClick={() => act('close')}
                                    disabled={pending || !current || current.status === 'closed'}
                                >
                                    Close voting
                                </ControlButton>
                                <ControlButton onClick={endDeck} disabled={pending} tone={confirmingEnd ? 'danger' : 'plain'}>
                                    {confirmingEnd ? 'Press again to end' : 'End deck'}
                                </ControlButton>
                            </>
                        )}
                    </div>

                    <div className="flex items-center gap-4">
                        <p className="hidden text-xs text-white/35 md:block">
                            Arrow keys or a presentation clicker also work
                        </p>
                        {deck.status === 'draft' && (
                            <ControlButton onClick={() => act('start')} disabled={pending || deck.total === 0} tone="primary">
                                Start deck →
                            </ControlButton>
                        )}
                        {deck.status === 'live' && (
                            <ControlButton onClick={() => act('advance')} disabled={pending || !deck.can_advance} tone="primary">
                                Next question →
                            </ControlButton>
                        )}
                    </div>
                </div>
            </footer>
        </div>
    );
}

function Waiting({ title, detail, questions }) {
    return (
        <main className="flex min-h-0 flex-1 flex-col items-center justify-center px-8 text-center">
            <p className="text-4xl font-light">{title}</p>
            <p className="mt-3 max-w-xl text-white/50">{detail}</p>
            {questions.length > 0 && (
                <ol className="mt-8 max-h-[40vh] w-full max-w-xl space-y-1.5 overflow-hidden text-left text-sm text-white/45">
                    {questions.map((question, index) => (
                        <li key={question + index} className="truncate">
                            <span className="mr-2 tabular-nums">{index + 1}.</span>
                            {question}
                        </li>
                    ))}
                </ol>
            )}
        </main>
    );
}

function ControlButton({ onClick, disabled, tone = 'plain', children }) {
    const tones = {
        plain: 'border border-white/20 text-white hover:border-white/50',
        primary: 'bg-white text-neutral-950 hover:bg-white/90',
        danger: 'border border-red-400 bg-red-500/15 text-red-200',
    };

    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={`rounded-md px-5 py-2.5 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-35 ${tones[tone]}`}
        >
            {children}
        </button>
    );
}
