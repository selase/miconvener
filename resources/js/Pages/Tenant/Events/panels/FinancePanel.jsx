import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { Download, Trash2, Landmark, Smartphone } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

function formatMoney(amount, currency) {
    return `${currency} ${(amount / 100).toFixed(2)}`;
}

function NewAccountForm({ onDone }) {
    const [form, setForm] = useState({ type: 'bank', label: '', account_name: '', account_number: '' });
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        await csrfFetch(route('tenant.payout-accounts.store'), { method: 'POST', body: JSON.stringify(form) });
        setSaving(false);
        setForm({ type: 'bank', label: '', account_name: '', account_number: '' });
        onDone();
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 border-t border-border p-4">
            <Select label="Type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                <option value="bank">Bank account</option>
                <option value="mobile_money">Mobile money</option>
            </Select>
            <Input label="Label" placeholder="e.g. Absa Bank Ghana — current" value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} required />
            <Input label="Account name" value={form.account_name} onChange={(e) => setForm({ ...form, account_name: e.target.value })} required />
            <Input label="Account number" value={form.account_number} onChange={(e) => setForm({ ...form, account_number: e.target.value })} required />
            <Button type="submit" variant="primary" disabled={saving} className="col-span-2 justify-center">Add account</Button>
        </form>
    );
}

function NewPayoutForm({ event, accounts, onDone }) {
    const [form, setForm] = useState({ payout_account_id: accounts[0]?.id ?? '', amount: '', scheduled_at: '', note: '' });
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        await csrfFetch(route('tenant.events.finance.payouts.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({
                ...form,
                amount: Math.round(Number(form.amount) * 100),
                scheduled_at: form.scheduled_at || null,
                note: form.note || null,
            }),
        });
        setSaving(false);
        setForm({ payout_account_id: accounts[0]?.id ?? '', amount: '', scheduled_at: '', note: '' });
        onDone();
    };

    if (accounts.length === 0) {
        return <p className="border-t border-border p-4 text-[13px] text-ink-secondary">Add a payout account above before recording a payout.</p>;
    }

    return (
        <form onSubmit={submit} className="grid grid-cols-4 gap-3 border-t border-border p-4">
            <Select label="To account" value={form.payout_account_id} onChange={(e) => setForm({ ...form, payout_account_id: e.target.value })}>
                {accounts.map((a) => (
                    <option key={a.id} value={a.id}>{a.label} ({a.masked_account_number})</option>
                ))}
            </Select>
            <Input label="Amount" type="number" min="0.01" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} required />
            <Input label="Scheduled for" type="date" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} />
            <Input label="Note" value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
            <Button type="submit" variant="primary" disabled={saving} className="col-span-4 justify-center">Record payout</Button>
        </form>
    );
}

export default function FinancePanel({ event }) {
    const [data, setData] = useState(null);

    const load = () => {
        csrfFetch(route('tenant.events.finance.index', { event: event.id }))
            .then((r) => r.json())
            .then(setData);
    };

    useEffect(load, [event.id]);

    const removeAccount = async (id) => {
        await csrfFetch(route('tenant.payout-accounts.destroy', { account: id }), { method: 'DELETE' });
        load();
    };

    const markPaid = async (payout) => {
        await csrfFetch(route('tenant.events.finance.payouts.status', { event: event.id, payout: payout.id }), {
            method: 'PATCH',
            body: JSON.stringify({ status: 'paid' }),
        });
        load();
    };

    if (!data) {
        return <p className="text-sm text-ink-secondary">Loading…</p>;
    }

    const { stats, accounts, payouts } = data;

    return (
        <div className="max-w-4xl">
            <div className="mb-5 flex items-center justify-between">
                <p className="text-sm text-ink-secondary">What you collected, what it cost, and where it goes.</p>
                <a
                    href={route('tenant.events.finance.settlement-statement', { event: event.id })}
                    className="inline-flex h-control items-center gap-2 border border-border px-4 text-sm text-ink hover:border-accent"
                >
                    <Download className="h-4 w-4" strokeWidth={1.75} />
                    Settlement statement
                </a>
            </div>

            <div className="mb-6 grid grid-cols-4 gap-px bg-border">
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Collected</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.collected, event.currency)}</div>
                    <div className="mt-0.5 text-xs text-ink-tertiary">{stats.confirmed_orders} orders</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Platform fee</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.fees, event.currency)}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Settles to you</div>
                    <div className="mt-1 text-xl font-semibold text-accent">{formatMoney(stats.net, event.currency)}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Paid out so far</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.paid_out, event.currency)}</div>
                </div>
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
                <div className="border border-border">
                    <div className="flex items-center justify-between p-4">
                        <b className="text-sm font-medium text-ink">Where the money goes</b>
                    </div>
                    {accounts.length > 0 ? (
                        <ul className="divide-y divide-border border-t border-border">
                            {accounts.map((a) => (
                                <li key={a.id} className="flex items-center gap-3 px-4 py-3">
                                    {a.type === 'mobile_money' ? (
                                        <Smartphone className="h-4 w-4 shrink-0 text-ink-secondary" strokeWidth={1.5} />
                                    ) : (
                                        <Landmark className="h-4 w-4 shrink-0 text-ink-secondary" strokeWidth={1.5} />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] text-ink">{a.label}</div>
                                        <div className="font-mono text-xs text-ink-tertiary">{a.masked_account_number}</div>
                                    </div>
                                    <StatusPill status={a.is_verified ? 'success' : 'pending'}>{a.is_verified ? 'Verified' : 'Unverified'}</StatusPill>
                                    <button onClick={() => removeAccount(a.id)} title="Remove" className="text-ink-secondary hover:text-danger-fg">
                                        <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="border-t border-border px-4 py-3 text-[13px] text-ink-secondary">No payout accounts yet.</p>
                    )}
                    <NewAccountForm onDone={load} />
                </div>

                <div className="border border-border">
                    <div className="p-4">
                        <b className="text-sm font-medium text-ink">Payouts</b>
                    </div>
                    <Table>
                        <Thead>
                            <Th>To</Th>
                            <Th>Amount</Th>
                            <Th>Status</Th>
                            <Th></Th>
                        </Thead>
                        <tbody>
                            {payouts.length === 0 ? (
                                <tr>
                                    <td colSpan={4}>
                                        <TableEmpty title="No payouts yet" description="Record one below once you're ready to send funds out." />
                                    </td>
                                </tr>
                            ) : (
                                payouts.map((p) => (
                                    <Tr key={p.id}>
                                        <Td muted>{p.payout_account_label} <span className="font-mono text-xs">{p.payout_account_masked_number}</span></Td>
                                        <Td numeric>{formatMoney(p.amount, event.currency)}</Td>
                                        <Td><StatusPill status={p.status === 'paid' ? 'success' : 'pending'}>{p.status === 'paid' ? 'Paid' : 'Scheduled'}</StatusPill></Td>
                                        <Td>{p.status !== 'paid' && <Button onClick={() => markPaid(p)}>Mark paid</Button>}</Td>
                                    </Tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                    <NewPayoutForm event={event} accounts={accounts} onDone={load} />
                </div>
            </div>
        </div>
    );
}
