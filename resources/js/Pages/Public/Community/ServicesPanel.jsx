import { useEffect, useRef, useState } from 'react';
import { communityRequest } from '@/lib/community';

export function serviceDate(value, timezone = 'UTC') {
    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZoneName: 'short',
    }).format(new Date(value));
}

export default function ServicesPanel({ tenantSlug, serviceId = null, onExpired, onClose }) {
    const [selected, setSelected] = useState(serviceId);
    const [result, setResult] = useState(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [seriesPage, setSeriesPage] = useState(1);
    const [seriesSearch, setSeriesSearch] = useState('');
    const [seriesQuery, setSeriesQuery] = useState('');
    const [filters, setFilters] = useState({ from: '', until: '', event: '' });
    const [applied, setApplied] = useState(filters);
    const [revision, setRevision] = useState(0);
    const callback = useRef(onExpired);
    callback.current = onExpired;
    const base = `/my/communities/${encodeURIComponent(tenantSlug)}/services`;
    useEffect(() => {
        const refresh = () => {
            setResult(null);
            setRevision((n) => n + 1);
        };
        const back = () => {
            setResult(null);
            setSelected(new URLSearchParams(window.location.search).get('service'));
        };
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
        setError('');
        const query = new URLSearchParams({
            page: String(page),
            series_page: String(seriesPage),
            series_search: seriesQuery,
            ...applied,
        });
        communityRequest(
            selected ? `${base}/${encodeURIComponent(selected)}` : `${base}?${query}`,
            'GET',
            undefined,
            { signal: controller.signal }
        )
            .then((data) => {
                if (!disposed) setResult(data);
            })
            .catch((failure) => {
                if (!disposed && failure.name !== 'AbortError') {
                    setError(failure.message);
                    if (failure.status === 401) callback.current?.();
                }
            });
        return () => {
            disposed = true;
            controller.abort();
        };
    }, [base, selected, page, applied, revision, seriesPage, seriesQuery]);
    const open = (id) => {
        setResult(null);
        setSelected(id);
        if (window.location.pathname === '/my') {
            const url = new URL(window.location.href);
            if (id) url.searchParams.set('service', id);
            else url.searchParams.delete('service');
            window.history.pushState({}, '', url);
        }
    };
    const timezone = result?.timezone || result?.organisation?.timezone || 'UTC';
    const series = Array.from(
        new Map(
            [
                ...(result?.series?.data || []),
                ...(result?.selected_series ? [result.selected_series] : []),
            ].map((event) => [event.id, event.title])
        ).entries()
    );
    return (
        <section className="space-y-6" aria-label="Community services">
            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-5">
                <div>
                    <p className="text-xs uppercase tracking-widest text-ink-secondary">
                        Services and gatherings
                    </p>
                    <h2 className="mt-2 text-2xl font-semibold text-ink">
                        {result?.service?.title || result?.organisation?.name || 'Service notes'}
                    </h2>
                    {result?.service && (
                        <p className="mt-2 text-sm text-ink-secondary">
                            {serviceDate(result.service.starts_at, timezone)}
                        </p>
                    )}
                </div>
                {selected ? (
                    <button className="min-h-11 text-accent underline" onClick={() => open(null)}>
                        All services
                    </button>
                ) : (
                    onClose && (
                        <button className="min-h-11 text-accent underline" onClick={onClose}>
                            Back to Communities
                        </button>
                    )
                )}
            </header>
            {error && (
                <div role="alert">
                    <p>{error}</p>
                    <button
                        className="min-h-11 text-accent underline"
                        onClick={() => setRevision((n) => n + 1)}
                    >
                        Try again
                    </button>
                </div>
            )}
            {!result && !error && (
                <p role="status" className="text-ink-secondary">
                    Loading service notes…
                </p>
            )}
            {result?.contexts
                ?.filter((context) => context.role === 'representative')
                .map((context) => (
                    <p key={context.id} className="text-sm text-ink-secondary">
                        Reading as an approved representative for {context.name}.
                    </p>
                ))}
            {result?.service && (
                <div className="space-y-4">
                    {result.notes.map((note) => (
                        <article
                            key={note.id}
                            className="space-y-4 border border-border bg-surface p-5 sm:p-7"
                        >
                            <h3 className="text-xl font-semibold text-ink">{note.title}</h3>
                            {note.description && (
                                <p className="text-ink-secondary">{note.description}</p>
                            )}
                            {note.plain_text && (
                                <p className="whitespace-pre-wrap break-words leading-relaxed text-ink">
                                    {note.plain_text}
                                </p>
                            )}
                            {note.file && (
                                <div className="border-t border-border pt-4">
                                    <p className="text-sm text-ink-secondary">
                                        {note.file.edition} · {Math.ceil(note.file.size / 1024)}KB
                                    </p>
                                    <a
                                        href={note.file.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-2 inline-flex min-h-11 items-center bg-accent px-5 py-2 font-medium text-accent-ink"
                                    >
                                        Open attachment
                                    </a>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}
            {result?.services && (
                <>
                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setSeriesQuery(seriesSearch);
                            setSeriesPage(1);
                        }}
                    >
                        <label className="flex-1 text-sm text-ink">
                            Find a series
                            <input
                                className="mt-1 block min-h-11 w-full border border-border bg-surface px-3 text-ink"
                                value={seriesSearch}
                                maxLength={160}
                                onChange={(event) => setSeriesSearch(event.target.value)}
                            />
                        </label>
                        <button className="min-h-11 border border-border px-4 text-ink">
                            Find series
                        </button>
                    </form>
                    {result.series && (
                        <div className="flex flex-wrap items-center gap-4 text-sm text-ink-secondary">
                            <button
                                disabled={result.series.current_page <= 1}
                                className="min-h-11 text-accent underline disabled:opacity-40"
                                onClick={() => setSeriesPage((n) => n - 1)}
                            >
                                Previous series choices
                            </button>
                            <span>
                                Choices page {result.series.current_page} of{' '}
                                {result.series.last_page}
                            </span>
                            <button
                                disabled={result.series.current_page >= result.series.last_page}
                                className="min-h-11 text-accent underline disabled:opacity-40"
                                onClick={() => setSeriesPage((n) => n + 1)}
                            >
                                More series choices
                            </button>
                        </div>
                    )}
                    <form
                        className="grid gap-3 sm:grid-cols-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setApplied({ ...filters });
                            setPage(1);
                        }}
                    >
                        <label className="text-sm text-ink">
                            From
                            <input
                                type="date"
                                value={filters.from}
                                onChange={(event) =>
                                    setFilters({ ...filters, from: event.target.value })
                                }
                                className="mt-1 block min-h-11 w-full border border-border bg-surface px-2 text-ink"
                            />
                        </label>
                        <label className="text-sm text-ink">
                            Until
                            <input
                                type="date"
                                value={filters.until}
                                onChange={(event) =>
                                    setFilters({ ...filters, until: event.target.value })
                                }
                                className="mt-1 block min-h-11 w-full border border-border bg-surface px-2 text-ink"
                            />
                        </label>
                        <label className="text-sm text-ink">
                            Series
                            <select
                                value={filters.event}
                                onChange={(event) =>
                                    setFilters({ ...filters, event: event.target.value })
                                }
                                className="mt-1 block min-h-11 w-full border border-border bg-surface px-2 text-ink"
                            >
                                <option value="">All series</option>
                                {series.map(([id, title]) => (
                                    <option value={id} key={id}>
                                        {title}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button className="min-h-11 self-end border border-border px-4 text-ink">
                            Filter services
                        </button>
                    </form>
                    {!result.services.data.length && (
                        <p className="border border-dashed border-border p-6 text-ink-secondary">
                            No published service notes match these dates.
                        </p>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {result.services.data.map((service) => (
                            <article
                                key={service.id}
                                className="space-y-3 border border-border bg-surface p-5"
                            >
                                <p className="text-xs font-medium text-accent">
                                    {serviceDate(service.starts_at, timezone)}
                                </p>
                                <button
                                    className="min-h-11 text-left text-lg font-semibold text-ink hover:text-accent"
                                    onClick={() => open(service.id)}
                                >
                                    {service.title}
                                </button>
                                <p className="text-sm text-ink-secondary">{service.event_title}</p>
                                <p className="text-xs text-ink-secondary">
                                    {service.notes_count || 1} published{' '}
                                    {service.notes_count === 1 ? 'note' : 'notes'}
                                </p>
                            </article>
                        ))}
                    </div>
                    <div className="flex gap-5">
                        {result.services.prev_page_url && (
                            <button
                                className="min-h-11 text-accent underline"
                                onClick={() => setPage((n) => n - 1)}
                            >
                                Newer services
                            </button>
                        )}
                        {result.services.next_page_url && (
                            <button
                                className="min-h-11 text-accent underline"
                                onClick={() => setPage((n) => n + 1)}
                            >
                                Earlier services
                            </button>
                        )}
                    </div>
                </>
            )}
        </section>
    );
}
