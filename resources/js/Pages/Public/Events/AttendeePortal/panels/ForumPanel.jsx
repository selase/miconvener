import { useState, useEffect } from 'react';
import {
    MessageSquare,
    ThumbsUp,
    Pin,
    AlertCircle,
    Loader2,
    Send,
    Plus,
    UserCheck,
    ChevronDown,
    ChevronUp,
} from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

export default function ForumPanel({ registration, isOnline = true }) {
    const [loading, setLoading] = useState(true);
    const [threads, setThreads] = useState([]);
    const [isBanned, setIsBanned] = useState(false);
    const [showNewThreadForm, setShowNewThreadForm] = useState(false);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [isAnonymous, setIsAnonymous] = useState(false);
    const [posting, setPosting] = useState(false);
    const [error, setError] = useState(null);
    const [expandedReplies, setExpandedReplies] = useState({});

    const fetchForum = async () => {
        try {
            setLoading(true);
            setError(null);
            const res = await fetch(`/my/events/${registration.id}/forum`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setThreads(data.threads || []);
                setIsBanned(Boolean(data.is_banned));
            } else {
                setError('Failed to load event forum.');
            }
        } catch {
            setError('Unable to load forum. Check your connection.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (isOnline) {
            fetchForum();
        }
    }, [registration.id, isOnline]);

    const handleCreateThread = async (e) => {
        e.preventDefault();
        if (!title.trim() || !body.trim()) return;

        setPosting(true);
        setError(null);

        try {
            const res = await csrfFetch(`/my/events/${registration.id}/forum`, {
                method: 'POST',
                body: JSON.stringify({
                    title: title.trim(),
                    body: body.trim(),
                    is_anonymous: isAnonymous,
                }),
            });

            const data = await res.json();
            if (!res.ok) {
                setError(data.message || 'Could not post your question.');
                setPosting(false);
                return;
            }

            setTitle('');
            setBody('');
            setIsAnonymous(false);
            setShowNewThreadForm(false);
            await fetchForum();
        } catch {
            setError('Network error while posting your question.');
        } finally {
            setPosting(false);
        }
    };

    const handleToggleVote = async (thread) => {
        const hasUpvoted = thread.has_upvoted;
        const endpoint = hasUpvoted ? 'unvote' : 'vote';

        // Optimistic update
        setThreads((prev) =>
            prev.map((t) => {
                if (t.id === thread.id) {
                    return {
                        ...t,
                        has_upvoted: !hasUpvoted,
                        upvotes_count: hasUpvoted
                            ? Math.max(0, t.upvotes_count - 1)
                            : t.upvotes_count + 1,
                    };
                }
                return t;
            })
        );

        try {
            const res = await csrfFetch(
                `/my/events/${registration.id}/forum/${thread.id}/${endpoint}`,
                {
                    method: 'POST',
                }
            );
            if (!res.ok) {
                // Revert if failed
                await fetchForum();
            }
        } catch {
            await fetchForum();
        }
    };

    const toggleReplies = (threadId) => {
        setExpandedReplies((prev) => ({
            ...prev,
            [threadId]: !prev[threadId],
        }));
    };

    if (!isOnline) {
        return (
            <div className="rounded-xl border border-border bg-surface p-6 text-center text-ink-secondary text-sm">
                <AlertCircle className="mx-auto mb-2 h-6 w-6 text-amber-500" />
                <p>Internet connection required to view and participate in event Q&A.</p>
            </div>
        );
    }

    if (loading) {
        return (
            <div className="flex flex-col items-center justify-center p-12 text-ink-secondary">
                <Loader2 className="h-7 w-7 animate-spin text-accent" />
                <span className="mt-3 text-sm">Loading event Q&A…</span>
            </div>
        );
    }

    return (
        <div className="space-y-6 text-left">
            {/* Header with action */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold tracking-tight text-ink">
                        Event Q&A & Forum
                    </h2>
                    <p className="text-xs text-ink-secondary">
                        Ask questions to speakers and connect with other attendees.
                    </p>
                </div>
                {!isBanned && !showNewThreadForm && (
                    <button
                        type="button"
                        onClick={() => setShowNewThreadForm(true)}
                        className="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg bg-accent px-4 py-2 text-xs font-semibold text-white hover:opacity-90 cursor-pointer shrink-0"
                    >
                        <Plus className="h-4 w-4" />
                        Ask a question
                    </button>
                )}
            </div>

            {isBanned && (
                <div className="rounded-lg bg-red-50 p-4 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                    Your forum posting privileges for this event have been restricted by the
                    organizer.
                </div>
            )}

            {error && (
                <div className="rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                    {error}
                </div>
            )}

            {/* Compose form */}
            {showNewThreadForm && !isBanned && (
                <form
                    onSubmit={handleCreateThread}
                    className="rounded-xl border border-accent/40 bg-surface p-5 shadow-sm space-y-4"
                >
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-ink">New Question or Topic</h3>
                        <button
                            type="button"
                            onClick={() => setShowNewThreadForm(false)}
                            className="text-xs text-ink-secondary hover:text-ink min-h-[44px] px-2 flex items-center"
                        >
                            Cancel
                        </button>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-ink mb-1">
                            Title / Question <span className="text-red-500">*</span>
                        </label>
                        <input
                            type="text"
                            required
                            maxLength={255}
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder="e.g., Where can we access the slides from Keynote 1?"
                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-ink mb-1">
                            Details <span className="text-red-500">*</span>
                        </label>
                        <textarea
                            required
                            rows={3}
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            placeholder="Provide any additional context for speakers or organizers..."
                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                        />
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-3 pt-1">
                        <label className="flex items-center gap-2 text-xs text-ink-secondary cursor-pointer min-h-[44px]">
                            <input
                                type="checkbox"
                                checked={isAnonymous}
                                onChange={(e) => setIsAnonymous(e.target.checked)}
                                className="h-4 w-4 rounded border-border text-accent focus:ring-accent"
                            />
                            <span>Post anonymously</span>
                        </label>

                        <button
                            type="submit"
                            disabled={posting || !title.trim() || !body.trim()}
                            className="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg bg-accent px-5 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50 cursor-pointer"
                        >
                            {posting ? (
                                <>
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    Posting…
                                </>
                            ) : (
                                <>
                                    <Send className="h-3.5 w-3.5" />
                                    Post question
                                </>
                            )}
                        </button>
                    </div>
                </form>
            )}

            {/* Threads List */}
            {threads.length === 0 ? (
                <div className="rounded-xl border border-border bg-surface p-8 text-center">
                    <MessageSquare className="mx-auto h-8 w-8 text-ink-muted" />
                    <h3 className="mt-3 text-base font-medium text-ink">No questions yet</h3>
                    <p className="mt-1.5 text-xs text-ink-secondary">
                        Be the first to ask a question or start a discussion for this event!
                    </p>
                </div>
            ) : (
                <div className="space-y-4">
                    {threads.map((thread) => {
                        const hasReplies = thread.replies && thread.replies.length > 0;
                        const isExpanded = expandedReplies[thread.id] ?? false;

                        return (
                            <div
                                key={thread.id}
                                className={`rounded-xl border bg-surface p-5 shadow-sm transition-colors ${
                                    thread.is_pinned
                                        ? 'border-accent/40 bg-accent-soft/10'
                                        : 'border-border'
                                }`}
                            >
                                {/* Thread header */}
                                <div className="flex items-start justify-between gap-3">
                                    <div className="space-y-1">
                                        {thread.is_pinned && (
                                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-accent mb-1">
                                                <Pin className="h-3 w-3" /> Pinned
                                            </span>
                                        )}
                                        <h3 className="text-sm font-semibold text-ink leading-snug">
                                            {thread.title}
                                        </h3>
                                        <div className="flex items-center gap-2 text-[11px] text-ink-secondary">
                                            <span>{thread.author_name}</span>
                                            <span>•</span>
                                            <span>
                                                {new Date(thread.created_at).toLocaleDateString(
                                                    undefined,
                                                    {
                                                        month: 'short',
                                                        day: 'numeric',
                                                        hour: 'numeric',
                                                        minute: '2-digit',
                                                    }
                                                )}
                                            </span>
                                        </div>
                                    </div>

                                    {/* Upvote button */}
                                    <button
                                        type="button"
                                        onClick={() => handleToggleVote(thread)}
                                        className={`flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold min-h-[44px] cursor-pointer transition-colors shrink-0 ${
                                            thread.has_upvoted
                                                ? 'border-accent bg-accent text-white'
                                                : 'border-border bg-surface text-ink-secondary hover:border-accent hover:text-ink'
                                        }`}
                                        aria-label={`Upvote question, ${thread.upvotes_count} upvotes`}
                                    >
                                        <ThumbsUp className="h-3.5 w-3.5" />
                                        <span>{thread.upvotes_count}</span>
                                    </button>
                                </div>

                                {/* Body */}
                                <p className="mt-3 text-xs text-ink whitespace-pre-wrap leading-relaxed">
                                    {thread.body}
                                </p>

                                {/* Replies Accordion */}
                                {hasReplies && (
                                    <div className="mt-4 border-t border-border pt-3">
                                        <button
                                            type="button"
                                            onClick={() => toggleReplies(thread.id)}
                                            className="flex items-center gap-1.5 text-xs font-medium text-accent hover:underline min-h-[44px] cursor-pointer"
                                        >
                                            <MessageSquare className="h-3.5 w-3.5" />
                                            <span>
                                                {thread.replies.length}{' '}
                                                {thread.replies.length === 1 ? 'answer' : 'answers'}
                                            </span>
                                            {isExpanded ? (
                                                <ChevronUp className="h-3.5 w-3.5" />
                                            ) : (
                                                <ChevronDown className="h-3.5 w-3.5" />
                                            )}
                                        </button>

                                        {isExpanded && (
                                            <div className="mt-3 space-y-3 pl-3 border-l-2 border-accent/30">
                                                {thread.replies.map((reply) => (
                                                    <div key={reply.id} className="space-y-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="text-xs font-semibold text-ink">
                                                                {reply.author_name}
                                                            </span>
                                                            {reply.is_host_reply && (
                                                                <span className="inline-flex items-center gap-0.5 rounded-full bg-emerald-100 px-2 py-0.2 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                                    <UserCheck className="h-2.5 w-2.5" />{' '}
                                                                    Host / Speaker
                                                                </span>
                                                            )}
                                                            <span className="text-[10px] text-ink-muted">
                                                                {new Date(
                                                                    reply.created_at
                                                                ).toLocaleTimeString(undefined, {
                                                                    hour: 'numeric',
                                                                    minute: '2-digit',
                                                                })}
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-ink whitespace-pre-wrap">
                                                            {reply.body}
                                                        </p>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
