import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';

const STATUS_MAP = {
    paid: 'success',
    issued: 'pending',
    overdue: 'failed',
    void: 'neutral',
};

function Party({ label, children }) {
    return (
        <div>
            <div className="text-xs font-semibold uppercase text-ink-secondary">{label}</div>
            <div className="mt-1 text-sm text-ink">{children}</div>
        </div>
    );
}

function TotalRow({ label, value, strong = false }) {
    return (
        <div
            className={`flex justify-between gap-6 ${strong ? 'border-t border-border pt-3 text-base font-semibold' : 'text-sm'}`}
        >
            <span className={strong ? 'text-ink' : 'text-ink-secondary'}>{label}</span>
            <span className="num text-ink">{value}</span>
        </div>
    );
}

export default function Invoice({ issuer, invoice }) {
    const [paying, setPaying] = useState(false);
    const money = (amount) => `${invoice.currency} ${amount}`;

    const pay = () => {
        router.post(
            route('billing.checkout'),
            { invoice_id: invoice.id },
            { onStart: () => setPaying(true), onFinish: () => setPaying(false) }
        );
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title={`Invoice ${invoice.number}`}
                actions={
                    <div className="flex gap-2 print:hidden">
                        <Button icon={Printer} onClick={() => window.print()}>
                            Print
                        </Button>
                        {/* A file download, so a plain link rather than an Inertia visit. */}
                        <a
                            href={route('billing.invoices.download', invoice.id)}
                            className="inline-flex h-control items-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink transition-colors duration-120 hover:border-border-strong hover:bg-surface-hover"
                        >
                            <Download className="h-4 w-4" strokeWidth={1.75} />
                            Download PDF
                        </a>
                        {invoice.can_pay && (
                            <Button variant="primary" onClick={pay} disabled={paying}>
                                Pay {money(invoice.total)}
                            </Button>
                        )}
                    </div>
                }
            />

            <div className="max-w-4xl space-y-6 px-4 py-6 sm:px-8">
                <div className="rounded-lg border border-border p-5 sm:p-8">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="num text-2xl font-semibold text-ink">
                                #{invoice.number}
                            </div>
                            {invoice.period && (
                                <div className="mt-1 text-sm text-ink-secondary">
                                    For {invoice.period}
                                </div>
                            )}
                        </div>
                        <StatusPill status={STATUS_MAP[invoice.status] ?? 'neutral'}>
                            {invoice.status}
                        </StatusPill>
                    </div>

                    <div className="mt-8 grid gap-6 sm:grid-cols-3">
                        <Party label="From">{issuer}</Party>
                        <Party label="Bill to">
                            <div>{invoice.bill_to.name}</div>
                            {invoice.bill_to.email && (
                                <div className="text-ink-secondary">{invoice.bill_to.email}</div>
                            )}
                        </Party>
                        <Party label="Dates">
                            <div className="num">Issued {invoice.issued_at}</div>
                            {invoice.due_at && (
                                <div className="num text-ink-secondary">Due {invoice.due_at}</div>
                            )}
                            {invoice.paid_at && (
                                <div className="num text-success-fg">Paid {invoice.paid_at}</div>
                            )}
                        </Party>
                    </div>

                    <div className="mt-8">
                        <Table>
                            <Thead>
                                <Th>Description</Th>
                                <Th align="right">Qty</Th>
                                <Th align="right">Rate</Th>
                                <Th align="right">Amount</Th>
                            </Thead>
                            <tbody>
                                {invoice.items.map((item) => (
                                    <Tr key={item.id}>
                                        <Td>
                                            <div>{item.description}</div>
                                            {item.metric && (
                                                <div className="text-xs text-ink-secondary">
                                                    Metered usage: {item.metric}
                                                </div>
                                            )}
                                        </Td>
                                        <Td align="right" numeric>
                                            {item.quantity}
                                        </Td>
                                        <Td align="right" numeric muted>
                                            {money(item.unit_price)}
                                        </Td>
                                        <Td align="right" numeric>
                                            {money(item.subtotal)}
                                        </Td>
                                    </Tr>
                                ))}
                                {invoice.items.length === 0 && (
                                    <tr>
                                        <td colSpan={4}>
                                            <TableEmpty title="No line items on this invoice" />
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </Table>
                    </div>

                    <div className="mt-6 ml-auto max-w-xs space-y-2">
                        <TotalRow label="Subtotal" value={money(invoice.subtotal)} />
                        {invoice.taxes.map((tax) => (
                            <TotalRow
                                key={tax.name}
                                label={tax.rate !== null ? `${tax.name} (${tax.rate}%)` : tax.name}
                                value={money(tax.amount)}
                            />
                        ))}
                        <TotalRow label="Total" value={money(invoice.total)} strong />
                    </div>
                </div>
            </div>
        </ConsoleLayout>
    );
}
