import { useEffect, useRef, useState } from 'react';
import { inboxBase, inboxRequest } from '@/lib/communityInbox';
import InboxMessage from './InboxMessage';

export default function InboxList({
    tenantSlug,
    name,
    logoUrl,
    itemId = null,
    context = 'self',
    onOpen,
    onBack,
    onClose,
    onChanged,
    onExpired,
}) {
    const [selected, setSelected] = useState(itemId ? { id: itemId, context } : null);
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const mounted = useRef(true);
    const listFocus = useRef(null);
    const heading = useRef(null);
    useEffect(() => {
        if (!itemId) heading.current?.focus();
    }, [itemId]);
    const readCopies = useRef(new Set());
    const callbacks = useRef({ onChanged, onExpired });
    callbacks.current = { onChanged, onExpired };
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    useEffect(() => {
        setSelected(itemId ? { id: itemId, context } : null);
    }, [itemId, context]);
    useEffect(() => {
        let disposed = false;
        const abort = new AbortController();
        setResult(null);
        setError('');
        inboxRequest(`${inboxBase(tenantSlug)}?page=${page}`, 'GET', undefined, abort.signal)
            .then((data) => {
                if (!disposed)
                    setResult({
                        tenantSlug,
                        entries: {
                            ...data.entries,
                            data: (data.entries?.data || []).map((row) => ({
                                ...row,
                                read:
                                    row.read ||
                                    readCopies.current.has(
                                        `${tenantSlug}:${row.id}:${row.context}`
                                    ),
                            })),
                        },
                    });
            })
            .catch((failure) => {
                if (disposed || failure.name === 'AbortError') return;
                setError(failure.message);
                if (failure.status === 401) callbacks.current.onExpired?.();
            });
        return () => {
            disposed = true;
            abort.abort();
        };
    }, [tenantSlug, page, revision]);
    const entries = result?.tenantSlug === tenantSlug ? result.entries : null;
    const markAll = async () => {
        setBusy(true);
        setError('');
        try {
            await inboxRequest(`${inboxBase(tenantSlug)}/read-all`, 'POST');
            if (!mounted.current) return;
            setResult((previous) =>
                previous?.tenantSlug === tenantSlug
                    ? {
                          ...previous,
                          entries: {
                              ...previous.entries,
                              data: previous.entries.data.map((row) => ({ ...row, read: true })),
                          },
                      }
                    : previous
            );
            callbacks.current.onChanged?.();
        } catch (failure) {
            if (mounted.current) {
                setError(failure.message);
                if (failure.status === 401) callbacks.current.onExpired?.();
            }
        } finally {
            if (mounted.current) setBusy(false);
        }
    };
    const open = (row) => {
        setSelected({ id: row.id, context: row.context });
        onOpen?.(row);
    };
    const back = () => {
        const previous = selected ? `${selected.id}:${selected.context}` : null;
        setSelected(null);
        window.requestAnimationFrame(() =>
            Array.from(listFocus.current?.querySelectorAll('[data-inbox-entry]') || [])
                .find((button) => button.dataset.inboxEntry === previous)
                ?.focus()
        );
        onBack?.();
    };
    const read = ({ id, context: readerContext }) => {
        readCopies.current.add(`${tenantSlug}:${id}:${readerContext}`);
        setResult((previous) =>
            previous?.tenantSlug === tenantSlug
                ? {
                      ...previous,
                      entries: {
                          ...previous.entries,
                          data: previous.entries.data.map((row) =>
                              row.id === id && row.context === readerContext
                                  ? { ...row, read: true }
                                  : row
                          ),
                      },
                  }
                : previous
        );
        callbacks.current.onChanged?.();
    };
    const unavailable = () => {
        setRevision((value) => value + 1);
        callbacks.current.onChanged?.();
    };
    return (
        <section aria-label={`Messages · ${name || tenantSlug}`} className="space-y-6">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 tabIndex={-1} ref={heading} className="text-2xl font-semibold text-ink">
                        Messages
                    </h1>
                    <p className="mt-2 text-sm text-ink-secondary">
                        Updates from your community, all in one place.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    className="min-h-11 text-sm text-accent underline"
                >
                    Back to Communities
                </button>
            </header>
            <div className="mb-5 flex items-center gap-3 border-b border-border pb-4">
                {logoUrl ? (
                    <img src={logoUrl} alt="" className="h-10 w-10 object-contain" />
                ) : (
                    <span
                        aria-hidden="true"
                        className="grid h-10 w-10 place-items-center border border-border text-accent"
                    >
                        {(name || tenantSlug).slice(0, 1).toUpperCase()}
                    </span>
                )}
                <p className="text-sm font-medium text-ink">{name || tenantSlug}</p>
            </div>
            <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
                <section
                    ref={listFocus}
                    aria-label="Message list"
                    data-mobile-hidden={selected ? 'true' : undefined}
                    className={`${selected ? 'hidden lg:block' : ''} min-w-0 space-y-4`}
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h3 className="font-medium text-ink">Latest messages</h3>
                        <button
                            type="button"
                            disabled={busy || !entries?.data?.length}
                            onClick={markAll}
                            className="min-h-11 text-sm text-accent underline disabled:opacity-50"
                        >
                            {busy ? 'Saving…' : 'Mark all read'}
                        </button>
                    </div>
                    <p className="text-xs text-ink-secondary">
                        Announcements, reminders and receipts, newest first.
                    </p>
                    {error && (
                        <div role="alert">
                            <p>{error}</p>
                            <button
                                className="min-h-11 text-accent underline"
                                onClick={() => setRevision((value) => value + 1)}
                            >
                                Retry messages
                            </button>
                        </div>
                    )}
                    {!entries && !error && <p role="status">Loading messages…</p>}
                    {entries?.data?.length === 0 && (
                        <p className="border border-dashed border-border p-6 text-sm text-ink-secondary">
                            No messages yet.
                        </p>
                    )}
                    <ul className="divide-y divide-border">
                        {entries?.data?.map((row) => (
                            <li key={`${row.id}:${row.context}`}>
                                <button
                                    type="button"
                                    data-inbox-entry={`${row.id}:${row.context}`}
                                    onClick={() => open(row)}
                                    aria-pressed={
                                        selected?.id === row.id && selected?.context === row.context
                                    }
                                    className={`block min-h-11 w-full space-y-2 border-l-2 px-4 py-4 text-left hover:bg-surface-hover focus-visible:outline-accent ${selected?.id === row.id && selected?.context === row.context ? 'border-accent bg-accent/5' : 'border-transparent'}`}
                                >
                                    {!row.read && (
                                        <span className="inline-block border border-accent px-2 py-0.5 text-xs font-medium text-accent">
                                            Unread
                                        </span>
                                    )}
                                    <span className="block break-words text-sm font-medium text-ink">
                                        {row.subject}
                                    </span>
                                    {row.label && (
                                        <span className="block text-xs text-ink-secondary">
                                            {row.label}
                                        </span>
                                    )}
                                    <time
                                        className="block text-xs text-ink-secondary"
                                        dateTime={row.available_at}
                                    >
                                        {new Date(row.available_at).toLocaleDateString()}
                                    </time>
                                </button>
                            </li>
                        ))}
                    </ul>
                    {entries?.last_page > 1 && (
                        <nav
                            aria-label="Message pages"
                            className="flex flex-wrap items-center gap-3"
                        >
                            <button
                                className="min-h-11 text-sm text-accent underline disabled:opacity-50"
                                disabled={busy || page <= 1}
                                onClick={() => setPage((value) => value - 1)}
                            >
                                Previous messages
                            </button>
                            <span className="text-xs text-ink-secondary">
                                Page {entries.current_page} of {entries.last_page}
                            </span>
                            <button
                                className="min-h-11 text-sm text-accent underline disabled:opacity-50"
                                disabled={busy || page >= entries.last_page}
                                onClick={() => setPage((value) => value + 1)}
                            >
                                More messages
                            </button>
                        </nav>
                    )}
                </section>
                <div
                    className={`${selected ? '' : 'hidden lg:block'} min-w-0 lg:border-l lg:border-border lg:pl-6`}
                >
                    {selected ? (
                        <InboxMessage
                            key={`${tenantSlug}:${selected.id}:${selected.context}`}
                            tenantSlug={tenantSlug}
                            itemId={selected.id}
                            context={selected.context}
                            onBack={back}
                            onRead={read}
                            onUnavailable={unavailable}
                            onExpired={onExpired}
                        />
                    ) : (
                        <p className="py-8 text-sm text-ink-secondary">
                            Choose a message to read its full details.
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}
