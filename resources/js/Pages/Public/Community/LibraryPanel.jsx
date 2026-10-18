import { useEffect, useRef, useState } from 'react';
import { communityRequest } from '@/lib/community';

export default function LibraryPanel({ tenantSlug, itemId = null, onExpired, onClose }) {
    const [result, setResult] = useState(null);
    const [selected, setSelected] = useState(itemId);
    const [detail, setDetail] = useState(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [revision, setRevision] = useState(0);
    const callbacks = useRef({ onExpired });
    callbacks.current = { onExpired };
    const base = `/my/communities/${encodeURIComponent(tenantSlug)}/library`;
    useEffect(() => {
        const refresh = () => {
            setDetail(null);
            setResult(null);
            setRevision((n) => n + 1);
        };
        const back = () =>
            setSelected(new URLSearchParams(window.location.search).get('library_item'));
        window.addEventListener('focus', refresh);
        window.addEventListener('popstate', back);
        return () => {
            window.removeEventListener('focus', refresh);
            window.removeEventListener('popstate', back);
        };
    }, []);
    useEffect(() => {
        let disposed = false;
        const controller = new AbortController();
        setResult(null);
        setDetail(null);
        setError('');
        const url = selected
            ? `${base}/${encodeURIComponent(selected)}`
            : `${base}?page=${page}&search=${encodeURIComponent(query)}`;
        communityRequest(url, 'GET', undefined, { signal: controller.signal })
            .then((data) => {
                if (!disposed) {
                    if (selected) setDetail({ ...data.item, contexts: data.contexts || [] });
                    else setResult(data);
                }
            })
            .catch((failure) => {
                if (!disposed && failure.name !== 'AbortError') {
                    setError(failure.message);
                    if (failure.status === 401) callbacks.current.onExpired?.();
                }
            });
        return () => {
            disposed = true;
            controller.abort();
        };
    }, [base, selected, page, query, revision]);
    const open = (id) => {
        setSelected(id);
        setDetail(null);
        if (window.location.pathname === '/my') {
            const url = new URL(window.location.href);
            if (id) url.searchParams.set('library_item', id);
            else url.searchParams.delete('library_item');
            window.history.pushState({}, '', url);
        }
    };
    return (
        <section className="space-y-6" aria-label="Community library">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-5">
                <div>
                    <p className="text-xs font-medium uppercase tracking-widest text-ink-secondary">
                        Your community
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold text-ink">
                        {detail?.title || result?.organisation?.name || 'Library'}
                    </h2>
                </div>
                {selected ? (
                    <button className="min-h-11 text-accent underline" onClick={() => open(null)}>
                        Back to Library
                    </button>
                ) : (
                    onClose && (
                        <button className="min-h-11 text-accent underline" onClick={onClose}>
                            Back to Communities
                        </button>
                    )
                )}
            </div>
            {error && (
                <div role="alert" className="border border-border p-4">
                    <p>{error}</p>
                    <button
                        className="min-h-11 text-accent underline"
                        onClick={() => setRevision((n) => n + 1)}
                    >
                        Try again
                    </button>
                </div>
            )}
            {!error && !detail && !result && (
                <p role="status" className="text-ink-secondary">
                    Loading library…
                </p>
            )}
            {(detail?.contexts || result?.contexts || [])
                .filter((reader) => reader.role === 'representative')
                .map((reader) => (
                    <p key={reader.id} className="text-sm text-ink-secondary">
                        Reading as an approved representative for {reader.name}.
                    </p>
                ))}
            {detail && (
                <article className="space-y-5 border border-border bg-surface p-5 sm:p-7">
                    {detail.description && (
                        <p className="text-ink-secondary">{detail.description}</p>
                    )}
                    {detail.plain_text && (
                        <p className="whitespace-pre-wrap break-words leading-relaxed text-ink">
                            {detail.plain_text}
                        </p>
                    )}
                    {detail.file && (
                        <div className="border-t border-border pt-5">
                            <p className="text-sm text-ink-secondary">
                                {detail.file.edition} ·{' '}
                                {detail.file.mime === 'application/pdf' ? 'PDF' : 'Document'} ·{' '}
                                {Math.ceil(detail.file.size / 1024)}KB
                            </p>
                            <a
                                className="mt-3 inline-flex min-h-11 items-center bg-accent px-5 py-2 font-medium text-white"
                                href={detail.file.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Open document
                            </a>
                        </div>
                    )}
                </article>
            )}
            {result && (
                <>
                    <form
                        className="flex flex-wrap gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setQuery(search);
                            setPage(1);
                        }}
                    >
                        <label className="flex-1 text-sm text-ink">
                            Find a document
                            <input
                                className="mt-1 block w-full border border-border bg-surface px-3 py-2 text-ink"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                maxLength={160}
                            />
                        </label>
                        <button className="min-h-11 self-end border border-border px-4 text-ink">
                            Search
                        </button>
                    </form>
                    {!result.entries?.data?.length && (
                        <p className="border border-dashed border-border p-6 text-ink-secondary">
                            No published documents are available to your membership yet.
                        </p>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {result.entries?.data?.map((entry) => (
                            <article
                                key={entry.id}
                                className="min-w-0 border border-border bg-surface p-5"
                            >
                                <p className="mb-3 text-xs uppercase tracking-wider text-ink-secondary">
                                    Library
                                </p>
                                <button
                                    className="min-h-11 text-left text-lg font-semibold text-ink hover:text-accent"
                                    onClick={() => open(entry.id)}
                                >
                                    {entry.title}
                                </button>
                                {entry.description && (
                                    <p className="mt-2 text-sm text-ink-secondary">
                                        {entry.description}
                                    </p>
                                )}
                                {entry.file && (
                                    <p className="mt-4 text-xs text-ink-secondary">
                                        {entry.file.edition}
                                    </p>
                                )}
                            </article>
                        ))}
                    </div>
                    <div className="flex gap-5">
                        {result.entries?.prev_page_url && (
                            <button
                                className="min-h-11 text-accent underline"
                                onClick={() => setPage((n) => n - 1)}
                            >
                                Previous documents
                            </button>
                        )}
                        {result.entries?.next_page_url && (
                            <button
                                className="min-h-11 text-accent underline"
                                onClick={() => setPage((n) => n + 1)}
                            >
                                More documents
                            </button>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}
