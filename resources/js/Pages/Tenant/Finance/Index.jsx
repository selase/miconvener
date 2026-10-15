import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import Input from '@/Components/Console/Input';
import { formatDuesMoney } from '@/lib/dues';
import { router } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import StatusBanner from '@/Components/Console/StatusBanner';
import Drawer from '@/Components/Console/Drawer';
import Button from '@/Components/Console/Button';
import DetailCard from '@/Components/Console/DetailCard';
import CopyField from '@/Components/Console/CopyField';
import SearchInput from '@/Components/Console/SearchInput';
import Select from '@/Components/Console/Select';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';

const STATUS_MAP = {
    succeeded: 'success',
    pending: 'pending',
    failed: 'failed',
    refunded: 'neutral',
};

const STATUS_WORD = {
    succeeded: 'Successful',
    pending: 'Pending',
    failed: 'Failed',
    refunded: 'Refunded',
};

const STATUS_DESCRIPTION = {
    succeeded: 'Transaction has been completed successfully.',
    pending: 'Awaiting confirmation from the payment provider.',
    failed: 'This payment was declined or could not be completed.',
    refunded: 'This payment has been refunded to the customer.',
};

function StatCard({ label, value }) {
    return (
        <div className="rounded-lg border border-border p-5">
            <div className="text-xs font-semibold uppercase text-ink-secondary">{label}</div>
            <div className="num mt-1 text-2xl font-bold text-ink">{value}</div>
        </div>
    );
}

function DuesSection({ dues }) {
    const [from, setFrom] = useState(dues.from);
    const [to, setTo] = useState(dues.to);
    const [processing, setProcessing] = useState(false);
    const errors = usePage().props.errors ?? {};
    const currencies = Object.entries(dues.currencies);
    const collectionUrl = `${route('tenant.finance.dues.collections.export')}?${new URLSearchParams({ from: dues.from, to: dues.to })}`;
    const metrics = [
        ['collected_total', 'Total collected'],
        ['collected_online_gross', 'Collected online'],
        ['recorded_cash', 'Recorded cash'],
        ['gateway_fees', 'Gateway fees'],
        ['commission', 'Commission'],
        ['net_online', 'Net online'],
    ];

    return (
        <section aria-label="Dues finance" className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 className="text-lg font-semibold text-ink">Dues collections</h2>
                    <p className="mt-1 text-sm text-ink-secondary">
                        Online receipts and recorded cash, by payment date in {dues.timezone}.
                    </p>
                </div>
                <a
                    href={route('tenant.finance.payouts.index')}
                    className="text-sm font-medium text-accent underline"
                >
                    View payouts
                </a>
            </div>
            <form
                className="flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    setProcessing(true);
                    router.get(
                        route('tenant.finance.index'),
                        { from, to },
                        {
                            preserveScroll: true,
                            onFinish: () => setProcessing(false),
                        }
                    );
                }}
            >
                <Input
                    aria-label="From"
                    label="From"
                    type="date"
                    value={from}
                    onChange={(event) => setFrom(event.target.value)}
                    error={errors.from}
                    required
                />
                <Input
                    aria-label="To"
                    label="To"
                    type="date"
                    value={to}
                    onChange={(event) => setTo(event.target.value)}
                    error={errors.to}
                    required
                />
                <Button type="submit" disabled={processing}>
                    {processing ? 'Applying…' : 'Apply dates'}
                </Button>
            </form>
            <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <a className="font-medium text-accent underline" href={collectionUrl}>
                    Export collections CSV
                </a>
                <a
                    className="font-medium text-accent underline"
                    href={route('tenant.finance.dues.arrears.export')}
                >
                    Export current arrears CSV
                </a>
            </div>
            <p className="text-xs text-ink-secondary">
                Exports contain integer minor units (for example 100 = 1.00). Arrears are current
                and do not follow the collection date filter.
            </p>
            {currencies.length === 0 && (
                <div className="rounded-lg border border-border p-6 text-sm text-ink-secondary">
                    No dues collections or current balances to show. Try a wider collection date
                    range.
                </div>
            )}
            {currencies.map(([currency, amounts]) => (
                <div key={currency} className="overflow-hidden rounded-lg border border-border">
                    <div className="border-b border-border bg-surface-sunken px-5 py-3">
                        <h3 className="font-semibold text-ink">{currency}</h3>
                        <p className="mt-1 text-xs text-ink-secondary">
                            Collections: {dues.from} to {dues.to}
                        </p>
                    </div>
                    <dl className="grid grid-cols-2 gap-5 p-5 lg:grid-cols-3">
                        {metrics.map(([key, label]) => (
                            <div key={key}>
                                <dt className="text-xs text-ink-secondary">{label}</dt>
                                <dd className="num mt-1 font-semibold text-ink">
                                    {formatDuesMoney(amounts[key], currency)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    <div className="border-t border-border bg-surface-sunken p-5">
                        <p className="mb-4 text-xs font-semibold uppercase text-ink-secondary">
                            Current balances · independent of collection dates
                        </p>
                        <dl className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                            {[
                                ['available_to_pay_out', 'Current available to pay out'],
                                ['in_flight', 'Current payouts in flight'],
                                ['arrears_total', 'Current overdue dues'],
                            ].map(([key, label]) => (
                                <div key={key}>
                                    <dt className="text-xs text-ink-secondary">{label}</dt>
                                    <dd className="num mt-1 font-semibold text-ink">
                                        {formatDuesMoney(amounts[key], currency)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mt-4 text-xs text-ink-secondary">
                            {amounts.members_in_arrears}{' '}
                            {amounts.members_in_arrears === 1 ? 'member' : 'members'} in arrears.
                            Cash is already held by your organisation and never increases available
                            to pay out.
                        </p>
                    </div>
                </div>
            ))}
        </section>
    );
}

export default function Index({ transactions, stats, filters, dues }) {
    const [selected, setSelected] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const [refunding, setRefunding] = useState(null);

    const applyFilters = (overrides = {}) => {
        router.get(
            route('tenant.finance.index'),
            {
                search,
                status: filters.status,
                ...(dues ? { from: dues.from, to: dues.to } : {}),
                ...overrides,
            },
            { preserveState: true }
        );
    };

    const confirmRefund = () => {
        router.post(
            route('tenant.finance.refund', { transaction: refunding.id }),
            {},
            {
                onFinish: () => {
                    setRefunding(null);
                    setSelected(null);
                },
            }
        );
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Finance" />

            <div className="flex">
                <div
                    className={
                        dues
                            ? 'min-w-0 flex-1 space-y-6 px-4 py-6 sm:px-8'
                            : 'min-w-0 flex-1 space-y-6 px-8 py-6'
                    }
                >
                    {dues && <DuesSection key={`${dues.from}-${dues.to}`} dues={dues} />}
                    {(!dues || dues.show_ticket_finance) && (
                        <>
                            {dues && (
                                <h2 className="text-lg font-semibold text-ink">
                                    Ticket transactions
                                </h2>
                            )}
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <StatCard
                                    label="Total volume"
                                    value={(stats.total_volume / 100).toLocaleString()}
                                />
                                <StatCard label="Transactions" value={stats.transaction_count} />
                                <StatCard
                                    label="Refunded"
                                    value={(stats.refund_volume / 100).toLocaleString()}
                                />
                            </div>

                            <div className="flex flex-wrap gap-3">
                                <SearchInput
                                    placeholder="Search name, email, or reference"
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    onKeyDown={(event) => event.key === 'Enter' && applyFilters()}
                                />
                                <Select
                                    value={filters.status ?? ''}
                                    onChange={(event) =>
                                        applyFilters({ status: event.target.value })
                                    }
                                    className="h-control w-auto py-0 font-medium"
                                >
                                    <option value="">All statuses</option>
                                    <option value="succeeded">Succeeded</option>
                                    <option value="pending">Pending</option>
                                    <option value="failed">Failed</option>
                                    <option value="refunded">Refunded</option>
                                </Select>
                            </div>

                            <Table>
                                <Thead>
                                    <Th>Customer</Th>
                                    <Th align="right">Amount</Th>
                                    <Th>Status</Th>
                                    <Th>Reference</Th>
                                    <Th align="right">Date</Th>
                                </Thead>
                                <tbody>
                                    {transactions.data.map((transaction) => (
                                        <Tr
                                            key={transaction.id}
                                            onClick={() => setSelected(transaction)}
                                            selected={selected?.id === transaction.id}
                                            flagged={transaction.status === 'failed'}
                                        >
                                            <Td>
                                                <div className="font-medium text-ink">
                                                    {transaction.customer_name ?? '—'}
                                                </div>
                                                <div className="text-xs text-ink-secondary">
                                                    {transaction.customer_email}
                                                </div>
                                            </Td>
                                            <Td align="right" numeric>
                                                {transaction.currency}{' '}
                                                {transaction.amount_formatted}
                                            </Td>
                                            <Td>
                                                <StatusPill
                                                    status={
                                                        STATUS_MAP[transaction.status] ?? 'neutral'
                                                    }
                                                >
                                                    {STATUS_WORD[transaction.status] ??
                                                        transaction.status}
                                                </StatusPill>
                                            </Td>
                                            <Td muted>
                                                <span className="num">
                                                    {transaction.provider_transaction_id}
                                                </span>
                                            </Td>
                                            <Td muted align="right" numeric>
                                                {transaction.created_at}
                                            </Td>
                                        </Tr>
                                    ))}
                                    {transactions.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5}>
                                                <TableEmpty
                                                    title="No transactions found"
                                                    description="Try widening your filters."
                                                />
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </Table>
                        </>
                    )}
                </div>

                <Drawer open={selected !== null} onClose={() => setSelected(null)}>
                    {selected && (
                        <div>
                            <div className="flex items-center justify-between gap-4">
                                <div className="num text-[32px] font-bold leading-9.5 tracking-tight text-ink">
                                    {selected.amount_formatted} {selected.currency}
                                </div>
                                {selected.can_refund && (
                                    <Button icon={Undo2} onClick={() => setRefunding(selected)}>
                                        Refund
                                    </Button>
                                )}
                            </div>

                            <div className="mt-5">
                                <StatusBanner
                                    status={STATUS_MAP[selected.status] ?? 'neutral'}
                                    title={STATUS_WORD[selected.status] ?? selected.status}
                                    description={STATUS_DESCRIPTION[selected.status]}
                                />
                            </div>

                            <div className="mt-5 space-y-5">
                                <DetailCard title="Customer">
                                    <CopyField label="Name" value={selected.customer_name ?? '—'} />
                                    <CopyField
                                        label="Email"
                                        value={selected.customer_email ?? '—'}
                                    />
                                </DetailCard>

                                <DetailCard title="Payment info">
                                    <CopyField
                                        label="Reference"
                                        value={selected.provider_transaction_id}
                                    />
                                    <CopyField label="Provider" value={selected.provider} />
                                    <CopyField label="Type" value={selected.type} />
                                    <CopyField label="Date" value={selected.created_at} />
                                </DetailCard>
                            </div>
                        </div>
                    )}
                </Drawer>
            </div>

            <ConfirmModal
                open={refunding !== null}
                onClose={() => setRefunding(null)}
                onConfirm={confirmRefund}
                title="Refund transaction"
                description={
                    refunding &&
                    `Refund ${refunding.currency} ${refunding.amount_formatted} to ${refunding.customer_email}?`
                }
                confirmLabel="Refund"
                danger
            />
        </ConsoleLayout>
    );
}
