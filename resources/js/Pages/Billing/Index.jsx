import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';

const STATUS_MAP = {
    success: 'success',
    succeeded: 'success',
    paid: 'success',
    pending: 'pending',
    failed: 'failed',
    issued: 'pending',
    overdue: 'failed',
    void: 'neutral',
};

const CHART_MAX_HEIGHT = 96;

function RevenueChart({ monthlyStats }) {
    const max = Math.max(...monthlyStats.map((stat) => stat.amount), 1);

    return (
        <div className="rounded-lg border border-border p-5">
            <h2 className="text-sm font-semibold text-ink-secondary">Spending analytics</h2>
            <div className="mt-4 flex items-end gap-3" style={{ height: CHART_MAX_HEIGHT + 40 }}>
                {monthlyStats.map((stat) => (
                    <div key={stat.label} className="flex flex-1 flex-col items-center justify-end gap-1.5">
                        <div className="num text-xs text-ink-secondary">${stat.formatted}</div>
                        <div
                            className="w-full rounded-t bg-accent-graph"
                            style={{ height: Math.max((stat.amount / max) * CHART_MAX_HEIGHT, 2) }}
                        />
                        <div className="text-xs font-medium text-ink">{stat.label}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Index({ transactions, invoices, subscription, accruedMetered, monthlyStats }) {
    const [refunding, setRefunding] = useState(null);

    const confirmRefund = () => {
        router.post(route('billing.refund', { transaction: refunding.id }), {}, { onFinish: () => setRefunding(null) });
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Billing" actions={<Button href={route('tenant.pricing')}>Change plan</Button>} />

            <div className="space-y-8 px-8 py-6">
                <div className="grid grid-cols-2 gap-4">
                    <div className="rounded-lg border border-border p-5">
                        <div className="text-xs font-semibold uppercase text-ink-secondary">Current plan</div>
                        <div className="mt-1 text-2xl font-bold text-ink">
                            {subscription?.package_name ?? 'Free'}
                        </div>
                        {subscription?.current_period_end && (
                            <div className="mt-1 text-sm text-ink-secondary">Renews {subscription.current_period_end}</div>
                        )}
                    </div>
                    <div className="rounded-lg border border-border p-5">
                        <div className="text-xs font-semibold uppercase text-ink-secondary">Accrued this month</div>
                        <div className="num mt-1 text-2xl font-bold text-ink">${accruedMetered}</div>
                        <div className="mt-1 text-sm text-ink-secondary">Usage-based charges</div>
                    </div>
                </div>

                <RevenueChart monthlyStats={monthlyStats} />

                <div>
                    <h2 className="mb-3 text-sm font-semibold text-ink-secondary">Invoices</h2>
                    <Table>
                        <Thead>
                            <Th>Number</Th>
                            <Th align="right">Amount</Th>
                            <Th>Status</Th>
                            <Th align="right">Date</Th>
                            <Th />
                        </Thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <Tr key={invoice.id}>
                                    <Td>{invoice.number}</Td>
                                    <Td align="right" numeric>${invoice.total}</Td>
                                    <Td><StatusPill status={STATUS_MAP[invoice.status] ?? 'neutral'}>{invoice.status}</StatusPill></Td>
                                    <Td muted align="right" numeric>{invoice.created_at}</Td>
                                    <Td>
                                        <a
                                            href={route('billing.invoices.show', { invoice: invoice.id })}
                                            className="text-sm font-medium text-accent hover:underline"
                                        >
                                            View
                                        </a>
                                    </Td>
                                </Tr>
                            ))}
                            {invoices.length === 0 && (
                                <tr>
                                    <td colSpan={5}>
                                        <TableEmpty title="No invoices yet" />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </Table>
                </div>

                <div>
                    <h2 className="mb-3 text-sm font-semibold text-ink-secondary">Transactions</h2>
                    <Table>
                        <Thead>
                            <Th>Description</Th>
                            <Th align="right">Amount</Th>
                            <Th>Status</Th>
                            <Th align="right">Date</Th>
                            <Th />
                        </Thead>
                        <tbody>
                            {transactions.data.map((transaction) => (
                                <Tr key={transaction.id} flagged={transaction.status === 'failed'}>
                                    <Td>{transaction.description}</Td>
                                    <Td align="right" numeric>{transaction.currency} {transaction.amount_formatted}</Td>
                                    <Td><StatusPill status={STATUS_MAP[transaction.status] ?? 'neutral'}>{transaction.status}</StatusPill></Td>
                                    <Td muted align="right" numeric>{transaction.created_at}</Td>
                                    <Td>
                                        {transaction.can_refund && (
                                            <button
                                                type="button"
                                                onClick={() => setRefunding(transaction)}
                                                className="text-sm font-medium text-danger-fg hover:underline"
                                            >
                                                Refund
                                            </button>
                                        )}
                                    </Td>
                                </Tr>
                            ))}
                            {transactions.data.length === 0 && (
                                <tr>
                                    <td colSpan={5}>
                                        <TableEmpty title="No transactions yet" />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </Table>
                </div>
            </div>

            <ConfirmModal
                open={refunding !== null}
                onClose={() => setRefunding(null)}
                onConfirm={confirmRefund}
                title="Refund transaction"
                description={refunding && `Refund ${refunding.currency} ${refunding.amount_formatted}?`}
                confirmLabel="Refund"
                danger
            />
        </ConsoleLayout>
    );
}
