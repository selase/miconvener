import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import PollChart from '@/Components/Polls/PollChart';

/**
 * The screen behind the speaker.
 *
 * Read from the back of a room, so everything here is larger and plainer than
 * the console equivalent: no chrome, no controls, one question at a time. It
 * follows whichever poll is live and shows a way in while it waits, because a
 * results screen with nothing on it is a wasted wall.
 *
 * Exactly one screen tall, never taller. Nobody at the back of a hall can
 * scroll a projector, so the question, the chart and the way in all have to fit
 * at once -- the chart takes whatever height is left and packs itself into it.
 */
export default function PollPresentation({
    event,
    organiser,
    poll: initialPoll,
    join,
    resultsUrl,
    eligibleVoters = 0,
}) {
    const [poll, setPoll] = useState(initialPoll);
    const [connected, setConnected] = useState(false);

    // Polling first, so the screen is correct even where a socket never opens.
    useEffect(() => {
        const load = () => {
            fetch(resultsUrl, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((data) => setPoll(data.poll))
                .catch(() => {});
        };

        const interval = setInterval(load, 8000);

        return () => clearInterval(interval);
    }, [resultsUrl]);

    // And the socket on top, because a bar that moves as the room answers is
    // the whole point of putting this on a projector.
    useEffect(() => {
        if (typeof window === 'undefined' || !window.Echo) {
            return undefined;
        }

        const channel = window.Echo.channel(`event.${event.id}.poll`);
        channel.listen('.PollResultsUpdated', (incoming) => {
            setPoll(incoming);
            setConnected(true);
        });

        return () => {
            channel.stopListening('.PollResultsUpdated');
            window.Echo.leave(`event.${event.id}.poll`);
        };
    }, [event.id]);

    const isClosed = poll?.status === 'closed';

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-neutral-950 px-[4vw] py-[4vh] text-white">
            <Head title={`${event.name} — live results`} />

            <header className="flex shrink-0 items-baseline justify-between">
                <div>
                    <p
                        className="uppercase tracking-[0.2em] text-white/40"
                        style={{ fontSize: 'min(1.6vw, 3vh)' }}
                    >
                        {organiser.name}
                    </p>
                    <p className="text-white/70" style={{ fontSize: 'min(1.8vw, 3.4vh)' }}>
                        {event.name}
                    </p>
                </div>
                {poll && (
                    <p className="text-white/50" style={{ fontSize: 'min(1.6vw, 3vh)' }}>
                        {poll.total_responses}{' '}
                        {poll.total_responses === 1 ? 'response' : 'responses'}
                        {connected && <span className="ml-3 text-emerald-400">●</span>}
                    </p>
                )}
            </header>

            {!poll && (
                <div className="flex min-h-0 flex-1 flex-col items-center justify-center text-center">
                    <p className="font-light" style={{ fontSize: 'min(3vw, 6vh)' }}>
                        Waiting for the next question
                    </p>
                    <p className="mt-[2vh] text-white/50" style={{ fontSize: 'min(1.6vw, 3vh)' }}>
                        Join now so you are ready
                    </p>
                    <JoinBlock join={join} large />
                </div>
            )}

            {poll && (
                <main className="mt-[4vh] grid min-h-0 flex-1 grid-cols-[1fr_auto] gap-[4vw]">
                    <div className="flex min-h-0 flex-col">
                        <h1
                            className="line-clamp-3 shrink-0 font-light leading-tight"
                            style={{ fontSize: questionSize(poll.question) }}
                        >
                            {poll.question}
                        </h1>

                        {isClosed && (
                            <p
                                className="mt-[1vh] shrink-0 uppercase tracking-[0.2em] text-amber-300"
                                style={{ fontSize: 'min(1.4vw, 2.6vh)' }}
                            >
                                Voting closed
                            </p>
                        )}

                        <div className="mt-[3vh] min-h-0 flex-1">
                            {poll.total_responses === 0 && eligibleVoters === 0 ? (
                                /* A chart of zeroes and a room that cannot answer
                                   yet look identical from the back of a hall, and
                                   only one of them is something an organiser can
                                   do anything about. */
                                <div>
                                    <p className="text-white/70" style={{ fontSize: 'min(2.2vw, 4.2vh)' }}>
                                        Nobody has checked in yet
                                    </p>
                                    <p className="mt-[1vh] text-white/40" style={{ fontSize: 'min(1.4vw, 2.6vh)' }}>
                                        Delegates answer from their ticket once they are checked in.
                                    </p>
                                </div>
                            ) : (
                                <PollChart poll={poll} />
                            )}
                        </div>
                    </div>

                    <JoinBlock join={join} />
                </main>
            )}
        </div>
    );
}

/**
 * A long question gets a smaller face so it stays on three lines and leaves the
 * chart room, rather than pushing the bars off the bottom of the wall.
 */
function questionSize(question) {
    const length = question?.length ?? 0;

    if (length > 140) {
        return 'min(2.2vw, 4.2vh)';
    }

    if (length > 80) {
        return 'min(2.8vw, 5.2vh)';
    }

    return 'min(3.4vw, 6.4vh)';
}

function JoinBlock({ join, large = false }) {
    return (
        <div className={`text-center ${large ? 'mt-[4vh]' : ''}`}>
            <div
                className="mx-auto rounded-2xl bg-white p-[1vw]"
                style={{ width: large ? 'min(18vw, 34vh)' : 'min(13vw, 26vh)' }}
                dangerouslySetInnerHTML={{ __html: decodeQr(join.qr) }}
            />
            <p className="mt-[1.5vh] text-white/50" style={{ fontSize: 'min(1.2vw, 2.4vh)' }}>
                Scan to join
            </p>
            <p className="mt-[0.5vh] text-white/35" style={{ fontSize: 'min(1.1vw, 2.2vh)' }}>
                {join.url.replace(/^https?:\/\//, '')}
            </p>
        </div>
    );
}

/**
 * The QR arrives as an SVG data URI, which cannot be styled or scaled from
 * inside an <img> the way a wall needs.
 */
function decodeQr(dataUri) {
    if (!dataUri) {
        return '';
    }

    const marker = 'base64,';
    const index = dataUri.indexOf(marker);

    if (index === -1) {
        return decodeURIComponent(dataUri.slice(dataUri.indexOf(',') + 1));
    }

    try {
        return atob(dataUri.slice(index + marker.length));
    } catch {
        return '';
    }
}
