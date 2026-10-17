import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { formatDuesMoney } from '@/lib/dues';
export default function GivingReport({ summary, filters, funds }) {
    const [form, setForm] = useState({
        from: filters.from,
        to: filters.to,
        fund: filters.fund ?? '',
        currency: filters.currency ?? '',
    });
    const params = Object.fromEntries(Object.entries(form).filter(([, v]) => v));
    return (
        <ConsoleLayout>
            <PageHeader title="Giving report" />
            <main className="space-y-6 px-4 py-6 sm:px-8">
                <p className="max-w-2xl text-sm text-ink-secondary">
                    Collections and adjustments in the selected period, using your organisation’s
                    timezone. Available is the current pooled Giving balance across all funds, not a
                    fund balance.
                </p>
                <form
                    className="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(route('tenant.community.money.giving.report'), params);
                    }}
                >
                    <Input
                        label="From"
                        type="date"
                        value={form.from}
                        onChange={(e) => setForm({ ...form, from: e.target.value })}
                    />
                    <Input
                        label="To"
                        type="date"
                        value={form.to}
                        onChange={(e) => setForm({ ...form, to: e.target.value })}
                    />
                    <Select
                        label="Fund"
                        value={form.fund}
                        onChange={(e) => setForm({ ...form, fund: e.target.value })}
                    >
                        <option value="">All funds</option>
                        {funds.map((f) => (
                            <option key={f.id} value={f.id}>
                                {f.name}
                            </option>
                        ))}
                    </Select>
                    <Select
                        label="Currency"
                        value={form.currency}
                        onChange={(e) => setForm({ ...form, currency: e.target.value })}
                    >
                        <option value="">All currencies</option>
                        {['GHS', 'USD', 'EUR', 'GBP'].map((c) => (
                            <option key={c}>{c}</option>
                        ))}
                    </Select>
                    <Button type="submit">Apply filters</Button>
                </form>
                <a
                    className="inline-block text-sm text-accent underline"
                    href={route('tenant.community.money.giving.report', {
                        ...params,
                        format: 'csv',
                    })}
                >
                    Download summary CSV (minor units)
                </a>
                {Object.keys(summary).length === 0 ? (
                    <p role="status" className="border border-border p-6 text-ink-secondary">
                        No contributions or adjustments in this period. Try a different date range
                        or fund.
                    </p>
                ) : (
                    Object.entries(summary).map(([currency, row]) => (
                        <section key={currency} className="border border-border p-6">
                            <h2 className="text-xl font-semibold text-ink">{currency}</h2>
                            <dl className="mt-5 grid gap-5 sm:grid-cols-3">
                                {[
                                    ['collected_online', 'Collected online'],
                                    ['recorded_cash', 'Recorded cash'],
                                    ['refunds', 'Refunds'],
                                    ['corrections', 'Cash corrections'],
                                    ['net_online', 'Net online'],
                                    ['available', 'Available for payout now'],
                                ].map(([key, label]) => (
                                    <div key={key}>
                                        <dt className="text-sm text-ink-secondary">{label}</dt>
                                        <dd className="mt-1 font-semibold text-ink">
                                            {formatDuesMoney(row[key], currency)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))
                )}
            </main>
        </ConsoleLayout>
    );
}
