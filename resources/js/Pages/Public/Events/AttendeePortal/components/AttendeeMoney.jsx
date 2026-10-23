import { useEffect, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';
import ContributionsSection from './ContributionsSection';
import GivingSection, { GivingStatementForm } from './GivingSection';
const amount = (currency, value) =>
    `${currency} ${(value / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
function MemberRecords({ row, statement = false }) {
    const [charges, setCharges] = useState(null);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    useEffect(() => {
        if (statement) return undefined;
        const controller = new AbortController();
        csrfFetch(`${row.charges_url}?page=${page}`, { signal: controller.signal })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Could not load dues records. Reopen this record to try again.'
                    );
                const data = await response.json();
                setCharges(data.charges);
            })
            .catch((failure) => {
                if (failure.name !== 'AbortError') setError(failure.message);
            });
        return () => controller.abort();
    }, [row.charges_url, page, statement]);
    return (
        <div className="mt-4 border-t border-border pt-4">
            {statement ? (
                <form
                    action={row.statement_url}
                    method="get"
                    className="flex flex-wrap items-end gap-3"
                >
                    <label className="text-xs">
                        From
                        <input
                            required
                            type="date"
                            name="from"
                            className="mt-2 block min-h-11 border border-border bg-surface px-2 text-ink"
                        />
                    </label>
                    <label className="text-xs">
                        To
                        <input
                            required
                            type="date"
                            name="to"
                            className="mt-2 block min-h-11 border border-border bg-surface px-2 text-ink"
                        />
                    </label>
                    <button type="submit" className="app-primary min-h-11 px-4 text-xs">
                        Download statement
                    </button>
                    <p className="w-full text-xs text-ink-secondary">
                        Recorded charges only. Choose up to 366 days.
                    </p>
                </form>
            ) : (
                <>
                    {error && (
                        <p role="alert" className="text-sm text-danger">
                            {error}
                        </p>
                    )}
                    {charges === null && !error && (
                        <p role="status" className="text-sm text-ink-secondary">
                            Loading dues records…
                        </p>
                    )}
                    {charges?.data?.length === 0 && (
                        <p className="text-sm text-ink-secondary">No recorded charges yet.</p>
                    )}
                    {charges?.data?.map((charge) => (
                        <div
                            key={charge.id}
                            className="flex flex-wrap justify-between gap-2 border-b border-border py-3 text-xs"
                        >
                            <span>
                                {charge.period_start} · {charge.status}
                                {charge.payment_method ? ` · ${charge.payment_method}` : ''}
                            </span>
                            <strong>{amount(charge.currency, charge.amount)}</strong>
                            {charge.status === 'paid' && (
                                <a
                                    href={`/my/communities/${encodeURIComponent(row.tenant_slug)}/members/${row.member_id}/receipts/${charge.id}`}
                                    className="min-h-11 content-center text-accent underline"
                                >
                                    Download receipt
                                </a>
                            )}
                        </div>
                    ))}
                    {charges?.last_page > 1 && (
                        <div className="mt-3 flex gap-4 text-xs">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage(page - 1)}
                                className="min-h-11 underline disabled:opacity-40"
                            >
                                Previous charges
                            </button>
                            <button
                                type="button"
                                disabled={page >= charges.last_page}
                                onClick={() => setPage(page + 1)}
                                className="min-h-11 underline disabled:opacity-40"
                            >
                                Next charges
                            </button>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
export default function AttendeeMoney({
    data,
    onPage,
    onRefresh,
    initialTab = null,
    tenantSlug = null,
    status = 'success',
}) {
    const [chosenTab, setTab] = useState(null);
    const hasDues = data?.dues?.data?.some((row) => row.has_dues !== false);
    const tab =
        chosenTab ?? initialTab ?? (!hasDues && data?.giving?.data?.length ? 'giving' : 'dues');
    const [open, setOpen] = useState(null);
    if (status !== 'success')
        return (
            <>
                <h1 className="text-3xl font-medium">Your money records</h1>
                <p
                    role={status === 'error' ? 'alert' : 'status'}
                    className="mt-5 border border-border p-5 text-sm text-ink-secondary"
                >
                    {status === 'error'
                        ? 'Your money records could not be loaded. Use Retry to reconnect.'
                        : 'Loading your authorised dues and contributions…'}
                </p>
            </>
        );
    return (
        <>
            <p className="text-xs uppercase tracking-widest text-accent">Payments & records</p>
            <a href="/my/store-orders" className="mt-4 inline-block text-sm text-accent underline">Store orders & receipts</a>
            <h1 className="mt-2 text-3xl font-medium">Your contributions and payments</h1>
            <p className="mt-3 text-sm text-ink-secondary">
                Keep voluntary contributions, dues and receipts together, with a clear record of
                each.
            </p>
            <div className="mt-6 flex flex-wrap gap-3">
                {[
                    ['giving', 'Giving'],
                    ['dues', 'Dues'],
                    ['contributions', 'Contributions & receipts'],
                    ['statements', 'Statements'],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        aria-pressed={tab === key}
                        onClick={() => {
                            setTab(key);
                            setOpen(null);
                        }}
                        className={`min-h-11 border px-4 py-2 text-xs ${tab === key ? 'border-accent bg-accent/10 text-accent' : 'border-border text-ink-secondary'}`}
                    >
                        {label}
                    </button>
                ))}
            </div>
            {tab === 'giving' ? (
                <GivingSection giving={data?.giving} onPage={onPage} tenantSlug={tenantSlug} />
            ) : tab === 'contributions' ? (
                <section className="mt-5">
                    <p className="mb-4 text-sm text-ink-secondary">
                        Existing event contributions and tributes.
                    </p>
                    {data?.contributions === null ? (
                        <p className="border border-border p-5 text-sm text-ink-secondary">
                            Verify your email to see event contributions and receipts. Phone
                            membership access is separate.
                        </p>
                    ) : (
                        <>
                            <ContributionsSection
                                contributions={data?.contributions?.data || []}
                                onContributionUpdated={onRefresh}
                            />
                            {data?.contributions?.last_page > 1 && (
                                <div className="mt-4 flex gap-5 text-sm">
                                    <button
                                        type="button"
                                        disabled={data.contributions.current_page <= 1}
                                        onClick={() =>
                                            onPage(
                                                'contributions',
                                                data.contributions.current_page - 1
                                            )
                                        }
                                        className="min-h-11 underline disabled:opacity-40"
                                    >
                                        Previous contributions
                                    </button>
                                    <button
                                        type="button"
                                        disabled={
                                            data.contributions.current_page >=
                                            data.contributions.last_page
                                        }
                                        onClick={() =>
                                            onPage(
                                                'contributions',
                                                data.contributions.current_page + 1
                                            )
                                        }
                                        className="min-h-11 underline disabled:opacity-40"
                                    >
                                        Next contributions
                                    </button>
                                </div>
                            )}
                        </>
                    )}
                </section>
            ) : (
                <>
                    {tab === 'statements' && <GivingStatementForm tenantSlug={tenantSlug} />}
                    {tab === 'dues' && (
                        <div className="mt-5 flex flex-wrap gap-4">
                            {data?.totals_by_currency?.map((row) => (
                                <div
                                    key={row.currency}
                                    className="min-w-40 border border-border bg-surface p-5"
                                >
                                    <p className="text-xs text-ink-secondary">
                                        Open dues · {row.currency}
                                    </p>
                                    <strong className="mt-2 block text-2xl font-medium">
                                        {amount(row.currency, row.open_total)}
                                    </strong>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="mt-5 space-y-4">
                        {data?.dues?.data?.length ? (
                            data.dues.data.map((row) => (
                                <article
                                    key={row.member_id}
                                    className="border border-border bg-surface p-5"
                                >
                                    <div className="flex flex-wrap justify-between gap-4">
                                        <div>
                                            <p className="text-xs text-accent">
                                                {row.organisation}
                                            </p>
                                            <h2 className="mt-1 text-lg font-medium">
                                                {row.member_name}
                                            </h2>
                                            <p className="mt-1 text-xs text-ink-secondary">
                                                {row.access_role === 'representative'
                                                    ? 'Representative access'
                                                    : 'Your membership'}{' '}
                                                · {row.status}
                                            </p>
                                        </div>
                                        {tab === 'dues' && (
                                            <div className="text-sm">
                                                {row.totals_by_currency.map((total) => (
                                                    <p key={total.currency}>
                                                        {amount(total.currency, total.open_total)}{' '}
                                                        due
                                                    </p>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <div className="mt-4 flex flex-wrap gap-5">
                                        {tab === 'dues' && (
                                            <a
                                                href={row.pay_url}
                                                className="app-primary inline-flex min-h-11 items-center px-4 text-xs"
                                            >
                                                Review & pay dues
                                            </a>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpen(
                                                    open === row.member_id ? null : row.member_id
                                                )
                                            }
                                            aria-expanded={open === row.member_id}
                                            className="min-h-11 text-xs text-accent underline"
                                        >
                                            {tab === 'statements'
                                                ? 'Choose statement dates'
                                                : 'Charges & receipts'}
                                        </button>
                                    </div>
                                    {open === row.member_id && (
                                        <MemberRecords row={row} statement={tab === 'statements'} />
                                    )}
                                </article>
                            ))
                        ) : (
                            <p className="border border-border p-6 text-sm text-ink-secondary">
                                No authorised dues records yet. Join or recover a membership through
                                Communities. Matching a phone number alone does not grant access.
                            </p>
                        )}
                    </div>
                    {data?.dues?.last_page > 1 && (
                        <nav aria-label="Membership pages" className="mt-5 flex gap-5 text-sm">
                            <button
                                type="button"
                                disabled={data.dues.current_page <= 1}
                                onClick={() => onPage('dues', data.dues.current_page - 1)}
                                className="min-h-11 underline disabled:opacity-40"
                            >
                                Previous memberships
                            </button>
                            <button
                                type="button"
                                disabled={data.dues.current_page >= data.dues.last_page}
                                onClick={() => onPage('dues', data.dues.current_page + 1)}
                                className="min-h-11 underline disabled:opacity-40"
                            >
                                Next memberships
                            </button>
                        </nav>
                    )}
                </>
            )}
        </>
    );
}
