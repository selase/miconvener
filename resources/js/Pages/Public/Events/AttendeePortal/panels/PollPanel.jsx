import { useEffect, useRef, useState } from 'react';
import { AlertCircle, ArrowDown, ArrowUp, BarChart2, CheckCircle2, Loader2, Sparkles, XCircle } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';
import PollChart from '@/Components/Polls/PollChart';

/** Types whose answer is a pick from the poll's options. */
const PICK_ONE = ['multiple_choice', 'quiz', 'yes_no', 'rating'];

/** Types whose results the phone draws with the wall's own chart. */
const CHARTED = ['yes_no', 'rating', 'scale', 'number', 'multi_select', 'word_cloud', 'ranking'];

const LABELS = {
    quiz: 'Live Quiz',
    yes_no: 'Yes or no',
    rating: 'Rating',
    scale: 'Scale',
    number: 'Number',
    multi_select: 'Pick any',
    word_cloud: 'Word cloud',
    ranking: 'Ranking',
};

/**
 * The live question on an attendee's phone: the input its type needs, and once
 * answered, how the room is answering.
 */
export default function PollPanel({ registration, isOnline = true }) {
    const [loading, setLoading] = useState(true);
    const [poll, setPoll] = useState(null);
    const [answer, setAnswer] = useState(emptyAnswer(null));
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [quizResult, setQuizResult] = useState(null);
    const pollId = useRef(null);

    const url = `/my/events/${registration.id}/poll`;

    // A new question gets a fresh answer, and a quiz score from an earlier one
    // must not hang over it.
    const adopt = (next) => {
        if (next?.id !== pollId.current) {
            pollId.current = next?.id ?? null;
            setAnswer(emptyAnswer(next));
            setError(null);
            const mine = next?.user_response;
            const score = mine?.score ?? mine?.points_awarded;
            setQuizResult(mine && score !== null && score !== undefined ? { score, is_correct: mine.is_correct } : null);
        }
        setPoll(next);
    };

    const fetchPoll = async () => {
        try {
            setLoading(true);
            setError(null);
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (res.ok) {
                adopt((await res.json()).poll);
            } else {
                setError('Unable to load live poll. Please try again.');
            }
        } catch {
            setError('Unable to connect. Please check your network.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (isOnline) {
            fetchPoll();
        }
    }, [registration.id, isOnline]);

    // The presenter holds the pace, so the phone follows rather than decides.
    // Five seconds: this is the fallback for a room with no socket, and a
    // question is open for a minute or two at most. While someone is still
    // answering, the same question is left alone so a half-typed answer is not
    // wiped out from under them; once they have answered, it refreshes so they
    // can watch the room.
    useEffect(() => {
        if (!isOnline) return undefined;

        const interval = setInterval(() => {
            fetch(url, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : null))
                .then((data) => {
                    if (!data) return;
                    const next = data.poll;
                    if (next?.id !== pollId.current || next?.user_response) {
                        adopt(next);
                    }
                })
                .catch(() => {});
        }, 5000);

        return () => clearInterval(interval);
    }, [registration.id, isOnline]);

    // A ref, not state: a tap that sends at once can fire twice before state
    // catches up, and the second would be refused as a repeat vote.
    const sending = useRef(false);

    /**
     * @param {Event|null} e
     * @param {object|null} override  an answer to send as-is, for a tap that
     *   picks and sends in one go -- before setAnswer has landed in state
     */
    const submit = async (e, override = null) => {
        e?.preventDefault?.();
        if (!poll || sending.current) return;

        sending.current = true;
        setSubmitting(true);
        setError(null);

        try {
            const res = await csrfFetch(`${url}/${poll.id}/respond`, {
                method: 'POST',
                body: JSON.stringify(override ?? payloadFor(poll, answer)),
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                setError(firstError(data) || 'Failed to submit response.');
                return;
            }

            const score = data.score ?? data.points_awarded;
            if (poll.type === 'quiz' && score !== undefined && score !== null) {
                setQuizResult({ score, is_correct: data.is_correct });
            }

            await fetchPoll();
        } catch {
            setError('A network error occurred while submitting.');
        } finally {
            sending.current = false;
            setSubmitting(false);
        }
    };

    if (!isOnline) {
        return (
            <div className="rounded-xl border border-border bg-surface p-6 text-center text-sm text-ink-secondary">
                <AlertCircle className="mx-auto mb-2 h-6 w-6 text-amber-500" />
                <p>Internet connection required to participate in live polls.</p>
            </div>
        );
    }

    if (loading && !poll) {
        return (
            <div className="flex flex-col items-center justify-center p-12 text-ink-secondary">
                <Loader2 className="h-7 w-7 animate-spin text-accent" />
                <span className="mt-3 text-sm">Checking for live polls…</span>
            </div>
        );
    }

    if (!poll) {
        return (
            <div className="rounded-xl border border-border bg-surface p-8 text-center">
                <BarChart2 className="mx-auto h-8 w-8 text-ink-muted" />
                <h3 className="mt-3 text-base font-medium text-ink">Waiting for the next question</h3>
                <p className="mt-1.5 text-xs text-ink-secondary">This updates on its own when the presenter moves on.</p>
                <button
                    type="button"
                    onClick={fetchPoll}
                    className="mt-4 inline-flex min-h-[44px] cursor-pointer items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:border-accent"
                >
                    Refresh
                </button>
            </div>
        );
    }

    const mine = poll.user_response;
    const isQuiz = poll.type === 'quiz';

    return (
        <div className="rounded-xl border border-border bg-surface p-6 text-left shadow-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2.5 py-0.5 text-[11px] font-semibold text-accent">
                    {isQuiz ? <Sparkles className="h-3 w-3" /> : <BarChart2 className="h-3 w-3" />}
                    {LABELS[poll.type] ?? 'Live Poll'}
                </span>
                {poll.total_votes > 0 && (
                    <span className="text-xs text-ink-secondary">
                        {poll.total_votes} {poll.total_votes === 1 ? 'response' : 'responses'}
                    </span>
                )}
            </div>

            <h2 className="mt-3 text-lg font-semibold tracking-tight text-ink">{poll.question}</h2>

            {error && (
                <div className="mt-4 rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200" role="alert">
                    {error}
                </div>
            )}

            {quizResult && <QuizBanner result={quizResult} />}

            {mine ? (
                <Answered poll={poll} mine={mine} />
            ) : (
                <form onSubmit={submit} className="mt-6 space-y-4">
                    <AnswerInput poll={poll} answer={answer} setAnswer={setAnswer} onPickAndSend={submit} />
                    {!submitsOnTap(poll) && (
                        <button
                            type="submit"
                            disabled={submitting || !isComplete(poll, answer)}
                            className="inline-flex min-h-[44px] w-full cursor-pointer items-center justify-center rounded-lg bg-accent px-5 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50 sm:w-auto"
                        >
                            {submitting ? (
                                <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Submitting…
                                </>
                            ) : isQuiz ? (
                                'Submit Answer'
                            ) : (
                                'Send'
                            )}
                        </button>
                    )}
                </form>
            )}
        </div>
    );
}

function emptyAnswer(poll) {
    const settings = poll?.settings ?? {};
    const min = Number(settings.min ?? 1);
    const max = Number(settings.max ?? 10);
    return {
        optionId: null,
        optionIds: [],
        text: '',
        number: '',
        // A scale starts in the middle, so the slider's first position is not
        // quietly taken as an answer at one end.
        scale: Math.round((min + max) / 2),
        scaleTouched: false,
        words: ['', '', ''],
        order: poll?.type === 'ranking' ? (poll.options ?? []).map((o) => o.id) : [],
    };
}

function submitsOnTap(poll) {
    return poll.type === 'yes_no' || poll.type === 'rating';
}

export function payloadFor(poll, answer) {
    switch (poll.type) {
        case 'open':
            return { response_text: answer.text.trim() };
        case 'scale':
            return { response_number: answer.scale };
        case 'number':
            return { response_number: Number(answer.number) };
        case 'multi_select':
            return { option_ids: answer.optionIds };
        case 'ranking':
            return { option_ids: answer.order };
        case 'word_cloud':
            return { words: answer.words.map((w) => w.trim()).filter(Boolean) };
        default:
            return { option_id: answer.optionId };
    }
}

export function isComplete(poll, answer) {
    switch (poll.type) {
        case 'open':
            return answer.text.trim() !== '';
        case 'scale':
            return answer.scaleTouched;
        case 'number':
            return answer.number !== '' && !Number.isNaN(Number(answer.number));
        case 'multi_select':
            return answer.optionIds.length > 0;
        case 'ranking':
            return answer.order.length === (poll.options ?? []).length;
        case 'word_cloud':
            return answer.words.some((w) => w.trim() !== '');
        default:
            return Boolean(answer.optionId);
    }
}

function firstError(data) {
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        return Array.isArray(first) ? first[0] : first;
    }
    return data?.message;
}

function AnswerInput({ poll, answer, setAnswer, onPickAndSend }) {
    const set = (patch) => setAnswer((current) => ({ ...current, ...patch }));
    const options = poll.options ?? [];

    // Yes/no and rating send on the tap itself: one decision, one touch.
    if (poll.type === 'yes_no') {
        return (
            <div className="grid grid-cols-2 gap-3">
                {options.map((o) => (
                    <button
                        key={o.id}
                        type="button"
                        onClick={() => {
                            set({ optionId: o.id });
                            onPickAndSend(null, { option_id: o.id });
                        }}
                        className={`min-h-[72px] cursor-pointer rounded-xl text-lg font-semibold text-white ${o.label === 'Yes' ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-rose-600 hover:bg-rose-500'}`}
                    >
                        {o.label}
                    </button>
                ))}
            </div>
        );
    }

    if (poll.type === 'rating') {
        return (
            <div className="flex justify-between gap-1" role="radiogroup" aria-label="Rating">
                {options.map((o) => (
                    <button
                        key={o.id}
                        type="button"
                        aria-label={`${o.label} out of ${options.length}`}
                        onClick={() => {
                            set({ optionId: o.id });
                            onPickAndSend(null, { option_id: o.id });
                        }}
                        className="min-h-[56px] flex-1 cursor-pointer rounded-lg border border-border text-3xl text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/30"
                    >
                        ★<span className="block text-[11px] text-ink-secondary">{o.label}</span>
                    </button>
                ))}
            </div>
        );
    }

    if (PICK_ONE.includes(poll.type) || poll.type === 'multi_select') {
        const many = poll.type === 'multi_select';
        return (
            <div className="space-y-2">
                {many && <p className="text-[11px] text-ink-secondary">Pick as many as apply.</p>}
                {options.map((o) => {
                    const checked = many ? answer.optionIds.includes(o.id) : answer.optionId === o.id;
                    return (
                        <label
                            key={o.id}
                            className={`flex min-h-[44px] cursor-pointer items-center gap-3 rounded-lg border p-3.5 text-xs transition-colors ${
                                checked ? 'border-accent bg-accent-soft/30 font-medium text-ink' : 'border-border bg-surface text-ink-secondary hover:bg-surface-subtle'
                            }`}
                        >
                            <input
                                type={many ? 'checkbox' : 'radio'}
                                name="poll_option"
                                checked={checked}
                                onChange={() =>
                                    many
                                        ? set({
                                              optionIds: checked
                                                  ? answer.optionIds.filter((id) => id !== o.id)
                                                  : [...answer.optionIds, o.id],
                                          })
                                        : set({ optionId: o.id })
                                }
                                className="h-4 w-4 border-border text-accent focus:ring-accent"
                            />
                            <span className="text-ink">{o.label}</span>
                        </label>
                    );
                })}
            </div>
        );
    }

    if (poll.type === 'scale') {
        const min = Number(poll.settings?.min ?? 1);
        const max = Number(poll.settings?.max ?? 10);
        return (
            <div>
                <div className="text-center text-3xl font-semibold tabular-nums text-ink">
                    {answer.scaleTouched ? answer.scale : '–'}
                </div>
                <input
                    type="range"
                    min={min}
                    max={max}
                    step={1}
                    value={answer.scale}
                    onChange={(e) => set({ scale: Number(e.target.value), scaleTouched: true })}
                    className="mt-3 w-full accent-[var(--color-accent,#155dfc)]"
                    aria-label="Your answer on the scale"
                />
                <div className="mt-1 flex justify-between text-[11px] text-ink-secondary">
                    <span>
                        {min} {poll.settings?.label_min}
                    </span>
                    <span>
                        {poll.settings?.label_max} {max}
                    </span>
                </div>
            </div>
        );
    }

    if (poll.type === 'number') {
        return (
            <div>
                <label className="mb-1.5 block text-xs font-medium text-ink">
                    Your answer{poll.settings?.unit ? ` (${poll.settings.unit})` : ''}
                </label>
                <input
                    type="number"
                    inputMode="decimal"
                    value={answer.number}
                    onChange={(e) => set({ number: e.target.value })}
                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                />
            </div>
        );
    }

    if (poll.type === 'word_cloud') {
        return (
            <div className="space-y-2">
                <p className="text-[11px] text-ink-secondary">Up to three words or short phrases.</p>
                {answer.words.map((word, i) => (
                    <input
                        key={i}
                        value={word}
                        maxLength={40}
                        onChange={(e) => set({ words: answer.words.map((w, j) => (j === i ? e.target.value : w)) })}
                        placeholder={i === 0 ? 'A word…' : 'Another (optional)'}
                        aria-label={`Word ${i + 1}`}
                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                    />
                ))}
            </div>
        );
    }

    if (poll.type === 'ranking') {
        const byId = Object.fromEntries(options.map((o) => [o.id, o]));
        // Buttons rather than dragging: dependable on any phone, and usable
        // without fine finger control.
        const move = (i, delta) => {
            const order = [...answer.order];
            [order[i], order[i + delta]] = [order[i + delta], order[i]];
            set({ order });
        };
        return (
            <ol className="space-y-2">
                <p className="text-[11px] text-ink-secondary">Put them in order, most important first.</p>
                {answer.order.map((id, i) => (
                    <li key={id} className="flex min-h-[44px] items-center gap-2 rounded-lg border border-border p-2.5 text-xs">
                        <span className="w-5 text-center font-semibold tabular-nums text-accent">{i + 1}</span>
                        <span className="flex-1 text-ink">{byId[id]?.label}</span>
                        <button type="button" onClick={() => move(i, -1)} disabled={i === 0} aria-label={`Move ${byId[id]?.label} up`} className="rounded p-2 text-ink-secondary disabled:opacity-25">
                            <ArrowUp className="h-4 w-4" />
                        </button>
                        <button type="button" onClick={() => move(i, 1)} disabled={i === answer.order.length - 1} aria-label={`Move ${byId[id]?.label} down`} className="rounded p-2 text-ink-secondary disabled:opacity-25">
                            <ArrowDown className="h-4 w-4" />
                        </button>
                    </li>
                ))}
            </ol>
        );
    }

    return (
        <div>
            <label className="mb-1.5 block text-xs font-medium text-ink">Your response</label>
            <textarea
                value={answer.text}
                onChange={(e) => set({ text: e.target.value })}
                rows={3}
                placeholder="Type your response here…"
                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
            />
        </div>
    );
}

/**
 * After answering: the room's results. The new types use the wall's own chart,
 * in a dark card, so what someone sees on their phone matches the screen.
 */
function Answered({ poll, mine }) {
    if (CHARTED.includes(poll.type) && poll.results) {
        return (
            <div className="mt-6">
                <p className="text-xs font-medium text-ink-secondary">Thanks — your answer is in. How the room is answering:</p>
                <div className="mt-3 h-72 rounded-xl bg-neutral-950 p-4 text-white">
                    <PollChart poll={poll.results} />
                </div>
            </div>
        );
    }

    if (poll.options?.length > 0) {
        const picked = new Set([mine.option_id, ...(mine.option_ids ?? [])].filter(Boolean));
        return (
            <div className="mt-6 space-y-3">
                <p className="text-xs font-medium text-ink-secondary">Thank you for voting! Here are the current results:</p>
                {poll.options.map((opt) => (
                    <div key={opt.id} className="relative overflow-hidden rounded-lg border border-border p-3.5">
                        <div className="absolute inset-y-0 left-0 bg-accent-soft transition-all duration-500" style={{ width: `${opt.percentage ?? 0}%` }} aria-hidden="true" />
                        <div className="relative flex items-center justify-between text-xs">
                            <div className="flex items-center gap-2 font-medium text-ink">
                                {picked.has(opt.id) && <CheckCircle2 className="h-4 w-4 shrink-0 text-accent" />}
                                <span>{opt.label}</span>
                            </div>
                            <span className="ml-2 shrink-0 font-semibold text-ink-secondary">
                                {opt.percentage}% ({opt.votes_count})
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="mt-6 rounded-lg bg-surface-subtle p-3 text-xs text-ink">
            Your answer: <span className="font-semibold">{mine.text_response || mine.response_text}</span>
        </div>
    );
}

function QuizBanner({ result }) {
    return (
        <div
            className={`mt-4 flex items-center gap-3 rounded-lg border p-4 text-xs ${
                result.is_correct
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                    : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
            }`}
        >
            {result.is_correct ? <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" /> : <XCircle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />}
            <div>
                <span className="font-semibold">{result.is_correct ? 'Correct answer!' : 'Incorrect.'}</span>
                {result.score !== null && <span className="ml-1 text-ink-secondary">You scored {result.score} point(s).</span>}
            </div>
        </div>
    );
}
