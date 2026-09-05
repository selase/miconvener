import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import { Download } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

const OTHER_EXPORTS = [
    { key: 'settlement', label: 'Settlement statement', route: 'tenant.events.finance.settlement-statement' },
    { key: 'checkins', label: 'Check-in log', route: 'tenant.events.reports.checkins' },
    { key: 'forum', label: 'Forum activity', route: 'tenant.events.reports.forum' },
    { key: 'polls', label: 'Poll results', route: 'tenant.events.reports.polls' },
    { key: 'attendee-directory', label: 'Attendee directory', route: 'tenant.events.reports.attendee-directory' },
    { key: 'session-attendance', label: 'Session attendance', route: 'tenant.events.reports.session-attendance' },
    { key: 'dietary-accessibility', label: 'Dietary & accessibility summary', route: 'tenant.events.reports.dietary-accessibility' },
    { key: 'audit-log', label: 'Audit log', route: 'tenant.events.reports.audit-log' },
    { key: 'certificates', label: 'Certificates (ZIP)', route: 'tenant.events.reports.certificates' },
];

export default function ReportsPanel({ event }) {
    const [data, setData] = useState(null);
    const [cols, setCols] = useState([]);

    useEffect(() => {
        csrfFetch(route('tenant.events.reports.index', { event: event.id }))
            .then((r) => r.json())
            .then((d) => {
                setData(d);
                setCols(d.default_columns);
            });
    }, [event.id]);

    const toggleCol = (key) => setCols((prev) => (prev.includes(key) ? prev.filter((x) => x !== key) : [...prev, key]));

    if (!data) {
        return <p className="text-sm text-ink-secondary">Loading…</p>;
    }

    const registrationsUrl = `${route('tenant.events.reports.registrations', { event: event.id })}?${cols.map((c) => `columns[]=${encodeURIComponent(c)}`).join('&')}`;

    return (
        <div className="max-w-4xl">
            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-ink-secondary">Anything here can leave as a spreadsheet.</p>
            </div>

            <div className="mb-5 grid grid-cols-4 gap-px bg-border">
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Registrations</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{data.counts.registrations}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Checked in</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{data.counts.checked_in}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Forum threads</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{data.counts.forum_threads}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Poll responses</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{data.counts.poll_responses}</div>
                </div>
            </div>

            <div className="border border-border p-4">
                <b className="text-sm font-medium text-ink">Build a registrations report</b>
                <div className="mt-3.5">
                    <label className="mb-1.5 block text-sm font-medium text-ink">Columns</label>
                    <div className="flex flex-wrap gap-1.5">
                        {data.available_columns.map((c) => (
                            <button
                                key={c.key}
                                onClick={() => toggleCol(c.key)}
                                className={`border px-2.5 py-1 text-xs ${cols.includes(c.key) ? 'border-accent bg-accent-soft text-accent' : 'border-border text-ink-secondary'}`}
                            >
                                {c.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="mt-4 flex items-center justify-between border-t border-border pt-3.5">
                    <span className="text-xs text-ink-secondary">
                        <span className="font-mono">{cols.length}</span> columns selected, about <span className="font-mono">{data.counts.registrations}</span> rows
                    </span>
                    <a href={registrationsUrl}>
                        <Button icon={Download} variant="primary" disabled={cols.length === 0}>Download CSV</Button>
                    </a>
                </div>
            </div>

            <div className="mt-5 border border-border p-4">
                <b className="text-sm font-medium text-ink">Everything else you can export</b>
                <div className="mt-3 flex flex-wrap gap-2">
                    {OTHER_EXPORTS.map((x) => (
                        <a
                            key={x.key}
                            href={route(x.route, { event: event.id })}
                            className="flex items-center gap-1.5 border border-border px-2.5 py-1 text-xs text-ink-secondary hover:border-accent hover:text-accent"
                        >
                            <Download className="h-3 w-3" strokeWidth={1.75} />
                            {x.label}
                        </a>
                    ))}
                </div>
            </div>
        </div>
    );
}
