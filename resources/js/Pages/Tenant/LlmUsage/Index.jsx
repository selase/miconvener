import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';

function StatCard({ label, value }) {
    return (
        <div className="rounded-lg border border-border bg-surface p-5">
            <div className="text-xs font-semibold uppercase text-ink-secondary">{label}</div>
            <div className="mt-1 text-2xl font-bold text-ink">{value}</div>
        </div>
    );
}

export default function Index({ totalUsage, recentUsage, topupBalance }) {
    return (
        <ConsoleLayout>
            <PageHeader title="LLM Usage" />

            <div className="space-y-6 px-8 py-6">
                <div className="grid grid-cols-4 gap-4">
                    <StatCard label="Total tokens" value={totalUsage.total_tokens.toLocaleString()} />
                    <StatCard label="Prompt tokens" value={totalUsage.prompt_tokens.toLocaleString()} />
                    <StatCard label="Completion tokens" value={totalUsage.completion_tokens.toLocaleString()} />
                    <StatCard label="Total cost" value={`$${totalUsage.total_cost}`} />
                </div>

                {topupBalance > 0 && (
                    <div className="rounded-lg border border-border bg-surface-sunken px-5 py-3 text-sm text-ink-secondary">
                        Top-up balance: <span className="num font-medium text-ink">{topupBalance.toLocaleString()}</span> tokens
                    </div>
                )}

                <Table>
                    <Thead>
                        <Th>Model</Th>
                        <Th>Tokens</Th>
                        <Th>Cost</Th>
                        <Th>User</Th>
                        <Th>API key</Th>
                        <Th>Date</Th>
                    </Thead>
                    <tbody>
                        {recentUsage.data.map((usage) => (
                            <Tr key={usage.id}>
                                <Td>{usage.model}</Td>
                                <Td muted numeric>{usage.total_tokens.toLocaleString()}</Td>
                                <Td muted numeric>${usage.cost_usd}</Td>
                                <Td muted>{usage.user ?? '—'}</Td>
                                <Td muted>{usage.api_key ?? '—'}</Td>
                                <Td muted numeric>{usage.created_at}</Td>
                            </Tr>
                        ))}
                        {recentUsage.data.length === 0 && (
                            <tr>
                                <td colSpan={6}>
                                    <TableEmpty title="No LLM usage recorded yet" />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </Table>
            </div>
        </ConsoleLayout>
    );
}
