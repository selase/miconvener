import { useEffect, useRef, useState } from 'react';
import { communityRequest } from '@/lib/community';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import QuestionnaireFields from './QuestionnaireFields';

export function QuestionnaireSummary({ summary, staff = false }) {
    if (!summary) return null;
    return (
        <section
            aria-label={staff ? 'Staff summary' : 'Published results'}
            className="space-y-4 border-t border-border pt-6"
        >
            <h3 className="text-xl font-semibold text-ink">
                {staff ? 'Staff summary' : 'Published results'}
            </h3>
            <p className="text-ink-secondary">
                {summary.responses} responses. Written answers and respondent identities are kept in
                staff screens. Small numeric groups show counts only.
            </p>
            {summary.questions.map((question, index) => (
                <article key={index} className="space-y-2 border border-border p-4">
                    <h4 className="font-semibold text-ink">{question.title}</h4>
                    <p className="text-ink-secondary">{question.responses} answers</p>
                    {question.distribution.map((entry, i) => (
                        <p className="text-ink" key={i}>
                            {entry.label}: {entry.count ?? `average rank ${entry.average_rank}`}
                        </p>
                    ))}
                    {question.statistics && (
                        <p className="text-ink">
                            Average {question.statistics.average} · Minimum{' '}
                            {question.statistics.minimum} · Maximum {question.statistics.maximum}
                        </p>
                    )}
                </article>
            ))}
        </section>
    );
}

export default function QuestionnairesPanel({
    tenantSlug,
    questionnaireId = null,
    onExpired,
    onClose,
}) {
    const [selected, setSelected] = useState(questionnaireId);
    const [page, setPage] = useState(1);
    const [data, setData] = useState(null);
    const [answers, setAnswers] = useState({});
    const [context, setContext] = useState('');
    const [error, setError] = useState('');
    const [receipt, setReceipt] = useState('');
    const [busy, setBusy] = useState(false);
    const [revision, setRevision] = useState(0);
    const sending = useRef(false);
    const callbacks = useRef({ onExpired });
    callbacks.current = { onExpired };
    const base = `/my/communities/${encodeURIComponent(tenantSlug)}/polls`;
    useEffect(() => {
        const focus = () => {
            setData(null);
            setRevision((n) => n + 1);
        };
        const back = () => {
            setSelected(new URLSearchParams(window.location.search).get('questionnaire'));
            setContext('');
            setAnswers({});
        };
        window.addEventListener('focus', focus);
        window.addEventListener('popstate', back);
        return () => {
            window.removeEventListener('focus', focus);
            window.removeEventListener('popstate', back);
        };
    }, []);
    useEffect(() => {
        let disposed = false;
        const controller = new AbortController();
        setData(null);
        setError('');
        communityRequest(
            selected ? `${base}/${encodeURIComponent(selected)}` : `${base}?page=${page}`,
            'GET',
            undefined,
            { signal: controller.signal }
        )
            .then((result) => {
                if (!disposed) setData(result);
            })
            .catch((failure) => {
                if (disposed || failure.name === 'AbortError') return;
                setError(failure.message);
                setData(null);
                if ([401, 403, 404].includes(failure.status)) {
                    setAnswers({});
                    setContext('');
                }
                if (failure.status === 401) callbacks.current.onExpired?.();
            });
        return () => {
            disposed = true;
            controller.abort();
        };
    }, [base, selected, page, revision]);
    const open = (id) => {
        setSelected(id);
        setData(null);
        setContext('');
        setAnswers({});
        setReceipt('');
        const url = new URL(window.location.href);
        if (id) url.searchParams.set('questionnaire', id);
        else url.searchParams.delete('questionnaire');
        window.history.pushState({}, '', url);
    };
    const member = data?.contexts?.find((entry) => entry.id === context);
    const canAnswer = data?.accepting && member?.role === 'self' && !member.responded;
    const submit = async (event) => {
        event.preventDefault();
        if (!canAnswer || sending.current) return;
        sending.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await communityRequest(
                `${base}/${encodeURIComponent(selected)}/responses`,
                'POST',
                {
                    member_id: context,
                    revision: data.questionnaire.revision,
                    answers: Object.fromEntries(
                        Object.entries(answers).filter(([, value]) =>
                            Object.values(value).some((part) =>
                                Array.isArray(part)
                                    ? part.some((entry) => String(entry ?? '').trim() !== '')
                                    : part !== null && String(part).trim() !== ''
                            )
                        )
                    ),
                }
            );
            setReceipt(
                result.already_received
                    ? 'Your response was already received.'
                    : 'Thank you. Your response has been received.'
            );
            setAnswers({});
            setRevision((n) => n + 1);
        } catch (failure) {
            setError(failure.message);
            if ([401, 403, 404, 409].includes(failure.status)) {
                setData(null);
                setAnswers({});
                setContext('');
            }
            if (failure.status === 401) callbacks.current.onExpired?.();
        } finally {
            sending.current = false;
            setBusy(false);
        }
    };
    return (
        <section aria-label="Community polls and surveys" className="space-y-6 text-ink">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-5">
                <div>
                    <p className="text-xs uppercase tracking-widest text-ink-secondary">
                        Your community
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold">
                        {data?.questionnaire?.title || 'Polls & surveys'}
                    </h2>
                </div>
                <button
                    className="min-h-11 text-accent underline"
                    onClick={() => (selected ? open(null) : onClose?.())}
                >
                    {selected ? 'Back to Polls & surveys' : 'Back to Communities'}
                </button>
            </div>
            {error && (
                <div role="alert" className="border border-border p-4">
                    <p>{error}</p>
                    <button
                        className="min-h-11 text-accent underline"
                        onClick={() => setRevision((n) => n + 1)}
                    >
                        Reload
                    </button>
                </div>
            )}
            {receipt && (
                <p role="status" className="border border-border p-4">
                    {receipt}
                </p>
            )}
            {!data && !error && <p role="status">Loading…</p>}
            {data?.entries && (
                <>
                    <p className="text-ink-secondary">
                        Share your views with your organisation. These are community questions,
                        separate from an event’s live polls.
                    </p>
                    {data.entries.data.length === 0 && (
                        <p>No published polls or surveys for your current memberships.</p>
                    )}
                    <div className="grid gap-4 md:grid-cols-2">
                        {data.entries.data.map((form) => (
                            <button
                                key={form.id}
                                className="space-y-2 border border-border bg-surface p-5 text-left"
                                onClick={() => open(form.id)}
                            >
                                <span className="text-xs uppercase tracking-widest text-ink-secondary">
                                    {form.kind} · {form.accepting ? 'Open' : 'Closed to responses'}
                                </span>
                                <h3 className="text-xl font-semibold">{form.title}</h3>
                                <p className="text-ink-secondary">{form.description}</p>
                                <span className="inline-block min-h-11 pt-3 text-accent underline">
                                    Open questionnaire
                                </span>
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap gap-4">
                        <button
                            disabled={data.entries.current_page <= 1}
                            className="min-h-11 text-accent underline disabled:opacity-40"
                            onClick={() => setPage((n) => n - 1)}
                        >
                            Previous
                        </button>
                        <span>
                            Page {data.entries.current_page} of {data.entries.last_page}
                        </span>
                        <button
                            disabled={data.entries.current_page >= data.entries.last_page}
                            className="min-h-11 text-accent underline disabled:opacity-40"
                            onClick={() => setPage((n) => n + 1)}
                        >
                            Next
                        </button>
                    </div>
                </>
            )}
            {data?.questionnaire && (
                <>
                    <p className="whitespace-pre-wrap text-ink-secondary">
                        {data.questionnaire.description}
                    </p>
                    <div className="border-l-2 border-accent pl-4 text-sm text-ink-secondary">
                        <p>
                            One response per member. Staff can see who answered and export
                            responses. This is not an anonymous ballot.
                        </p>
                        <p>
                            Representatives can read on behalf of their member but cannot answer for
                            them.
                        </p>
                        {!data.accepting && (
                            <p>
                                This questionnaire is not accepting new responses. Existing history
                                is retained.
                            </p>
                        )}
                    </div>
                    <form className="space-y-6" onSubmit={submit}>
                        <Select
                            label="Answering as"
                            value={context}
                            onChange={(event) => {
                                setContext(event.target.value);
                                setAnswers({});
                                setReceipt('');
                            }}
                        >
                            <option value="">Choose your membership</option>
                            {data.contexts.map((entry) => (
                                <option
                                    disabled={entry.role !== 'self'}
                                    key={entry.id}
                                    value={entry.id}
                                >
                                    {entry.name}
                                    {entry.role === 'representative'
                                        ? ' — representative read only'
                                        : ''}
                                    {entry.responded ? ' — response received' : ''}
                                </option>
                            ))}
                        </Select>
                        {member?.responded && <p role="status">Your response has been received.</p>}
                        {data.questionnaire.questions.map((question) => (
                            <QuestionnaireFields
                                key={`${question.id}-${data.questionnaire.revision}`}
                                question={question}
                                value={answers[question.id]}
                                disabled={!canAnswer || busy}
                                onChange={(value) =>
                                    setAnswers((current) => ({ ...current, [question.id]: value }))
                                }
                            />
                        ))}
                        <Button variant="primary" disabled={!canAnswer || busy} type="submit">
                            {busy ? 'Sending…' : 'Send response'}
                        </Button>
                    </form>
                    <QuestionnaireSummary summary={data.results} />
                </>
            )}
        </section>
    );
}
