import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import StatusPill from '@/Components/Console/StatusPill';
import { Trash2, Plus, X, Check, Trophy } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';
import PollChart from '@/Components/Polls/PollChart';

const STATUS_TONE = { draft: 'neutral', live: 'success', closed: 'failed' };

/** Types where the organiser writes the options. Yes/no and rating fix their own. */
const AUTHORED_OPTION_TYPES = ['multiple_choice', 'quiz', 'multi_select', 'ranking'];

/** Types the console draws with the wall's own chart. */
const CHARTED_TYPES = ['yes_no', 'rating', 'scale', 'number', 'multi_select', 'word_cloud', 'ranking'];

const TYPE_LABELS = {
    quiz: 'Quiz',
    yes_no: 'Yes / No',
    rating: 'Rating',
    scale: 'Scale',
    number: 'Number',
    multi_select: 'Multiple select',
    word_cloud: 'Word cloud',
    ranking: 'Ranking',
};

function NewPollForm({ event, onCreated }) {
    const [type, setType] = useState('multiple_choice');
    const [question, setQuestion] = useState('');
    const [options, setOptions] = useState(['', '']);
    const [correctIndex, setCorrectIndex] = useState(0);
    const [timerSeconds, setTimerSeconds] = useState(20);
    const [points, setPoints] = useState(10);
    const [requiresModeration, setRequiresModeration] = useState(true);
    const [scaleMin, setScaleMin] = useState(1);
    const [scaleMax, setScaleMax] = useState(10);
    const [labelMin, setLabelMin] = useState('');
    const [labelMax, setLabelMax] = useState('');
    const [unit, setUnit] = useState('');
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const authored = AUTHORED_OPTION_TYPES.includes(type);
    const moderated = type === 'open' || type === 'word_cloud';

    const setOption = (i, value) =>
        setOptions((prev) => prev.map((o, idx) => (idx === i ? value : o)));
    const addOption = () => setOptions((prev) => [...prev, '']);
    const removeOption = (i) => setOptions((prev) => prev.filter((_, idx) => idx !== i));

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);

        setFormError(null);

        const response = await csrfFetch(route('tenant.events.polls.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({
                question,
                type,
                options: authored ? options.filter((o) => o.trim() !== '') : undefined,
                correct_option_index: type === 'quiz' ? correctIndex : undefined,
                timer_seconds: type === 'quiz' ? timerSeconds : undefined,
                points: type === 'quiz' ? points : undefined,
                requires_moderation: moderated ? requiresModeration : undefined,
                settings:
                    type === 'scale'
                        ? { min: Number(scaleMin), max: Number(scaleMax), label_min: labelMin, label_max: labelMax }
                        : type === 'number'
                          ? { unit }
                          : undefined,
            }),
        });

        if (response.status === 403) {
            const data = await response.json().catch(() => ({}));
            setError(data.message || 'Live Polling requires the Growth plan or an active add-on/pass.');
            setSaving(false);
            return;
        }

        setSaving(false);

        // A refused question stays in the form with the reason, rather than
        // vanishing as though it had been created.
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const first = data.errors ? Object.values(data.errors)[0] : null;
            setFormError((Array.isArray(first) ? first[0] : first) || data.message || 'Could not create that question.');
            return;
        }
        setQuestion('');
        setOptions(['', '']);
        setCorrectIndex(0);
        onCreated();
    };

    return (
        <form onSubmit={submit} className="border border-border p-4">
            <b className="text-sm font-medium text-ink">New poll or quiz question</b>
            {error && (
                <div className="mt-2 p-3 rounded border border-warning-fg/30 bg-warning-bg text-sm text-warning-fg flex flex-col gap-1.5">
                    <span>{error}</span>
                    <a
                        href={route('billing.addons.index')}
                        className="font-semibold underline text-ink hover:text-accent"
                    >
                        Unlock via Modular Add-Ons or Single-Event Pass →
                    </a>
                </div>
            )}
            <div className="mt-3 space-y-3">
                <Input
                    label="Question"
                    value={question}
                    onChange={(e) => setQuestion(e.target.value)}
                    required
                />
                <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
                    <option value="multiple_choice">Multiple choice — pick one</option>
                    <option value="multi_select">Multiple select — pick any</option>
                    <option value="yes_no">Yes / No</option>
                    <option value="rating">Rating — 1 to 5 stars</option>
                    <option value="scale">Scale — a point between two ends</option>
                    <option value="number">Number</option>
                    <option value="word_cloud">Word cloud</option>
                    <option value="ranking">Ranking — put options in order</option>
                    <option value="open">Open response</option>
                    <option value="quiz">Quiz (timed, auto-graded)</option>
                </Select>

                {type === 'scale' && (
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Lowest value" type="number" min="0" max="99" value={scaleMin} onChange={(e) => setScaleMin(e.target.value)} />
                        <Input label="Highest value" type="number" min="1" max="100" value={scaleMax} onChange={(e) => setScaleMax(e.target.value)} />
                        <Input label="Label at the low end" placeholder="e.g. Not at all" value={labelMin} onChange={(e) => setLabelMin(e.target.value)} />
                        <Input label="Label at the high end" placeholder="e.g. Completely" value={labelMax} onChange={(e) => setLabelMax(e.target.value)} />
                    </div>
                )}

                {type === 'number' && (
                    <Input label="Unit (optional)" placeholder="e.g. beds, years, GHS" value={unit} onChange={(e) => setUnit(e.target.value)} />
                )}

                {['rating', 'scale', 'number'].includes(type) && (
                    <p className="text-xs text-ink-secondary">
                        Results stay hidden until five people have answered, so no one's answer can be picked out
                        in a small room.
                    </p>
                )}

                {authored && (
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-ink">
                            {type === 'quiz' ? 'Options — pick the correct one' : 'Options'}
                        </label>
                        <div className="space-y-2">
                            {options.map((o, i) => (
                                <div key={i} className="flex items-center gap-2">
                                    {type === 'quiz' && (
                                        <input
                                            type="radio"
                                            name="correct_option"
                                            checked={correctIndex === i}
                                            onChange={() => setCorrectIndex(i)}
                                            title="Correct answer"
                                        />
                                    )}
                                    <input
                                        value={o}
                                        onChange={(e) => setOption(i, e.target.value)}
                                        placeholder={`Option ${i + 1}`}
                                        className="w-full border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                                    />
                                    {options.length > 2 && (
                                        <button
                                            type="button"
                                            onClick={() => removeOption(i)}
                                            className="text-ink-secondary hover:text-danger-fg"
                                        >
                                            <X className="h-4 w-4" strokeWidth={1.75} />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <Button type="button" onClick={addOption} className="mt-2">
                            Add option
                        </Button>
                    </div>
                )}

                {type === 'quiz' && (
                    <div className="grid grid-cols-2 gap-3">
                        <Input
                            label="Timer (seconds)"
                            type="number"
                            min="5"
                            max="300"
                            value={timerSeconds}
                            onChange={(e) => setTimerSeconds(e.target.value)}
                        />
                        <Input
                            label="Points for a correct answer"
                            type="number"
                            min="1"
                            max="1000"
                            value={points}
                            onChange={(e) => setPoints(e.target.value)}
                        />
                    </div>
                )}

                {moderated && (
                    <label className="flex items-center gap-2 text-sm text-ink">
                        <input
                            type="checkbox"
                            checked={requiresModeration}
                            onChange={(e) => setRequiresModeration(e.target.checked)}
                        />
                        Review responses before they're shown
                    </label>
                )}

                {formError && <p className="text-sm text-danger-fg">{formError}</p>}

                <Button type="submit" icon={Plus} variant="primary" disabled={saving}>
                    Create
                </Button>
            </div>
        </form>
    );
}

function PollCard({ event, poll, onChange }) {
    const setStatus = async (status) => {
        await csrfFetch(route('tenant.events.polls.status', { event: event.id, poll: poll.id }), {
            method: 'PATCH',
            body: JSON.stringify({ status }),
        });
        onChange();
    };

    const remove = async () => {
        await csrfFetch(route('tenant.events.polls.destroy', { event: event.id, poll: poll.id }), {
            method: 'DELETE',
        });
        onChange();
    };

    const moderate = async (responseId, isApproved) => {
        await csrfFetch(
            route('tenant.events.polls.responses.moderate', {
                event: event.id,
                poll: poll.id,
                response: responseId,
            }),
            {
                method: 'PATCH',
                body: JSON.stringify({ is_approved: isApproved }),
            }
        );
        onChange();
    };

    const maxCount = Math.max(1, ...poll.options.map((o) => o.responses_count));

    return (
        <div className="border border-border p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <b className="text-[13.5px] text-ink">{poll.question}</b>
                        {poll.type !== 'multiple_choice' && poll.type !== 'open' && (
                            <StatusPill status="neutral">{TYPE_LABELS[poll.type] ?? poll.type}</StatusPill>
                        )}
                    </div>
                    <div className="mt-1 flex items-center gap-2 text-xs text-ink-secondary">
                        <StatusPill status={STATUS_TONE[poll.status]}>{poll.status}</StatusPill>
                        <span className="font-mono">{poll.responses_count} responses</span>
                        {poll.type === 'quiz' && (
                            <span className="font-mono">
                                {poll.timer_seconds ?? '—'}s · {poll.points} pts
                            </span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 gap-1.5">
                    {poll.status !== 'live' && (
                        <Button onClick={() => setStatus('live')} variant="primary">
                            Go live
                        </Button>
                    )}
                    {poll.status === 'live' && (
                        <Button onClick={() => setStatus('closed')}>Close</Button>
                    )}
                    <button onClick={remove} className="text-ink-secondary hover:text-danger-fg">
                        <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                    </button>
                </div>
            </div>

            {(poll.type === 'multiple_choice' || poll.type === 'quiz') && (
                <ul className="mt-3 space-y-2">
                    {poll.options.map((o) => (
                        <li key={o.id}>
                            <div className="flex items-center justify-between text-[13px]">
                                <span className="flex items-center gap-1.5 text-ink">
                                    {o.label}
                                    {poll.type === 'quiz' && o.is_correct && (
                                        <Check
                                            className="h-3.5 w-3.5 text-success-fg"
                                            strokeWidth={2}
                                        />
                                    )}
                                </span>
                                <span className="font-mono text-ink-secondary">
                                    {o.responses_count}
                                </span>
                            </div>
                            <div className="mt-1 h-1 bg-surface-sunken">
                                <div
                                    className="h-full bg-accent"
                                    style={{ width: `${(o.responses_count / maxCount) * 100}%` }}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {poll.type === 'open' && (
                <>
                    <ul className="mt-3 space-y-1.5">
                        {poll.open_responses.length > 0 ? (
                            poll.open_responses.map((text, i) => (
                                <li
                                    key={i}
                                    className="border-l-2 border-border pl-3 text-[13px] text-ink-secondary"
                                >
                                    {text}
                                </li>
                            ))
                        ) : (
                            <li className="text-[13px] text-ink-secondary">No responses yet.</li>
                        )}
                    </ul>

                    <PendingReview pending={poll.pending_responses} moderate={moderate} />
                </>
            )}

            {CHARTED_TYPES.includes(poll.type) && poll.results && (
                <>
                    {/* The wall's own chart, so the organiser sees exactly what the room sees. */}
                    <div className="mt-3 h-56 bg-neutral-950 p-4 text-white">
                        <PollChart poll={poll.results} />
                    </div>
                    {poll.type === 'word_cloud' && (
                        <PendingReview pending={poll.pending_responses} moderate={moderate} />
                    )}
                </>
            )}
        </div>
    );
}

function PendingReview({ pending, moderate }) {
    if (!pending || pending.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 border-t border-border pt-3">
            <b className="mb-2 block text-xs uppercase tracking-wide text-warning-fg">Pending review ({pending.length})</b>
            <ul className="space-y-2">
                {pending.map((r) => (
                    <li key={r.id} className="flex items-center justify-between gap-3 border border-border px-3 py-2">
                        <span className="text-[13px] text-ink">{r.text}</span>
                        <div className="flex shrink-0 gap-1.5">
                            <Button onClick={() => moderate(r.id, true)} variant="primary">
                                Approve
                            </Button>
                            <Button onClick={() => moderate(r.id, false)}>Reject</Button>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Leaderboard({ event }) {
    const [rows, setRows] = useState([]);

    useEffect(() => {
        csrfFetch(route('tenant.events.quiz.leaderboard', { event: event.id }))
            .then((r) => r.json())
            .then(setRows);
        // Dependencies deliberately limited to the ids above (re-run only when they change).
    }, [event.id]);

    if (rows.length === 0) return null;

    return (
        <div className="border border-border p-4">
            <div className="flex items-center gap-2">
                <Trophy className="h-4 w-4 text-accent" strokeWidth={1.75} />
                <b className="text-sm font-medium text-ink">Quiz leaderboard</b>
            </div>
            <ol className="mt-3 space-y-1.5">
                {rows.map((r, i) => (
                    <li key={i} className="flex items-center justify-between text-[13px]">
                        <span className="text-ink">
                            {i + 1}. {r.name}
                        </span>
                        <span className="font-mono text-ink-secondary">{r.points} pts</span>
                    </li>
                ))}
            </ol>
        </div>
    );
}


/**
 * Decks, and the controls for driving one in front of a room.
 *
 * A deck is an ordered run of questions the presenter moves through. Every
 * surface -- the wall, each phone -- follows the deck's pointer rather than
 * guessing from which poll went live last.
 */
export function DeckSection({ event, polls, onChange }) {
    const [decks, setDecks] = useState([]);
    const [title, setTitle] = useState('');
    const [creating, setCreating] = useState(false);

    const load = () => {
        csrfFetch(route('tenant.events.decks.index', { event: event.id }))
            .then((r) => r.json())
            .then(setDecks)
            .catch(() => {});
    };

    useEffect(load, [event.id]);

    const live = decks.some((d) => d.status === 'live');

    // While a deck is running the position is the instrument; nothing polls
    // once every deck is idle.
    useEffect(() => {
        if (!live) return undefined;
        const interval = setInterval(load, 5000);
        return () => clearInterval(interval);
    }, [live, event.id]);

    const create = async (e) => {
        e.preventDefault();
        if (!title.trim()) return;
        setCreating(true);
        await csrfFetch(route('tenant.events.decks.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({ title }),
        });
        setTitle('');
        setCreating(false);
        load();
    };

    return (
        <section className="space-y-3 border-t border-border pt-4">
            <div>
                <h3 className="text-sm font-medium text-ink">Decks</h3>
                <p className="mt-1 text-xs text-ink-secondary">
                    An ordered run of questions. Start one and the wall and every
                    phone follow you through it.
                </p>
            </div>

            {decks.map((deck) => (
                <DeckCard
                    key={deck.id}
                    event={event}
                    deck={deck}
                    polls={polls}
                    onChange={() => {
                        load();
                        onChange();
                    }}
                />
            ))}

            <form onSubmit={create} className="flex gap-2">
                <input
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    placeholder="Deck name, e.g. Opening plenary"
                    className="h-control flex-1 border border-border px-3 text-sm text-ink focus:border-accent focus:outline-none"
                />
                <button
                    type="submit"
                    disabled={creating || !title.trim()}
                    className="h-control border border-border px-4 text-sm text-ink hover:border-accent disabled:opacity-50 cursor-pointer"
                >
                    Add deck
                </button>
            </form>
        </section>
    );
}

function DeckCard({ event, deck, polls, onChange }) {
    const [busy, setBusy] = useState(false);

    const drive = async (action) => {
        setBusy(true);
        await csrfFetch(
            route(`tenant.events.decks.${action}`, { event: event.id, deck: deck.id })
        , { method: 'POST' });
        setBusy(false);
        onChange();
    };

    const setPolls = async (ids) => {
        setBusy(true);
        await csrfFetch(route('tenant.events.decks.polls', { event: event.id, deck: deck.id }), {
            method: 'PUT',
            body: JSON.stringify({ poll_ids: ids }),
        });
        setBusy(false);
        onChange();
    };

    const inDeck = deck.polls.map((p) => p.id);
    const available = polls.filter((p) => !inDeck.includes(p.id));

    const move = (index, delta) => {
        const next = [...inDeck];
        const target = index + delta;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        setPolls(next);
    };

    const position = deck.polls.findIndex((p) => p.id === deck.current_poll_id);

    return (
        <div className="border border-border p-4 space-y-3">
            <div className="flex items-baseline justify-between gap-3">
                <div>
                    <b className="text-sm text-ink">{deck.title}</b>
                    <span className="ml-2 text-xs text-ink-secondary">
                        {deck.status === 'live'
                            ? `Question ${position + 1} of ${deck.polls.length}`
                            : `${deck.polls.length} question${deck.polls.length === 1 ? '' : 's'} · ${deck.status}`}
                    </span>
                </div>
                {deck.status === 'live' && (
                    <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-accent" />
                )}
            </div>

            <ol className="space-y-1.5">
                {deck.polls.map((poll, index) => (
                    <li
                        key={poll.id}
                        className={`flex items-center gap-2 border px-3 py-2 text-[13px] ${
                            poll.id === deck.current_poll_id
                                ? 'border-accent bg-accent-soft text-ink'
                                : 'border-border text-ink-secondary'
                        }`}
                    >
                        <span className="w-5 shrink-0 text-xs text-ink-muted">{index + 1}</span>
                        <span className="flex-1">{poll.question}</span>
                        <button
                            type="button"
                            onClick={() => move(index, -1)}
                            disabled={busy || index === 0}
                            className="px-1 text-xs text-ink-secondary disabled:opacity-30 cursor-pointer"
                            aria-label="Move up"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            onClick={() => move(index, 1)}
                            disabled={busy || index === deck.polls.length - 1}
                            className="px-1 text-xs text-ink-secondary disabled:opacity-30 cursor-pointer"
                            aria-label="Move down"
                        >
                            ↓
                        </button>
                        <button
                            type="button"
                            onClick={() => setPolls(inDeck.filter((id) => id !== poll.id))}
                            disabled={busy}
                            className="px-1 text-xs text-danger-fg disabled:opacity-30 cursor-pointer"
                            aria-label="Remove from deck"
                        >
                            ×
                        </button>
                    </li>
                ))}
            </ol>

            {available.length > 0 && (
                <select
                    value=""
                    onChange={(e) => e.target.value && setPolls([...inDeck, e.target.value])}
                    disabled={busy}
                    className="h-control w-full border border-border px-3 text-sm text-ink focus:border-accent focus:outline-none"
                >
                    <option value="">Add a question to this deck…</option>
                    {available.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.question}
                        </option>
                    ))}
                </select>
            )}

            <PresenterLink event={event} deck={deck} />

            <div className="flex flex-wrap gap-2">
                {deck.status !== 'live' ? (
                    <button
                        type="button"
                        onClick={() => drive('start')}
                        disabled={busy || deck.polls.length === 0 || deck.status === 'ended'}
                        className="h-control border border-accent bg-accent px-4 text-sm text-white hover:opacity-90 disabled:opacity-50 cursor-pointer"
                    >
                        Start deck
                    </button>
                ) : (
                    <>
                        <button
                            type="button"
                            onClick={() => drive('previous')}
                            disabled={busy}
                            className="h-control border border-border px-4 text-sm text-ink hover:border-accent disabled:opacity-50 cursor-pointer"
                        >
                            Back
                        </button>
                        <button
                            type="button"
                            onClick={() => drive('advance')}
                            disabled={busy}
                            className="h-control border border-accent bg-accent px-4 text-sm text-white hover:opacity-90 disabled:opacity-50 cursor-pointer"
                        >
                            Next question
                        </button>
                        <button
                            type="button"
                            onClick={() => drive('close')}
                            disabled={busy}
                            className="h-control border border-border px-4 text-sm text-ink hover:border-accent disabled:opacity-50 cursor-pointer"
                        >
                            Close voting
                        </button>
                        <button
                            type="button"
                            onClick={() => drive('end')}
                            disabled={busy}
                            className="h-control border border-border px-4 text-sm text-ink hover:border-accent disabled:opacity-50 cursor-pointer"
                        >
                            End deck
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}


/**
 * The link a speaker drives this deck from.
 *
 * Fetched only when asked for, unlike the wall's link: this one grants control,
 * so it should come into existence when someone means to hand it over, not
 * whenever the console happens to load.
 */
export function PresenterLink({ event, deck }) {
    const [url, setUrl] = useState(null);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    const fetchLink = async (rotate = false) => {
        setBusy(true);
        const target = rotate
            ? route('tenant.events.decks.presenter-link.rotate', { event: event.id, deck: deck.id })
            : route('tenant.events.decks.presenter-link', { event: event.id, deck: deck.id });

        try {
            const response = await csrfFetch(target, { method: rotate ? 'POST' : 'GET' });
            const data = await response.json();
            setUrl(data.presenter_url);
            setCopied(false);
        } finally {
            setBusy(false);
        }
    };

    const copy = () => {
        if (url) {
            navigator.clipboard?.writeText(url).then(() => setCopied(true));
        }
    };

    if (!url) {
        return (
            <button
                type="button"
                onClick={() => fetchLink()}
                disabled={busy}
                className="text-xs text-accent hover:underline disabled:opacity-50 cursor-pointer"
            >
                Get a presenter link for a speaker
            </button>
        );
    }

    return (
        <div className="space-y-2 border border-border bg-surface-subtle p-3">
            <p className="text-xs text-ink-secondary">
                Whoever has this link can move through this deck. Send it to the speaker; issue a new
                one to take it back.
            </p>
            <div className="flex items-center gap-2">
                <input
                    readOnly
                    value={url}
                    onFocus={(e) => e.target.select()}
                    className="h-control min-w-0 flex-1 border border-border bg-surface px-2 text-xs text-ink"
                    aria-label="Presenter link"
                />
                <button
                    type="button"
                    onClick={copy}
                    className="h-control border border-border px-3 text-xs text-ink hover:border-accent cursor-pointer"
                >
                    {copied ? 'Copied' : 'Copy'}
                </button>
                <a
                    href={url}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex h-control items-center border border-border px-3 text-xs text-ink hover:border-accent"
                >
                    Open
                </a>
            </div>
            <button
                type="button"
                onClick={() => fetchLink(true)}
                disabled={busy}
                className="text-xs text-ink-secondary hover:text-danger-fg disabled:opacity-50 cursor-pointer"
            >
                Issue a new link (the old one stops working)
            </button>
        </div>
    );
}

export default function EngagementPanel({ event }) {
    const [polls, setPolls] = useState([]);

    const load = () => {
        csrfFetch(route('tenant.events.polls.index', { event: event.id }))
            .then((r) => r.json())
            .then(setPolls);
    };

    useEffect(load, [event.id]);

    // While a poll is open, the results are the instrument -- an organiser
    // reading them off a projector should not have to reload to see the room
    // answer. Nothing polls once every poll is closed.
    const hasLivePoll = polls.some((p) => p.status === 'live');

    useEffect(() => {
        if (!hasLivePoll) {
            return undefined;
        }

        const interval = setInterval(load, 8000);

        return () => clearInterval(interval);
    }, [hasLivePoll, event.id]);

    return (
        <div className="max-w-3xl space-y-4">
            <p className="text-sm text-ink-secondary">
                Live polls and quizzes. Put questions into a deck to run them in
                order; a loose poll is shown on its own when no deck is running.
            </p>

            {hasLivePoll && (
                <p className="flex items-center gap-1.5 text-xs text-ink-secondary">
                    <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-accent" />
                    Results update as votes arrive.
                </p>
            )}

            <PresentLink event={event} />

            <DeckSection event={event} polls={polls} onChange={load} />

            <Leaderboard event={event} />

            {polls.map((p) => (
                <PollCard key={p.id} event={event} poll={p} onChange={load} />
            ))}

            <NewPollForm event={event} onCreated={load} />
        </div>
    );
}

/**
 * The results screen for the room, and the link for whoever is driving the
 * projector -- which at a conference is rarely the organiser's own laptop, so
 * it opens on a token rather than a login.
 */
function PresentLink({ event }) {
    const [url, setUrl] = useState(null);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);

    const fetchLink = (rotate = false) => {
        setBusy(true);
        const route_ = rotate
            ? route('tenant.events.polls.present-link.rotate', { event: event.id })
            : route('tenant.events.polls.present-link', { event: event.id });

        csrfFetch(route_, { method: rotate ? 'POST' : 'GET' })
            .then((r) => r.json())
            .then((data) => {
                setUrl(data.present_url);
                setCopied(false);
            })
            .finally(() => setBusy(false));
    };

    useEffect(() => {
        fetchLink();
        // Only when the event changes; rotating is explicit.
    }, [event.id]);

    const copy = () => {
        if (!url) {
            return;
        }
        navigator.clipboard?.writeText(url).then(() => setCopied(true));
    };

    return (
        <div className="border border-border p-4">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="text-[13.5px] font-medium text-ink">Show results on screen</p>
                    <p className="mt-0.5 text-xs text-ink-secondary">
                        Full-screen results with a QR code the room can scan to vote.
                    </p>
                </div>
                <Button
                    onClick={() => url && window.open(url, '_blank', 'noopener')}
                    disabled={!url || busy}
                >
                    Present
                </Button>
            </div>

            {url && (
                <div className="mt-3 flex items-center gap-2">
                    <code className="flex-1 truncate border border-border bg-surface px-2 py-1.5 text-[11.5px] text-ink-secondary">
                        {url}
                    </code>
                    <button
                        type="button"
                        onClick={copy}
                        className="shrink-0 text-xs text-accent hover:underline"
                    >
                        {copied ? 'Copied' : 'Copy'}
                    </button>
                    <button
                        type="button"
                        onClick={() => fetchLink(true)}
                        disabled={busy}
                        className="shrink-0 text-xs text-ink-secondary hover:text-danger-fg"
                        title="Issues a new link and stops the old one working"
                    >
                        Revoke
                    </button>
                </div>
            )}
        </div>
    );
}
