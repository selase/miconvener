import { useEffect, useState } from 'react';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import { Pin, EyeOff, Trash2, ThumbsUp, Flag, Ban, Paperclip } from 'lucide-react';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

const TAG_LABEL = {
    official_answer: 'Answered by a speaker',
    important: 'Important',
    action_item: "We'll do this",
    faq: 'Common question',
};

const TAG_STATUS = {
    official_answer: 'success',
    important: 'pending',
    action_item: 'pending',
    faq: 'neutral',
};

function ReplyForm({ event, thread, onDone }) {
    const [body, setBody] = useState('');
    const [tag, setTag] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        await csrfFetch(route('tenant.events.forum.reply', { event: event.id, thread: thread.id }), {
            method: 'POST',
            body: JSON.stringify({ body, tag: tag || null }),
        });
        setSaving(false);
        setBody('');
        setTag('');
        onDone();
    };

    return (
        <form onSubmit={submit} className="mt-3 space-y-2">
            <textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={2}
                placeholder="Answer as the organising team"
                required
                className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
            />
            <div className="flex items-center gap-2">
                <Select value={tag} onChange={(e) => setTag(e.target.value)} className="w-56">
                    <option value="">No tag</option>
                    {Object.entries(TAG_LABEL).map(([key, label]) => (
                        <option key={key} value={key}>{label}</option>
                    ))}
                </Select>
                <Button type="submit" variant="primary" disabled={saving}>Post reply</Button>
            </div>
        </form>
    );
}

function ThreadCard({ event, thread, onChange }) {
    const toast = useToast();

    const moderate = async (patch) => {
        await csrfFetch(route('tenant.events.forum.moderate', { event: event.id, thread: thread.id }), {
            method: 'PATCH',
            body: JSON.stringify(patch),
        });
        onChange();
    };

    const remove = async () => {
        await csrfFetch(route('tenant.events.forum.destroy', { event: event.id, thread: thread.id }), { method: 'DELETE' });
        onChange();
    };

    const ban = async () => {
        const reason = window.prompt(`Ban ${thread.author_email ?? 'this author'} from posting in this forum? Reason (optional):`);
        if (reason === null) return;
        const response = await csrfFetch(route('tenant.events.forum.ban', { event: event.id, thread: thread.id }), {
            method: 'PATCH',
            body: JSON.stringify({ reason: reason || null }),
        });
        const json = await response.json();
        if (!response.ok) {
            toast?.(json.message);
            return;
        }
        toast?.(json.message);
        onChange();
    };

    return (
        <div className={`border p-4 ${thread.reports_count > 0 ? 'border-danger-fg/40 bg-danger-bg/40' : 'border-border'}`}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <b className="text-[13.5px] text-ink">{thread.title}</b>
                        {thread.is_pinned && <Pin className="h-3.5 w-3.5 text-accent" strokeWidth={1.75} />}
                        {thread.is_answered && <StatusPill status="success">Answered</StatusPill>}
                        {thread.is_anonymous && <StatusPill status="neutral">Anonymous</StatusPill>}
                        {thread.reports_count > 0 && (
                            <StatusPill status="failed">{thread.reports_count} report{thread.reports_count > 1 ? 's' : ''}</StatusPill>
                        )}
                    </div>
                    <p className="mt-1 text-[13px] text-ink-secondary">{thread.body}</p>
                    {thread.attachment_url && (
                        <a href={thread.attachment_url} target="_blank" rel="noreferrer" className="mt-1.5 inline-flex items-center gap-1 text-xs text-accent hover:underline">
                            <Paperclip className="h-3 w-3" strokeWidth={1.75} />
                            {thread.attachment_name}
                        </a>
                    )}
                    <div className="mt-1.5 flex items-center gap-3 text-xs text-ink-tertiary">
                        <span>{thread.author_name}{thread.author_email ? ` · ${thread.author_email}` : ''}</span>
                        <span className="flex items-center gap-1"><ThumbsUp className="h-3 w-3" strokeWidth={1.75} /> {thread.votes_count}</span>
                    </div>
                </div>
                <div className="flex shrink-0 gap-1.5">
                    <button onClick={() => moderate({ is_pinned: !thread.is_pinned })} title="Pin" className="text-ink-secondary hover:text-accent">
                        <Pin className="h-4 w-4" strokeWidth={1.75} />
                    </button>
                    <button onClick={() => moderate({ is_hidden: !thread.is_hidden })} title="Hide" className="text-ink-secondary hover:text-warning-fg">
                        <EyeOff className="h-4 w-4" strokeWidth={1.75} />
                    </button>
                    <button onClick={ban} title="Ban author" className="text-ink-secondary hover:text-danger-fg">
                        <Ban className="h-4 w-4" strokeWidth={1.75} />
                    </button>
                    <button onClick={remove} title="Delete" className="text-ink-secondary hover:text-danger-fg">
                        <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                    </button>
                </div>
            </div>

            {thread.replies.length > 0 && (
                <ul className="mt-3 space-y-2 border-t border-border pt-3">
                    {thread.replies.map((r) => (
                        <li key={r.id} className="text-[13px]">
                            <div className="flex items-center gap-2">
                                <b className={r.is_from_host ? 'text-accent' : 'text-ink'}>{r.author_name}</b>
                                {r.tag && <StatusPill status={TAG_STATUS[r.tag]}>{TAG_LABEL[r.tag]}</StatusPill>}
                            </div>
                            <p className="text-ink-secondary">{r.body}</p>
                        </li>
                    ))}
                </ul>
            )}

            <ReplyForm event={event} thread={thread} onDone={onChange} />
        </div>
    );
}

function BannedAuthors({ event, bans, onChange }) {
    if (bans.length === 0) return null;

    const unban = async (ban) => {
        await csrfFetch(route('tenant.events.forum.bans.destroy', { event: event.id, ban: ban.id }), { method: 'DELETE' });
        onChange();
    };

    return (
        <div className="border border-border p-4">
            <b className="mb-2 flex items-center gap-1.5 text-sm font-medium text-ink"><Flag className="h-3.5 w-3.5" strokeWidth={1.75} /> Banned from this forum</b>
            <ul className="divide-y divide-border">
                {bans.map((b) => (
                    <li key={b.id} className="flex items-center justify-between py-2 text-[13px]">
                        <span className="text-ink">{b.author_email}{b.reason ? ` — ${b.reason}` : ''}</span>
                        <button onClick={() => unban(b)} className="text-xs text-ink-secondary underline hover:text-accent">Unban</button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export default function ForumPanel({ event }) {
    const [threads, setThreads] = useState([]);
    const [bans, setBans] = useState([]);

    const load = () => {
        csrfFetch(route('tenant.events.forum.index', { event: event.id }))
            .then((r) => r.json())
            .then((data) => {
                setThreads(data.threads);
                setBans(data.bans);
            });
    };

    useEffect(load, [event.id]);

    return (
        <div className="max-w-3xl space-y-4">
            <p className="text-sm text-ink-secondary">Questions from the floor, answers from your team, all in one place.</p>

            <BannedAuthors event={event} bans={bans} onChange={load} />

            {threads.length > 0 ? (
                threads.map((t) => <ThreadCard key={t.id} event={event} thread={t} onChange={load} />)
            ) : (
                <p className="text-sm text-ink-secondary">No questions yet. They'll show up here once attendees post from the event page.</p>
            )}
        </div>
    );
}
