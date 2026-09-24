import { useState, useEffect } from 'react';
import { BarChart2, CheckCircle2, XCircle, AlertCircle, Loader2, Sparkles } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

export default function PollPanel({ registration, isOnline = true }) {
    const [loading, setLoading] = useState(true);
    const [poll, setPoll] = useState(null);
    const [selectedOptionId, setSelectedOptionId] = useState(null);
    const [selectedOptionIds, setSelectedOptionIds] = useState([]);
    const [textResponse, setTextResponse] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);
    const [quizResult, setQuizResult] = useState(null);

    const fetchPoll = async () => {
        try {
            setLoading(true);
            setError(null);
            const res = await fetch(`/my/events/${registration.id}/poll`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                const pollData = data.poll;
                const userResp = pollData?.user_response || data.my_response;
                if (pollData && userResp && !pollData.user_response) {
                    pollData.user_response = userResp;
                }
                setPoll(pollData);
                if (userResp) {
                    const score = userResp.score ?? userResp.points_awarded;
                    if (score !== null && score !== undefined) {
                        setQuizResult({
                            score,
                            is_correct: userResp.is_correct,
                        });
                    }
                }
            } else {
                setError('Unable to load live poll. Please try again.');
            }
        } catch (err) {
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

    const handleOptionToggle = (optionId) => {
        if (poll.allows_multiple) {
            if (selectedOptionIds.includes(optionId)) {
                setSelectedOptionIds(selectedOptionIds.filter((id) => id !== optionId));
            } else {
                setSelectedOptionIds([...selectedOptionIds, optionId]);
            }
        } else {
            setSelectedOptionId(optionId);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!poll) return;

        setSubmitting(true);
        setError(null);

        const payload = {};
        if (poll.allows_multiple) {
            payload.option_ids = selectedOptionIds;
        } else if (selectedOptionId) {
            payload.option_id = selectedOptionId;
        } else if (textResponse.trim()) {
            payload.response_text = textResponse.trim();
            payload.text_response = textResponse.trim();
        }

        try {
            const res = await csrfFetch(`/my/events/${registration.id}/poll/${poll.id}/respond`, {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            const data = await res.json();

            if (!res.ok) {
                setError(data.message || 'Failed to submit response.');
                setSubmitting(false);
                return;
            }

            const score = data.score ?? data.points_awarded;
            if (score !== undefined && score !== null) {
                setQuizResult({
                    score,
                    is_correct: data.is_correct,
                });
            }

            // Reload poll to show updated percentages and user_response
            await fetchPoll();
        } catch (err) {
            setError('A network error occurred while submitting.');
        } finally {
            setSubmitting(false);
        }
    };

    if (!isOnline) {
        return (
            <div className="rounded-xl border border-border bg-surface p-6 text-center text-ink-secondary text-sm">
                <AlertCircle className="mx-auto mb-2 h-6 w-6 text-amber-500" />
                <p>Internet connection required to participate in live polls.</p>
            </div>
        );
    }

    if (loading) {
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
                <h3 className="mt-3 text-base font-medium text-ink">No live poll right now</h3>
                <p className="mt-1.5 text-xs text-ink-secondary">
                    When the organizers activate a poll or quiz, it will be available here.
                </p>
                <button
                    type="button"
                    onClick={fetchPoll}
                    className="mt-4 inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:border-accent cursor-pointer min-h-[44px]"
                >
                    Refresh
                </button>
            </div>
        );
    }

    const userResponse = poll.user_response;
    const hasResponded = Boolean(userResponse);
    const isQuiz = poll.type === 'quiz';

    return (
        <div className="rounded-xl border border-border bg-surface p-6 text-left shadow-sm">
            {/* Header badge & title */}
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span className="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2.5 py-0.5 text-[11px] font-semibold text-accent">
                        {isQuiz ? (
                            <Sparkles className="h-3 w-3" />
                        ) : (
                            <BarChart2 className="h-3 w-3" />
                        )}
                        {isQuiz ? 'Live Quiz' : 'Live Poll'}
                    </span>
                    {poll.allows_multiple && (
                        <span className="text-[11px] text-ink-secondary">
                            (Select all that apply)
                        </span>
                    )}
                </div>
                {poll.total_votes > 0 && (
                    <span className="text-xs text-ink-secondary">
                        {poll.total_votes} {poll.total_votes === 1 ? 'vote' : 'votes'}
                    </span>
                )}
            </div>

            <h2 className="mt-3 text-lg font-semibold tracking-tight text-ink">
                {poll.question || poll.title}
            </h2>

            {error && (
                <div
                    className="mt-4 rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200"
                    role="alert"
                >
                    {error}
                </div>
            )}

            {/* Quiz Result banner if completed */}
            {quizResult && (
                <div
                    className={`mt-4 flex items-center gap-3 rounded-lg border p-4 text-xs ${
                        quizResult.is_correct
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200'
                            : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
                    }`}
                >
                    {quizResult.is_correct ? (
                        <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    ) : (
                        <XCircle className="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    )}
                    <div>
                        <span className="font-semibold">
                            {quizResult.is_correct ? 'Correct answer!' : 'Incorrect.'}
                        </span>
                        {quizResult.score !== null && (
                            <span className="ml-1 text-ink-secondary">
                                You scored {quizResult.score} point(s).
                            </span>
                        )}
                    </div>
                </div>
            )}

            {/* Results View (after response) */}
            {hasResponded ? (
                <div className="mt-6 space-y-3">
                    <p className="text-xs font-medium text-ink-secondary">
                        Thank you for voting! Here are the current results:
                    </p>
                    {poll.options && poll.options.length > 0 ? (
                        poll.options.map((opt) => {
                            const isUserSelected =
                                poll.user_response.option_ids?.includes(opt.id) ||
                                poll.user_response.option_ids === opt.id;
                                userResponse?.option_ids?.includes(opt.id) ||
                                userResponse?.option_ids === opt.id ||
                                userResponse?.option_id === opt.id;
                            return (
                                <div
                                    key={opt.id}
                                    className="relative overflow-hidden rounded-lg border border-border p-3.5"
                                >
                                    <div
                                        className="absolute inset-y-0 left-0 bg-accent-soft transition-all duration-500"
                                        style={{ width: `${opt.percentage}%` }}
                                        aria-hidden="true"
                                    />
                                    <div className="relative flex items-center justify-between text-xs">
                                        <div className="flex items-center gap-2 font-medium text-ink">
                                            {isUserSelected && (
                                                <CheckCircle2 className="h-4 w-4 text-accent shrink-0" />
                                            )}
                                            <span>{opt.label}</span>
                                        </div>
                                        <span className="font-semibold text-ink-secondary shrink-0 ml-2">
                                            {opt.percentage}% ({opt.votes_count})
                                        </span>
                                    </div>
                                </div>
                            );
                        })
                    ) : (
                        <div className="rounded-lg bg-surface-subtle p-3 text-xs text-ink">
                            Your answer:{' '}
                            <span className="font-semibold">
                                {poll.user_response.text_response}
                                {userResponse?.text_response || userResponse?.response_text}
                            </span>
                        </div>
                    )}
                </div>
            ) : (
                /* Voting Form View */
                <form onSubmit={handleSubmit} className="mt-6 space-y-4">
                    {poll.options && poll.options.length > 0 ? (
                        <div className="space-y-2">
                            {poll.options.map((opt) => {
                                const isChecked = poll.allows_multiple
                                    ? selectedOptionIds.includes(opt.id)
                                    : selectedOptionId === opt.id;
                                return (
                                    <label
                                        key={opt.id}
                                        onClick={() => handleOptionToggle(opt.id)}
                                        className={`flex cursor-pointer items-center justify-between rounded-lg border p-3.5 text-xs transition-colors min-h-[44px] ${
                                            isChecked
                                                ? 'border-accent bg-accent-soft/30 text-ink font-medium'
                                                : 'border-border bg-surface hover:bg-surface-subtle text-ink-secondary'
                                        }`}
                                    >
                                        <div className="flex items-center gap-3">
                                            <input
                                                type={poll.allows_multiple ? 'checkbox' : 'radio'}
                                                name="poll_option"
                                                checked={isChecked}
                                                onChange={() => {}}
                                                className="h-4 w-4 text-accent border-border focus:ring-accent"
                                            />
                                            <span className="text-ink">{opt.label}</span>
                                        </div>
                                    </label>
                                );
                            })}
                        </div>
                    ) : (
                        <div>
                            <label className="block text-xs font-medium text-ink mb-1.5">
                                Your Response
                            </label>
                            <textarea
                                value={textResponse}
                                onChange={(e) => setTextResponse(e.target.value)}
                                rows={3}
                                required
                                placeholder="Type your response here..."
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                            />
                        </div>
                    )}

                    <button
                        type="submit"
                        disabled={
                            submitting ||
                            (poll.options?.length > 0 &&
                                (poll.allows_multiple
                                    ? selectedOptionIds.length === 0
                                    : !selectedOptionId)) ||
                            (!poll.options?.length && !textResponse.trim())
                        }
                        className="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-accent px-5 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50 cursor-pointer w-full sm:w-auto"
                    >
                        {submitting ? (
                            <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                Submitting…
                            </>
                        ) : isQuiz ? (
                            'Submit Answer'
                        ) : (
                            'Cast Vote'
                        )}
                    </button>
                </form>
            )}
        </div>
    );
}
