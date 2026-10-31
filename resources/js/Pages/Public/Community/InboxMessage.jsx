import { useEffect, useRef, useState } from 'react';
import { inboxBase, inboxRequest } from '@/lib/communityInbox';

function MessageBody({ body }) {
    return (
        <p className="whitespace-pre-wrap break-words text-sm leading-7 text-ink">
            {String(body || '')
                .split(/(https?:\/\/[^\s<>]+)/g)
                .map((part, index) =>
                    /^https?:\/\//.test(part) ? (
                        <a
                            key={index}
                            href={part}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="break-all text-accent underline"
                        >
                            {part}
                        </a>
                    ) : (
                        part
                    )
                )}
        </p>
    );
}
export default function InboxMessage({
    tenantSlug,
    itemId,
    context = 'self',
    onRead,
    onBack,
    onExpired,
    onUnavailable,
}) {
    const backFocus = useRef(null);
    const owner = `${tenantSlug}:${itemId}:${context}`;
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [revision, setRevision] = useState(0);
    const [readError, setReadError] = useState('');
    const [readAttempt, setReadAttempt] = useState(0);
    const callbacks = useRef({ onRead, onExpired, onUnavailable });
    callbacks.current = { onRead, onExpired, onUnavailable };
    const entry = result?.owner === owner ? result.entry : null;
    useEffect(() => {
        backFocus.current?.focus();
    }, [owner]);
    useEffect(() => {
        let disposed = false;
        const abort = new AbortController();
        setResult(null);
        setError('');
        setReadError('');
        inboxRequest(
            `${inboxBase(tenantSlug)}/${encodeURIComponent(itemId)}?context=${encodeURIComponent(context)}`,
            'GET',
            undefined,
            abort.signal
        )
            .then((data) => {
                if (!disposed) {
                    if (!data.entry?.id) throw new Error('This message is no longer available.');
                    setResult({ owner, entry: data.entry });
                }
            })
            .catch((failure) => {
                if (disposed || failure.name === 'AbortError') return;
                setError(
                    failure.status === 404
                        ? 'This message is no longer available.'
                        : failure.message
                );
                if (failure.status === 401) callbacks.current.onExpired?.();
                if (failure.status === 404) callbacks.current.onUnavailable?.();
            });
        return () => {
            disposed = true;
            abort.abort();
        };
    }, [tenantSlug, itemId, context, owner, revision]);
    useEffect(() => {
        if (!entry || entry.read) return undefined;
        let disposed = false;
        const abort = new AbortController();
        inboxRequest(
            `${inboxBase(tenantSlug)}/${encodeURIComponent(itemId)}/read`,
            'POST',
            { context },
            abort.signal
        )
            .then(() => {
                if (disposed) return;
                setResult((previous) =>
                    previous?.owner === owner
                        ? { ...previous, entry: { ...previous.entry, read: true } }
                        : previous
                );
                setReadError('');
                callbacks.current.onRead?.({ id: itemId, context });
            })
            .catch((failure) => {
                if (disposed || failure.name === 'AbortError') return;
                if ([401, 403, 404].includes(failure.status)) {
                    setResult(null);
                    setError('This message is no longer available.');
                    if (failure.status === 401) callbacks.current.onExpired?.();
                    if (failure.status === 404) callbacks.current.onUnavailable?.();
                } else setReadError('Message opened, but read status could not be saved.');
            });
        return () => {
            disposed = true;
            abort.abort();
        };
    }, [entry, owner, tenantSlug, itemId, context, readAttempt]);
    return (
        <article aria-label="Message detail" className="min-w-0 space-y-5">
            <button
                type="button"
                ref={backFocus}
                onClick={onBack}
                className="min-h-11 text-sm text-accent underline"
            >
                Back to messages
            </button>
            {error ? (
                <div role="alert" className="space-y-3 border border-border p-5">
                    <p>{error}</p>
                    <button
                        className="min-h-11 text-accent underline"
                        onClick={() => setRevision((value) => value + 1)}
                    >
                        Retry message
                    </button>
                </div>
            ) : !entry ? (
                <p role="status">Loading message…</p>
            ) : (
                <>
                    <p className="text-xs uppercase tracking-widest text-accent">
                        {entry.kind === 'announcement'
                            ? 'Community announcement'
                            : entry.kind === 'dues_receipt'
                              ? 'Payment receipt'
                              : 'Dues reminder'}
                    </p>
                    <h3 className="break-words text-xl font-medium text-ink">{entry.subject}</h3>
                    {entry.label && (
                        <p className="border-l-2 border-accent pl-3 text-sm font-medium">
                            {entry.label}
                        </p>
                    )}
                    <time
                        className="block text-xs text-ink-secondary"
                        dateTime={entry.available_at}
                    >
                        {new Date(entry.available_at).toLocaleString()}
                    </time>
                    <MessageBody body={entry.body} />
                    {entry.money_url && (
                        <a
                            href={entry.money_url}
                            className="inline-flex min-h-11 items-center text-sm text-accent underline"
                        >
                            Review in Money →
                        </a>
                    )}
                    {readError && (
                        <div role="alert">
                            <p>{readError}</p>
                            <button
                                className="min-h-11 text-sm text-accent underline"
                                onClick={() => setReadAttempt((value) => value + 1)}
                            >
                                Retry read status
                            </button>
                        </div>
                    )}
                </>
            )}
        </article>
    );
}
