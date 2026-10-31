import { useState } from 'react';
const money = (amount, currency) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount / 100);
export function GivingStatementForm({ tenantSlug = null, memberId = null, filters = {} }) {
    const today = new Date();
    const [from, setFrom] = useState(filters.from ?? `${today.getFullYear()}-01-01`);
    const [to, setTo] = useState(filters.to ?? today.toLocaleDateString('en-CA'));
    const [format, setFormat] = useState('pdf');
    const [error, setError] = useState('');
    const submit = (e) => {
        e.preventDefault();
        const days = (Date.parse(to) - Date.parse(from)) / 86400000;
        if (!Number.isFinite(days) || days < 0 || days > 366) {
            setError('Choose a valid period of at most 366 days.');
            return;
        }
        const params = {
            ...filters,
            from,
            to,
            ...(format === 'csv' ? { format: 'csv' } : {}),
            ...(tenantSlug ? { tenant_slug: tenantSlug } : {}),
            ...(memberId ? { member_id: memberId } : {}),
        };
        window.location.assign(route('attendee.my.giving.statement', params));
    };
    return (
        <details className="mt-6 border border-border bg-surface p-5">
            <summary className="cursor-pointer text-sm font-medium text-accent">
                Download a contributions statement
            </summary>
            <form onSubmit={submit} className="mt-5 grid items-end gap-4 sm:grid-cols-2">
                <label className="text-sm text-ink">
                    From
                    <input
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        required
                        className="mt-2 block min-h-11 w-full border border-border bg-surface px-3 text-ink"
                    />
                </label>
                <label className="text-sm text-ink">
                    To
                    <input
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        required
                        className="mt-2 block min-h-11 w-full border border-border bg-surface px-3 text-ink"
                    />
                </label>
                <label className="text-sm text-ink">
                    File format
                    <select
                        value={format}
                        onChange={(e) => setFormat(e.target.value)}
                        className="mt-2 block min-h-11 w-full border border-border bg-surface px-3 text-ink"
                    >
                        <option value="pdf">PDF</option>
                        <option value="csv">CSV</option>
                    </select>
                </label>
                <button
                    type="submit"
                    className="min-h-11 border border-accent bg-accent px-5 py-2 text-sm font-medium text-accent-ink"
                >
                    Download statement
                </button>
                {error && (
                    <p role="alert" className="text-sm text-ink sm:col-span-2">
                        {error}
                    </p>
                )}
                <p className="text-xs text-ink-secondary sm:col-span-2">
                    Dates use each organisation’s local timezone. Contributions and later
                    adjustments appear separately. Giving has no amount owed; dues are separate.
                </p>
            </form>
        </details>
    );
}
export default function GivingSection({
    giving,
    onPage = () => {},
    tenantSlug = null,
    statementFilters = {},
}) {
    const rows = giving?.data ?? [];
    return (
        <section className="mt-5 space-y-5">
            <p className="max-w-2xl text-sm text-ink-secondary">
                Your voluntary contributions, recorded offerings and payment receipts. Membership
                and dues are optional. These private records are available online only.
            </p>
            {rows.length === 0 ? (
                <p className="border border-border p-5 text-sm text-ink-secondary">
                    No contributions match your current access or filters. Verify the email recorded
                    for your contribution; a shared phone number alone does not reveal gifts.
                </p>
            ) : (
                <ul className="space-y-4">
                    {rows.map((row) => (
                        <li key={row.key} className="border border-border bg-surface p-5">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="text-xs text-ink-secondary">{row.organisation}</p>
                                    <h3 className="mt-2 text-lg font-medium text-ink">
                                        {row.fund}
                                    </h3>
                                    <p className="mt-2 text-sm text-ink-secondary">
                                        {row.date
                                            ? new Date(row.date).toLocaleDateString(undefined, {
                                                  dateStyle: 'medium',
                                              })
                                            : 'Date not recorded'}{' '}
                                        ·{' '}
                                        {row.source === 'online'
                                            ? 'Online'
                                            : row.source === 'bulk_cash'
                                              ? 'Offering batch'
                                              : 'Recorded cash'}{' '}
                                        · {row.status}
                                    </p>
                                    {row.adjusted_amount > 0 && (
                                        <p className="mt-2 text-sm text-ink-secondary">
                                            Refunds / corrections:{' '}
                                            {money(row.adjusted_amount, row.currency)} · Net
                                            contribution:{' '}
                                            {money(row.net_contribution, row.currency)}
                                        </p>
                                    )}
                                    {row.member_name && (
                                        <p className="mt-2 text-sm text-ink-secondary">
                                            For {row.member_name}
                                            {row.access_role === 'representative'
                                                ? ' · representative access'
                                                : ''}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <p className="text-xl font-medium text-ink">
                                        {money(row.amount, row.currency)}
                                    </p>
                                    {row.receipt_url && (
                                        <a
                                            href={row.receipt_url}
                                            className="mt-3 inline-block min-h-11 py-2 text-sm text-accent underline"
                                        >
                                            Download receipt
                                        </a>
                                    )}
                                </div>
                            </div>
                            {!row.receipt_url && row.member_name && (
                                <p className="mt-3 text-xs text-ink-secondary">
                                    This contribution is attributed to the member. The payer’s
                                    private receipt is separate.
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {giving?.last_page > 1 && (
                <nav aria-label="Contribution pages" className="flex flex-wrap gap-5 text-sm">
                    <button
                        type="button"
                        disabled={giving.current_page <= 1}
                        onClick={() => onPage('giving', giving.current_page - 1)}
                        className="min-h-11 text-accent underline disabled:opacity-40"
                    >
                        Previous contributions
                    </button>
                    <span className="self-center text-ink-secondary">
                        Page {giving.current_page} of {giving.last_page}
                    </span>
                    <button
                        type="button"
                        disabled={giving.current_page >= giving.last_page}
                        onClick={() => onPage('giving', giving.current_page + 1)}
                        className="min-h-11 text-accent underline disabled:opacity-40"
                    >
                        Next contributions
                    </button>
                </nav>
            )}
            <a
                href={route(
                    'attendee.my.giving.index',
                    tenantSlug ? { tenant_slug: tenantSlug } : {}
                )}
                className="inline-block min-h-11 py-2 text-sm text-accent underline"
            >
                Filter contribution records
            </a>
            <GivingStatementForm tenantSlug={tenantSlug} filters={statementFilters} />
        </section>
    );
}
