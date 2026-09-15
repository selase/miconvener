import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
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

function TokenPacks({ packs }) {
    const [buying, setBuying] = useState(null);

    const buy = (pack) => {
        setBuying(pack.key);
        router.post(route('billing.llm-checkout'), { pack: pack.key }, { onFinish: () => setBuying(null) });
    };

    return (
        <section>
            <h2 className="text-sm font-semibold text-ink">Buy AI tokens</h2>
            <p className="mt-1 text-sm text-ink-secondary">
                Tokens you buy are used when your plan's monthly allowance runs out. They don't expire.
            </p>
            <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
                {packs.map((pack) => (
                    <div key={pack.key} className="flex flex-col rounded-lg border border-border bg-surface p-5">
                        <div className="text-sm font-medium text-ink">{pack.name}</div>
                        <div className="num mt-1 text-2xl font-bold text-ink">{pack.price}</div>
                        <div className="num mt-1 text-sm text-ink-secondary">{pack.tokens.toLocaleString()} tokens</div>
                        <Button
                            className="mt-4 self-start"
                            variant="primary"
                            disabled={buying !== null}
                            onClick={() => buy(pack)}
                            aria-label={`Buy ${pack.name} for ${pack.price}`}
                        >
                            {buying === pack.key ? 'Opening Paystack…' : 'Buy'}
                        </Button>
                    </div>
                ))}
            </div>
        </section>
    );
}

export default function Index({ totalUsage, recentUsage, topupBalance, tokenPacks = [] }) {
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

                {tokenPacks.length > 0 && <TokenPacks packs={tokenPacks} />}

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
