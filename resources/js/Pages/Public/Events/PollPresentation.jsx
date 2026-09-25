import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';

/**
 * The screen behind the speaker.
 *
 * Read from the back of a room, so everything here is larger and plainer than
 * the console equivalent: no chrome, no controls, one question at a time. It
 * follows whichever poll is live and shows a way in while it waits, because a
 * results screen with nothing on it is a wasted wall.
 */
export default function PollPresentation({ event, organiser, poll: initialPoll, join, resultsUrl }) {
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
        <div className="min-h-screen bg-neutral-950 px-[4vw] py-[4vh] text-white">
            <Head title={`${event.name} — live results`} />

            <header className="flex items-baseline justify-between">
                <div>
                    <p className="text-[1.6vw] uppercase tracking-[0.2em] text-white/40">
                        {organiser.name}
                    </p>
                    <p className="text-[1.8vw] text-white/70">{event.name}</p>
                </div>
                {poll && (
                    <p className="text-[1.6vw] text-white/50">
                        {poll.total_responses}{' '}
                        {poll.total_responses === 1 ? 'response' : 'responses'}
                        {connected && <span className="ml-3 text-emerald-400">●</span>}
                    </p>
                )}
            </header>

            {!poll && (
                <div className="flex min-h-[70vh] flex-col items-center justify-center text-center">
                    <p className="text-[3vw] font-light">Waiting for the next question</p>
                    <p className="mt-[2vh] text-[1.6vw] text-white/50">
                        Join now so you are ready
                    </p>
                    <JoinBlock join={join} large />
                </div>
            )}

            {poll && (
                <main className="mt-[5vh] grid grid-cols-[1fr_auto] gap-[4vw]">
                    <div>
                        <h1 className="text-[3.4vw] font-light leading-tight">{poll.question}</h1>

                        {isClosed && (
                            <p className="mt-[1vh] text-[1.4vw] uppercase tracking-[0.2em] text-amber-300">
                                Voting closed
                            </p>
                        )}

                        {poll.type === 'open' ? (
                            <OpenWall responses={poll.open_responses} />
                        ) : (
                            <ol className="mt-[4vh] space-y-[2.5vh]">
                                {poll.options.map((option) => (
                                    <Bar key={option.id} option={option} closed={isClosed} />
                                ))}
                            </ol>
                        )}
                    </div>

                    <JoinBlock join={join} />
                </main>
            )}
        </div>
    );
}

function Bar({ option, closed }) {
    // Green only once voting is closed: colouring the right answer while people
    // are still choosing would give it away from the back of the room.
    const correct = closed && option.is_correct;

    return (
        <li>
            <div className="flex items-baseline justify-between text-[2vw]">
                <span className={correct ? 'text-emerald-300' : 'text-white'}>
                    {option.label}
                    {correct && <span className="ml-3 text-[1.4vw]">correct</span>}
                </span>
                <span className="tabular-nums text-white/60">
                    {option.percentage}% <span className="text-[1.3vw]">({option.count})</span>
                </span>
            </div>
            <div className="mt-[1vh] h-[2.4vh] w-full overflow-hidden rounded-full bg-white/10">
                <div
                    className={`h-full rounded-full transition-[width] duration-700 ease-out ${
                        correct ? 'bg-emerald-400' : 'bg-white'
                    }`}
                    style={{ width: `${Math.max(option.percentage, option.count > 0 ? 2 : 0)}%` }}
                />
            </div>
        </li>
    );
}

function OpenWall({ responses }) {
    if (!responses || responses.length === 0) {
        return (
            <p className="mt-[6vh] text-[1.8vw] text-white/40">
                Answers will appear here as they arrive.
            </p>
        );
    }

    return (
        <ul className="mt-[4vh] grid grid-cols-2 gap-[1.5vw]">
            {responses.map((response) => (
                <li
                    key={response.id}
                    className="rounded-2xl bg-white/5 p-[1.5vw] text-[1.5vw] leading-snug"
                >
                    {response.text}
                    {response.name && (
                        <span className="mt-[1vh] block text-[1.1vw] text-white/40">
                            {response.name}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

function JoinBlock({ join, large = false }) {
    return (
        <div className={`text-center ${large ? 'mt-[4vh]' : ''}`}>
            <div
                className="mx-auto rounded-2xl bg-white p-[1vw]"
                style={{ width: large ? '18vw' : '13vw' }}
                dangerouslySetInnerHTML={{ __html: decodeQr(join.qr) }}
            />
            <p className="mt-[1.5vh] text-[1.2vw] text-white/50">Scan to join</p>
            <p className="mt-[0.5vh] text-[1.1vw] text-white/35">{join.url.replace(/^https?:\/\//, '')}</p>
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
