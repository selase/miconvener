import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import StatusPill from '@/Components/Console/StatusPill';
import { Trash2, Plus, X, Check, Trophy } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

const STATUS_TONE = { draft: 'neutral', live: 'success', closed: 'failed' };

function NewPollForm({ event, onCreated }) {
    const [type, setType] = useState('multiple_choice');
    const [question, setQuestion] = useState('');
    const [options, setOptions] = useState(['', '']);
    const [correctIndex, setCorrectIndex] = useState(0);
    const [timerSeconds, setTimerSeconds] = useState(20);
    const [points, setPoints] = useState(10);
    const [requiresModeration, setRequiresModeration] = useState(true);
    const [saving, setSaving] = useState(false);

    const setOption = (i, value) => setOptions((prev) => prev.map((o, idx) => (idx === i ? value : o)));
    const addOption = () => setOptions((prev) => [...prev, '']);
    const removeOption = (i) => setOptions((prev) => prev.filter((_, idx) => idx !== i));

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);

        const withOptions = type === 'multiple_choice' || type === 'quiz';

        await csrfFetch(route('tenant.events.polls.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({
                question,
                type,
                options: withOptions ? options.filter((o) => o.trim() !== '') : undefined,
                correct_option_index: type === 'quiz' ? correctIndex : undefined,
                timer_seconds: type === 'quiz' ? timerSeconds : undefined,
                points: type === 'quiz' ? points : undefined,
                requires_moderation: type === 'open' ? requiresModeration : undefined,
            }),
        });

        setSaving(false);
        setQuestion('');
        setOptions(['', '']);
        setCorrectIndex(0);
        onCreated();
    };

    return (
        <form onSubmit={submit} className="border border-border p-4">
            <b className="text-sm font-medium text-ink">New poll or quiz question</b>
            <div className="mt-3 space-y-3">
                <Input label="Question" value={question} onChange={(e) => setQuestion(e.target.value)} required />
                <Select label="Type" value={type} onChange={(e) => setType(e.target.value)}>
                    <option value="multiple_choice">Multiple choice</option>
                    <option value="open">Open response</option>
                    <option value="quiz">Quiz (timed, auto-graded)</option>
                </Select>

                {(type === 'multiple_choice' || type === 'quiz') && (
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
                                        <button type="button" onClick={() => removeOption(i)} className="text-ink-secondary hover:text-danger-fg">
                                            <X className="h-4 w-4" strokeWidth={1.75} />
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <Button type="button" onClick={addOption} className="mt-2">Add option</Button>
                    </div>
                )}

                {type === 'quiz' && (
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Timer (seconds)" type="number" min="5" max="300" value={timerSeconds} onChange={(e) => setTimerSeconds(e.target.value)} />
                        <Input label="Points for a correct answer" type="number" min="1" max="1000" value={points} onChange={(e) => setPoints(e.target.value)} />
                    </div>
                )}

                {type === 'open' && (
                    <label className="flex items-center gap-2 text-sm text-ink">
                        <input type="checkbox" checked={requiresModeration} onChange={(e) => setRequiresModeration(e.target.checked)} />
                        Review responses before they're shown
                    </label>
                )}

                <Button type="submit" icon={Plus} variant="primary" disabled={saving}>Create</Button>
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
        await csrfFetch(route('tenant.events.polls.destroy', { event: event.id, poll: poll.id }), { method: 'DELETE' });
        onChange();
    };

    const moderate = async (responseId, isApproved) => {
        await csrfFetch(route('tenant.events.polls.responses.moderate', { event: event.id, poll: poll.id, response: responseId }), {
            method: 'PATCH',
            body: JSON.stringify({ is_approved: isApproved }),
        });
        onChange();
    };

    const maxCount = Math.max(1, ...poll.options.map((o) => o.responses_count));

    return (
        <div className="border border-border p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <b className="text-[13.5px] text-ink">{poll.question}</b>
                        {poll.type === 'quiz' && <StatusPill status="neutral">Quiz</StatusPill>}
                    </div>
                    <div className="mt-1 flex items-center gap-2 text-xs text-ink-secondary">
                        <StatusPill status={STATUS_TONE[poll.status]}>{poll.status}</StatusPill>
                        <span className="font-mono">{poll.responses_count} responses</span>
                        {poll.type === 'quiz' && (
                            <span className="font-mono">{poll.timer_seconds ?? '—'}s · {poll.points} pts</span>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 gap-1.5">
                    {poll.status !== 'live' && (
                        <Button onClick={() => setStatus('live')} variant="primary">Go live</Button>
                    )}
                    {poll.status === 'live' && <Button onClick={() => setStatus('closed')}>Close</Button>}
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
                                    {poll.type === 'quiz' && o.is_correct && <Check className="h-3.5 w-3.5 text-success-fg" strokeWidth={2} />}
                                </span>
                                <span className="font-mono text-ink-secondary">{o.responses_count}</span>
                            </div>
                            <div className="mt-1 h-1 bg-surface-sunken">
                                <div className="h-full bg-accent" style={{ width: `${(o.responses_count / maxCount) * 100}%` }} />
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
                                <li key={i} className="border-l-2 border-border pl-3 text-[13px] text-ink-secondary">{text}</li>
                            ))
                        ) : (
                            <li className="text-[13px] text-ink-secondary">No responses yet.</li>
                        )}
                    </ul>

                    {poll.pending_responses.length > 0 && (
                        <div className="mt-3 border-t border-border pt-3">
                            <b className="mb-2 block text-xs uppercase tracking-wide text-warning-fg">Pending review ({poll.pending_responses.length})</b>
                            <ul className="space-y-2">
                                {poll.pending_responses.map((r) => (
                                    <li key={r.id} className="flex items-center justify-between gap-3 border border-border px-3 py-2">
                                        <span className="text-[13px] text-ink">{r.text}</span>
                                        <div className="flex shrink-0 gap-1.5">
                                            <Button onClick={() => moderate(r.id, true)} variant="primary">Approve</Button>
                                            <Button onClick={() => moderate(r.id, false)}>Reject</Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}

function Leaderboard({ event }) {
    const [rows, setRows] = useState([]);

    useEffect(() => {
        csrfFetch(route('tenant.events.quiz.leaderboard', { event: event.id }))
            .then((r) => r.json())
            .then(setRows);
        // eslint-disable-next-line react-hooks/exhaustive-deps
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
                        <span className="text-ink">{i + 1}. {r.name}</span>
                        <span className="font-mono text-ink-secondary">{r.points} pts</span>
                    </li>
                ))}
            </ol>
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

    return (
        <div className="max-w-3xl space-y-4">
            <p className="text-sm text-ink-secondary">Live polls and quizzes. Attendees see whichever poll you most recently set live.</p>

            <Leaderboard event={event} />

            {polls.map((p) => <PollCard key={p.id} event={event} poll={p} onChange={load} />)}

            <NewPollForm event={event} onCreated={load} />
        </div>
    );
}
