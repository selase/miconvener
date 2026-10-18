import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { communityRequest } from '@/lib/community';
import { QUESTION_TYPES } from '@/Pages/Public/Community/QuestionnaireFields';
import { QuestionnaireSummary } from '@/Pages/Public/Community/QuestionnairesPanel';

const authored = ['multiple_choice', 'quiz', 'multi_select', 'ranking'];
const newQuestion = () => ({
    title: '',
    type: 'multiple_choice',
    required: true,
    options: ['Yes', 'No'],
    settings: { min: 1, max: 5 },
});
const localDate = (value) =>
    value
        ? new Date(new Date(value).getTime() - new Date(value).getTimezoneOffset() * 60000)
              .toISOString()
              .slice(0, 16)
        : '';
export default function Polls({ forms, groups = [], canPublish }) {
    const { auth } = usePage().props;
    const manage = !!auth.can.manage_community_polls;
    const readResults = !!auth.can.read_community_poll_results;
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [results, setResults] = useState(null);
    const [resultsForm, setResultsForm] = useState(null);
    const open = (item) => {
        setEditing(item || {});
        setError('');
        setForm(
            item
                ? {
                      ...item,
                      opens_at: localDate(item.opens_at),
                      closes_at: localDate(item.closes_at),
                      questions: item.questions.map((q) => ({
                          title: q.title,
                          type: q.type,
                          required: q.required,
                          options: q.options.map((o) => o.label),
                          settings: q.settings || {},
                      })),
                  }
                : {
                      title: '',
                      description: '',
                      kind: 'poll',
                      audience: { kind: 'all_members' },
                      opens_at: '',
                      closes_at: '',
                      questions: [newQuestion()],
                  }
        );
    };
    const changeQuestion = (index, change) =>
        setForm((current) => ({
            ...current,
            questions: current.questions.map((q, i) => (i === index ? { ...q, ...change } : q)),
        }));
    const refresh = () => router.reload({ only: ['forms', 'canPublish'], preserveScroll: true });
    const save = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                editing.id
                    ? route('tenant.community.polls.update', { questionnaire: editing.id })
                    : route('tenant.community.polls.store'),
                'POST',
                {
                    title: form.title,
                    description: form.description,
                    kind: form.kind,
                    audience: form.audience,
                    opens_at: form.opens_at ? new Date(form.opens_at).toISOString() : null,
                    closes_at: form.closes_at ? new Date(form.closes_at).toISOString() : null,
                    ...(editing.id ? { revision: editing.revision } : {}),
                    questions: form.questions.map((q) => ({
                        ...q,
                        settings:
                            q.type === 'scale'
                                ? {
                                      min: Number(q.settings.min),
                                      max: Number(q.settings.max),
                                      label_min: q.settings.label_min || null,
                                      label_max: q.settings.label_max || null,
                                  }
                                : q.type === 'number'
                                  ? { unit: q.settings.unit || null }
                                  : {},
                    })),
                }
            );
            setEditing(null);
            refresh();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const transition = async (item, payload) => {
        setBusy(true);
        setError('');
        try {
            await communityRequest(
                route('tenant.community.polls.state', { questionnaire: item.id }),
                'POST',
                payload
            );
            refresh();
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const loadResults = async (item, url = null) => {
        setBusy(true);
        setError('');
        setResults(null);
        setResultsForm(item);
        try {
            setResults(
                await communityRequest(
                    url || route('tenant.community.polls.results', { questionnaire: item.id })
                )
            );
        } catch (failure) {
            setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <ConsoleLayout>
            <PageHeader
                title="Polls & surveys"
                actions={
                    manage && canPublish ? (
                        <Button onClick={() => open(null)}>Create questionnaire</Button>
                    ) : null
                }
            />
            <div className="space-y-6">
                {!canPublish && (
                    <p className="border border-border p-4 text-ink-secondary">
                        New publication and responses require an eligible plan and the Polls
                        feature. Existing questionnaires and results are retained.
                    </p>
                )}
                <p className="text-sm text-ink-secondary">
                    Responses identify the member to authorised staff. This feature is not an
                    anonymous survey or an election system. Publishing results shares aggregate
                    counts only.
                </p>
                {error && (
                    <p role="alert" className="border border-border p-4 text-danger-fg">
                        {error}
                    </p>
                )}
                {editing && (
                    <section className="border border-border bg-surface p-5 md:p-8">
                        <h2 className="mb-5 text-xl font-semibold text-ink">
                            {editing.id ? 'Edit questionnaire' : 'New questionnaire'}
                        </h2>
                        <form onSubmit={save} className="space-y-6">
                            <div className="grid gap-5 md:grid-cols-2">
                                <Input
                                    label="Title"
                                    required
                                    maxLength={255}
                                    value={form.title}
                                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                                />
                                <Select
                                    label="Kind"
                                    value={form.kind}
                                    onChange={(e) => setForm({ ...form, kind: e.target.value })}
                                >
                                    <option value="poll">Poll — one question</option>
                                    <option value="survey">Survey — several questions</option>
                                </Select>
                            </div>
                            <Input
                                label="Description"
                                maxLength={2000}
                                value={form.description || ''}
                                onChange={(e) => setForm({ ...form, description: e.target.value })}
                            />
                            <Select
                                label="Audience"
                                value={form.audience.kind}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        audience: {
                                            kind: e.target.value,
                                            ...(e.target.value === 'groups'
                                                ? { group_ids: [] }
                                                : {}),
                                        },
                                    })
                                }
                            >
                                <option value="all_members">Active members</option>
                                <option value="groups">Selected active groups</option>
                            </Select>
                            {form.audience.kind === 'groups' && (
                                <fieldset className="space-y-2">
                                    <legend className="text-sm text-ink">Groups</legend>
                                    {groups.length === 0 && (
                                        <p className="text-ink-secondary">
                                            Create an active group before targeting it.
                                        </p>
                                    )}
                                    {groups.map((group) => (
                                        <label
                                            key={group.id}
                                            className="flex min-h-11 items-center gap-3 text-ink"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={(form.audience.group_ids || []).includes(
                                                    group.id
                                                )}
                                                onChange={(e) =>
                                                    setForm({
                                                        ...form,
                                                        audience: {
                                                            ...form.audience,
                                                            group_ids: e.target.checked
                                                                ? [
                                                                      ...(form.audience.group_ids ||
                                                                          []),
                                                                      group.id,
                                                                  ]
                                                                : form.audience.group_ids.filter(
                                                                      (id) => id !== group.id
                                                                  ),
                                                        },
                                                    })
                                                }
                                            />
                                            {group.name}
                                        </label>
                                    ))}
                                </fieldset>
                            )}
                            <div className="grid gap-5 md:grid-cols-2">
                                <Input
                                    label="Opens at (your local time)"
                                    type="datetime-local"
                                    value={form.opens_at}
                                    onChange={(e) => setForm({ ...form, opens_at: e.target.value })}
                                />
                                <Input
                                    label="Closes at (your local time)"
                                    type="datetime-local"
                                    value={form.closes_at}
                                    onChange={(e) =>
                                        setForm({ ...form, closes_at: e.target.value })
                                    }
                                />
                            </div>
                            {form.questions.map((q, index) => (
                                <fieldset
                                    key={index}
                                    className="space-y-4 border border-border p-4 md:p-6"
                                >
                                    <legend className="px-2 font-medium text-ink">
                                        Question {index + 1}
                                    </legend>
                                    <Input
                                        label="Question"
                                        required
                                        maxLength={255}
                                        value={q.title}
                                        onChange={(e) =>
                                            changeQuestion(index, { title: e.target.value })
                                        }
                                    />
                                    <Select
                                        label="Answer type"
                                        value={q.type}
                                        onChange={(e) =>
                                            changeQuestion(index, {
                                                type: e.target.value,
                                                settings: { min: 1, max: 5 },
                                                options: ['First choice', 'Second choice'],
                                            })
                                        }
                                    >
                                        {Object.entries(QUESTION_TYPES).map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </Select>
                                    <label className="flex min-h-11 items-center gap-3 text-sm text-ink">
                                        <input
                                            type="checkbox"
                                            checked={q.required}
                                            onChange={(e) =>
                                                changeQuestion(index, {
                                                    required: e.target.checked,
                                                })
                                            }
                                        />
                                        Answer required
                                    </label>
                                    {authored.includes(q.type) && (
                                        <>
                                            <div className="space-y-3">
                                                {q.options.map((label, i) => (
                                                    <div key={i} className="flex items-end gap-3">
                                                        <div className="min-w-0 flex-1">
                                                            <Input
                                                                label={`Choice ${i + 1}`}
                                                                required
                                                                maxLength={255}
                                                                value={label}
                                                                onChange={(e) =>
                                                                    changeQuestion(index, {
                                                                        options: q.options.map(
                                                                            (value, n) =>
                                                                                n === i
                                                                                    ? e.target.value
                                                                                    : value
                                                                        ),
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        {q.options.length > 2 && (
                                                            <Button
                                                                variant="default"
                                                                type="button"
                                                                onClick={() =>
                                                                    changeQuestion(index, {
                                                                        options: q.options.filter(
                                                                            (_, n) => n !== i
                                                                        ),
                                                                    })
                                                                }
                                                            >
                                                                Remove
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                            {q.options.length < 12 && (
                                                <Button
                                                    variant="default"
                                                    type="button"
                                                    onClick={() =>
                                                        changeQuestion(index, {
                                                            options: [...q.options, ''],
                                                        })
                                                    }
                                                >
                                                    Add choice
                                                </Button>
                                            )}
                                        </>
                                    )}
                                    {q.type === 'scale' && (
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Input
                                                label="Minimum"
                                                type="number"
                                                min={0}
                                                max={99}
                                                value={q.settings.min ?? 1}
                                                onChange={(e) =>
                                                    changeQuestion(index, {
                                                        settings: {
                                                            ...q.settings,
                                                            min: e.target.value,
                                                        },
                                                    })
                                                }
                                            />
                                            <Input
                                                label="Maximum"
                                                type="number"
                                                min={1}
                                                max={100}
                                                value={q.settings.max ?? 5}
                                                onChange={(e) =>
                                                    changeQuestion(index, {
                                                        settings: {
                                                            ...q.settings,
                                                            max: e.target.value,
                                                        },
                                                    })
                                                }
                                            />
                                        </div>
                                    )}
                                    {q.type === 'number' && (
                                        <Input
                                            label="Unit (optional)"
                                            maxLength={20}
                                            value={q.settings.unit || ''}
                                            onChange={(e) =>
                                                changeQuestion(index, {
                                                    settings: { unit: e.target.value },
                                                })
                                            }
                                        />
                                    )}
                                    {form.questions.length > 1 && (
                                        <Button
                                            variant="default"
                                            type="button"
                                            onClick={() =>
                                                setForm({
                                                    ...form,
                                                    questions: form.questions.filter(
                                                        (_, i) => i !== index
                                                    ),
                                                })
                                            }
                                        >
                                            Remove question
                                        </Button>
                                    )}
                                </fieldset>
                            ))}
                            {form.kind === 'survey' && form.questions.length < 30 && (
                                <Button
                                    type="button"
                                    variant="default"
                                    onClick={() =>
                                        setForm({
                                            ...form,
                                            questions: [...form.questions, newQuestion()],
                                        })
                                    }
                                >
                                    Add question
                                </Button>
                            )}
                            <p className="text-sm text-ink-secondary">
                                Saving creates a draft. Review it and choose Open when ready.
                                Questions and audience freeze after the first response.
                            </p>
                            <div className="flex flex-wrap gap-3">
                                <Button type="submit" disabled={busy}>
                                    Save draft
                                </Button>
                                <Button
                                    type="button"
                                    variant="default"
                                    onClick={() => setEditing(null)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </section>
                )}
                {forms.data.length === 0 && (
                    <p className="border border-border p-6 text-ink-secondary">
                        No questionnaires yet. Create a poll to ask one question, or a survey for
                        several.
                    </p>
                )}
                <div className="grid gap-4 xl:grid-cols-2">
                    {forms.data.map((item) => (
                        <article
                            key={item.id}
                            className="space-y-4 border border-border bg-surface p-5"
                        >
                            <div>
                                <p className="text-xs uppercase tracking-widest text-ink-secondary">
                                    {item.kind} · {item.state}
                                </p>
                                <h2 className="mt-2 text-xl font-semibold text-ink">
                                    {item.title}
                                </h2>
                                <p className="mt-2 text-ink-secondary">{item.description}</p>
                            </div>
                            {item.frozen && (
                                <p className="text-sm text-ink-secondary">
                                    Responses received. Questions and audience are frozen.
                                </p>
                            )}
                            <div className="flex flex-wrap gap-3">
                                {manage && canPublish && !item.frozen && (
                                    <Button
                                        variant="default"
                                        disabled={busy}
                                        onClick={() => open(item)}
                                    >
                                        Edit
                                    </Button>
                                )}
                                {manage && canPublish && item.state !== 'open' && (
                                    <Button
                                        disabled={busy}
                                        onClick={() => transition(item, { state: 'open' })}
                                    >
                                        Open
                                    </Button>
                                )}
                                {manage && item.state === 'open' && (
                                    <Button
                                        variant="default"
                                        disabled={busy}
                                        onClick={() => transition(item, { state: 'closed' })}
                                    >
                                        Close
                                    </Button>
                                )}
                                {manage && item.state !== 'withdrawn' && (
                                    <Button
                                        variant="default"
                                        disabled={busy}
                                        onClick={() => transition(item, { state: 'withdrawn' })}
                                    >
                                        Withdraw
                                    </Button>
                                )}
                                {manage && (
                                    <Button
                                        variant="default"
                                        disabled={busy}
                                        onClick={() =>
                                            transition(item, {
                                                results_published: !item.results_published,
                                            })
                                        }
                                    >
                                        {item.results_published
                                            ? 'Hide results from members'
                                            : 'Publish aggregate results'}
                                    </Button>
                                )}
                                {readResults && (
                                    <Button
                                        variant="default"
                                        disabled={busy}
                                        onClick={() => loadResults(item)}
                                    >
                                        View responses
                                    </Button>
                                )}
                            </div>
                        </article>
                    ))}
                </div>
                <div className="flex flex-wrap gap-4">
                    {forms.prev_page_url && (
                        <Button variant="default" onClick={() => router.get(forms.prev_page_url)}>
                            Previous
                        </Button>
                    )}
                    <p className="text-sm text-ink-secondary">
                        Page {forms.current_page} of {forms.last_page}
                    </p>
                    {forms.next_page_url && (
                        <Button variant="default" onClick={() => router.get(forms.next_page_url)}>
                            Next
                        </Button>
                    )}
                </div>
                {resultsForm && (
                    <section className="space-y-5 border border-border p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h2 className="text-xl font-semibold text-ink">
                                Responses · {resultsForm.title}
                            </h2>
                            <Button
                                variant="default"
                                onClick={() => {
                                    setResults(null);
                                    setResultsForm(null);
                                }}
                            >
                                Close responses
                            </Button>
                        </div>
                        {busy && !results && <p role="status">Loading responses…</p>}
                        {results && (
                            <>
                                <QuestionnaireSummary summary={results.summary} staff />
                                <a
                                    className="inline-block min-h-11 text-accent underline"
                                    href={route('tenant.community.polls.export', {
                                        questionnaire: resultsForm.id,
                                    })}
                                >
                                    Download attributable CSV
                                </a>
                                <p className="text-sm text-ink-secondary">
                                    Private staff view. Members only receive published aggregate
                                    results.
                                </p>
                                {results.responses.data.map((response, index) => (
                                    <article
                                        key={index}
                                        className="space-y-3 border-t border-border pt-4"
                                    >
                                        <h3 className="font-semibold text-ink">
                                            {response.member.name} · {response.member.email}
                                        </h3>
                                        <p className="text-xs text-ink-secondary">
                                            {new Date(response.created_at).toLocaleString()}
                                        </p>
                                        {response.answers.map((answer, i) => {
                                            const q = resultsForm.questions.find(
                                                (question) => question.id === answer.question_id
                                            );
                                            const option = q?.options.find(
                                                (o) => o.id === answer.option_id
                                            );
                                            return (
                                                <div key={i}>
                                                    <h4 className="text-sm font-medium text-ink">
                                                        {q?.title}
                                                    </h4>
                                                    <p className="whitespace-pre-wrap break-words text-ink-secondary">
                                                        {option?.label ??
                                                            answer.response_text ??
                                                            answer.response_number ??
                                                            answer.response_payload
                                                                ?.map(
                                                                    (id) =>
                                                                        q?.options.find(
                                                                            (o) => o.id === id
                                                                        )?.label || id
                                                                )
                                                                .join(' · ')}
                                                    </p>
                                                </div>
                                            );
                                        })}
                                    </article>
                                ))}
                                <div className="flex flex-wrap gap-4">
                                    {results.responses.prev_page_url && (
                                        <Button
                                            variant="default"
                                            onClick={() =>
                                                loadResults(
                                                    resultsForm,
                                                    results.responses.prev_page_url
                                                )
                                            }
                                        >
                                            Previous responses
                                        </Button>
                                    )}
                                    <p className="text-sm text-ink-secondary">
                                        Page {results.responses.current_page} of{' '}
                                        {results.responses.last_page}
                                    </p>
                                    {results.responses.next_page_url && (
                                        <Button
                                            variant="default"
                                            onClick={() =>
                                                loadResults(
                                                    resultsForm,
                                                    results.responses.next_page_url
                                                )
                                            }
                                        >
                                            Next responses
                                        </Button>
                                    )}
                                </div>
                            </>
                        )}
                    </section>
                )}
            </div>
        </ConsoleLayout>
    );
}
