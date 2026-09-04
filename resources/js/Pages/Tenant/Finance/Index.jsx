import { useState } from 'react';
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

export default function Index({ transactions, stats, filters }) {
    const [selected, setSelected] = useState(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const [refunding, setRefunding] = useState(null);

    const applyFilters = (overrides = {}) => {
        router.get(route('tenant.finance.index'), { search, status: filters.status, ...overrides }, { preserveState: true });
    };

    const confirmRefund = () => {
        router.post(route('tenant.finance.refund', { transaction: refunding.id }), {}, {
            onFinish: () => {
                setRefunding(null);
                setSelected(null);
            },
        });
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Finance" />

            <div className="flex">
                <div className="min-w-0 flex-1 space-y-6 px-8 py-6">
                    <div className="grid grid-cols-3 gap-4">
                        <StatCard label="Total volume" value={(stats.total_volume / 100).toLocaleString()} />
                        <StatCard label="Transactions" value={stats.transaction_count} />
                        <StatCard label="Refunded" value={(stats.refund_volume / 100).toLocaleString()} />
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
                            onChange={(event) => applyFilters({ status: event.target.value })}
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
                                        <div className="font-medium text-ink">{transaction.customer_name ?? '—'}</div>
                                        <div className="text-xs text-ink-secondary">{transaction.customer_email}</div>
                                    </Td>
                                    <Td align="right" numeric>{transaction.currency} {transaction.amount_formatted}</Td>
                                    <Td>
                                        <StatusPill status={STATUS_MAP[transaction.status] ?? 'neutral'}>
                                            {STATUS_WORD[transaction.status] ?? transaction.status}
                                        </StatusPill>
                                    </Td>
                                    <Td muted><span className="num">{transaction.provider_transaction_id}</span></Td>
                                    <Td muted align="right" numeric>{transaction.created_at}</Td>
                                </Tr>
                            ))}
                            {transactions.data.length === 0 && (
                                <tr>
                                    <td colSpan={5}>
                                        <TableEmpty title="No transactions found" description="Try widening your filters." />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </Table>
                </div>

                <Drawer open={selected !== null} onClose={() => setSelected(null)}>
                    {selected && (
                        <div>
                            <div className="flex items-center justify-between gap-4">
                                <div className="num text-[32px] font-bold leading-9.5 tracking-tight text-ink">
                                    {selected.amount_formatted} {selected.currency}
                                </div>
                                {selected.can_refund && (
                                    <Button icon={Undo2} onClick={() => setRefunding(selected)}>Refund</Button>
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
                                    <CopyField label="Email" value={selected.customer_email ?? '—'} />
                                </DetailCard>

                                <DetailCard title="Payment info">
                                    <CopyField label="Reference" value={selected.provider_transaction_id} />
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
                description={refunding && `Refund ${refunding.currency} ${refunding.amount_formatted} to ${refunding.customer_email}?`}
                confirmLabel="Refund"
                danger
            />
        </ConsoleLayout>
    );
}
