import { useEffect, useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import Modal from '@/Components/Console/Modal';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { 
    Download, 
    Trash2, 
    Landmark, 
    Smartphone, 
    Scale, 
    ShieldCheck, 
    Calendar, 
    Clock, 
    Settings2, 
    CheckCircle2, 
    AlertTriangle,
    Coins
} from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

function formatMoney(amount, currency) {
    return `${currency} ${(amount / 100).toFixed(2)}`;
}

function NewAccountForm({ onDone }) {
    const [form, setForm] = useState({ type: 'bank', label: '', account_name: '', account_number: '', bank_code: '' });
    const [banks, setBanks] = useState([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        csrfFetch(route('tenant.payout-accounts.banks'))
            .then((r) => (r.ok ? r.json() : []))
            .then((list) => setBanks(Array.isArray(list) ? list : []))
            .catch(() => setBanks([]));
    }, []);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        const response = await csrfFetch(route('tenant.payout-accounts.store'), { method: 'POST', body: JSON.stringify(form) });
        setSaving(false);
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            setError(body.message || 'Could not add this account.');
            return;
        }
        setForm({ type: 'bank', label: '', account_name: '', account_number: '', bank_code: '' });
        onDone();
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-2 gap-3 border-t border-border p-4">
            {error && <p className="col-span-2 text-[13px] text-danger-fg">{error}</p>}
            <Select label="Type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                <option value="bank">Bank account</option>
                <option value="mobile_money">Mobile money</option>
            </Select>
            <Select label="Bank" value={form.bank_code} onChange={(e) => setForm({ ...form, bank_code: e.target.value })} required>
                <option value="">Select a bank…</option>
                {banks.map((b) => (
                    <option key={b.code} value={b.code}>{b.name}</option>
                ))}
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
    const [error, setError] = useState(null);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        const response = await csrfFetch(route('tenant.events.finance.payouts.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({
                ...form,
                amount: Math.round(Number(form.amount) * 100),
                scheduled_at: form.scheduled_at || null,
                note: form.note || null,
            }),
        });
        setSaving(false);
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            setError(body.message || 'Could not record this payout.');
            return;
        }
        setForm({ payout_account_id: accounts[0]?.id ?? '', amount: '', scheduled_at: '', note: '' });
        onDone();
    };

    if (accounts.length === 0) {
        return <p className="border-t border-border p-4 text-[13px] text-ink-secondary">Add a payout account above before recording a payout.</p>;
    }

    return (
        <form onSubmit={submit} className="grid grid-cols-4 gap-3 border-t border-border p-4">
            {error && <p className="col-span-4 text-[13px] text-danger-fg">{error}</p>}
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

function PayoutScheduleModal({ open, onClose, event, schedule, accounts, onSaved }) {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [form, setForm] = useState({
        schedule_type: schedule?.schedule_type || 'post_event',
        days_after_event: schedule?.days_after_event ?? 3,
        holdback_percentage: schedule?.holdback_percentage ?? 10,
        holdback_release_days: schedule?.holdback_release_days ?? 14,
        minimum_payout_amount: schedule ? (schedule.minimum_payout_amount / 100).toFixed(2) : '10.00',
        auto_payout_enabled: schedule?.auto_payout_enabled ?? true,
        preferred_account_id: schedule?.preferred_account_id || (accounts[0]?.id ?? ''),
    });

    useEffect(() => {
        if (schedule) {
            setForm({
                schedule_type: schedule.schedule_type || 'post_event',
                days_after_event: schedule.days_after_event ?? 3,
                holdback_percentage: schedule.holdback_percentage ?? 10,
                holdback_release_days: schedule.holdback_release_days ?? 14,
                minimum_payout_amount: (schedule.minimum_payout_amount / 100).toFixed(2),
                auto_payout_enabled: schedule.auto_payout_enabled ?? true,
                preferred_account_id: schedule.preferred_account_id || (accounts[0]?.id ?? ''),
            });
        }
    }, [schedule, accounts]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);

        try {
            const res = await csrfFetch(route('tenant.events.finance.payout-schedule.update', { event: event.id }), {
                method: 'POST',
                body: JSON.stringify({
                    schedule_type: form.schedule_type,
                    days_after_event: parseInt(form.days_after_event, 10) || 0,
                    holdback_percentage: parseFloat(form.holdback_percentage) || 0,
                    holdback_release_days: parseInt(form.holdback_release_days, 10) || 0,
                    minimum_payout_amount: Math.round(parseFloat(form.minimum_payout_amount || 0) * 100),
                    auto_payout_enabled: !!form.auto_payout_enabled,
                    preferred_account_id: form.preferred_account_id || null,
                }),
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                throw new Error(data.message || 'Failed to update payout schedule');
            }

            onSaved();
            onClose();
        } catch (err) {
            setError(err.message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Modal open={open} onClose={onClose} title="Configure Automated Payout & Holdback Policy" className="max-w-xl">
            <form onSubmit={handleSubmit} className="space-y-4">
                {error && (
                    <div className="rounded border border-danger-fg/30 bg-danger-fg/10 p-3 text-xs text-danger-fg">
                        {error}
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <Select
                        label="Settlement Schedule Mode"
                        value={form.schedule_type}
                        onChange={(e) => setForm({ ...form, schedule_type: e.target.value })}
                        required
                    >
                        <option value="post_event">Post-Event Settlement (Recommended)</option>
                        <option value="immediate">Immediate Rolling Settlement (T+0)</option>
                        <option value="manual">Manual Batch Disbursements Only</option>
                    </Select>

                    {form.schedule_type === 'post_event' && (
                        <Input
                            label="Days After Event End (T+N)"
                            type="number"
                            min="0"
                            max="90"
                            value={form.days_after_event}
                            onChange={(e) => setForm({ ...form, days_after_event: e.target.value })}
                            helpText="Number of business days after event completes before initial payout fires."
                            required
                        />
                    )}
                </div>

                <div className="border-t border-border pt-4">
                    <div className="text-xs font-semibold uppercase tracking-wider text-ink-secondary mb-3 flex items-center gap-1.5">
                        <ShieldCheck className="h-4 w-4 text-accent" />
                        Holdback Escrow Reserve Policy
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Input
                            label="Holdback Percentage (%)"
                            type="number"
                            step="0.5"
                            min="0"
                            max="50"
                            value={form.holdback_percentage}
                            onChange={(e) => setForm({ ...form, holdback_percentage: e.target.value })}
                            helpText="Retained temporarily in escrow for refund or chargeback claims."
                            required
                        />

                        <Input
                            label="Holdback Release Duration (Days)"
                            type="number"
                            min="0"
                            max="180"
                            value={form.holdback_release_days}
                            onChange={(e) => setForm({ ...form, holdback_release_days: e.target.value })}
                            helpText="Days after event before the escrow reserve automatically unlocks."
                            required
                        />
                    </div>
                </div>

                <div className="border-t border-border pt-4">
                    <div className="text-xs font-semibold uppercase tracking-wider text-ink-secondary mb-3 flex items-center gap-1.5">
                        <Coins className="h-4 w-4 text-accent" />
                        Disbursement Rules & Account
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <Input
                            label={`Minimum Payout Amount (${event.currency})`}
                            type="number"
                            step="0.01"
                            min="0"
                            value={form.minimum_payout_amount}
                            onChange={(e) => setForm({ ...form, minimum_payout_amount: e.target.value })}
                            helpText="Batches will accumulate until this threshold is reached."
                        />

                        <Select
                            label="Default Payout Account"
                            value={form.preferred_account_id}
                            onChange={(e) => setForm({ ...form, preferred_account_id: e.target.value })}
                        >
                            <option value="">Auto-select first verified account</option>
                            {accounts.map((acc) => (
                                <option key={acc.id} value={acc.id}>
                                    {acc.label} ({acc.masked_account_number})
                                </option>
                            ))}
                        </Select>
                    </div>

                    <div className="mt-4">
                        <Checkbox
                            id="auto_payout_enabled"
                            checked={form.auto_payout_enabled}
                            onChange={(e) => setForm({ ...form, auto_payout_enabled: e.target.checked })}
                            label="Enable automated scheduled disbursements (Reconciliation Engine)"
                            description="When active, the system automatically disburses payable balances to the selected account upon reaching maturity."
                        />
                    </div>
                </div>

                <div className="flex justify-end gap-3 pt-4 border-t border-border">
                    <Button type="button" variant="outline" onClick={onClose} disabled={saving}>
                        Cancel
                    </Button>
                    <Button type="submit" variant="primary" disabled={saving}>
                        {saving ? 'Saving...' : 'Save Policy'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}

export default function FinancePanel({ event }) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const [scheduleModalOpen, setScheduleModalOpen] = useState(false);

    const load = () => {
        csrfFetch(route('tenant.events.finance.index', { event: event.id }))
            .then((r) => {
                if (!r.ok) {
                    throw new Error('Could not load this event’s finances.');
                }
                return r.json();
            })
            .then(setData)
            .catch((e) => setError(e.message));
    };

    useEffect(load, [event.id]);

    const removeAccount = async (id) => {
        setError(null);
        const response = await csrfFetch(route('tenant.payout-accounts.destroy', { account: id }), { method: 'DELETE' });
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            setError(body.message || 'Could not remove this account.');
            return;
        }
        load();
    };

    const markPaid = async (payout) => {
        setError(null);
        const response = await csrfFetch(route('tenant.events.finance.payouts.status', { event: event.id, payout: payout.id }), {
            method: 'PATCH',
            body: JSON.stringify({ status: 'paid' }),
        });
        if (!response.ok) {
            const body = await response.json().catch(() => ({}));
            setError(body.message || 'Could not update this payout.');
            return;
        }
        load();
    };

    if (!data) {
        return error
            ? <p className="text-[13px] text-danger-fg">{error}</p>
            : <p className="text-sm text-ink-secondary">Loading…</p>;
    }

    const { stats, accounts, payouts, trial_balance, payout_schedule } = data;

    return (
        <div className="max-w-4xl space-y-6">
            {/* Header */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 className="text-base font-semibold text-ink">Financial Management &amp; Double-Entry Ledger</h3>
                    <p className="text-xs text-ink-secondary">Real-time balancing, automated settlement reconciliation, and escrow holdback.</p>
                </div>
                <div className="flex items-center gap-2">
                    <Button 
                        variant="outline" 
                        size="sm" 
                        onClick={() => setScheduleModalOpen(true)}
                        className="gap-1.5"
                    >
                        <Settings2 className="h-3.5 w-3.5" />
                        Payout Policy
                    </Button>
                    <a
                        href={route('tenant.events.finance.settlement-statement', { event: event.id })}
                        className="inline-flex h-9 items-center gap-2 border border-border px-3.5 text-xs font-medium text-ink hover:border-accent hover:text-accent transition-colors bg-surface shadow-xs"
                    >
                        <Download className="h-3.5 w-3.5" strokeWidth={1.75} />
                        Settlement Statement (CSV)
                    </a>
                </div>
            </div>

            {error && <p className="border border-danger-fg/30 px-4 py-3 text-[13px] text-danger-fg">{error}</p>}

            {/* Financial Overview Metrics */}
            <div className="grid grid-cols-2 md:grid-cols-5 gap-px bg-border shadow-xs">
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Collected (Gross)</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.collected, event.currency)}</div>
                    <div className="mt-0.5 text-xs text-ink-tertiary">{stats.confirmed_orders} orders</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Refunded</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.refunded, event.currency)}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Platform Fee</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.fees, event.currency)}</div>
                </div>
                <div className="bg-surface p-4">
                    <div className="text-xs text-ink-secondary">Settles to Organizer</div>
                    <div className="mt-1 text-xl font-semibold text-accent">{formatMoney(stats.net_collected, event.currency)}</div>
                    <div className="mt-0.5 text-xs text-ink-tertiary">net of fees &amp; refunds</div>
                </div>
                <div className="bg-surface p-4 col-span-2 md:col-span-1">
                    <div className="text-xs text-ink-secondary">Available Payout</div>
                    <div className="mt-1 text-xl font-semibold text-ink">{formatMoney(stats.available_balance, event.currency)}</div>
                    <div className="mt-0.5 text-xs text-ink-tertiary">{formatMoney(stats.paid_out, event.currency)} disbursed</div>
                </div>
            </div>

            {/* Automated Payout Schedule Banner */}
            <div className="border border-border bg-surface p-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div className="flex items-start gap-3">
                    <div className="p-2 bg-accent/10 text-accent rounded">
                        <Clock className="h-5 w-5" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <h4 className="text-sm font-semibold text-ink">Settlement Schedule &amp; Holdback</h4>
                            <span className="inline-flex items-center rounded-full bg-accent/10 px-2 py-0.5 text-[11px] font-medium text-accent">
                                {payout_schedule?.schedule_type === 'immediate' 
                                    ? 'Immediate (T+0)' 
                                    : payout_schedule?.schedule_type === 'manual' 
                                    ? 'Manual Batch' 
                                    : `T+${payout_schedule?.days_after_event ?? 3} Days Post-Event`}
                            </span>
                            {payout_schedule?.auto_payout_enabled && (
                                <span className="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-600">
                                    Auto-Disburse Active
                                </span>
                            )}
                        </div>
                        <p className="mt-1 text-xs text-ink-secondary">
                            Holdback reserve: <span className="font-semibold text-ink">{payout_schedule?.holdback_percentage ?? 10}%</span> retained for <span className="font-semibold text-ink">{payout_schedule?.holdback_release_days ?? 14} days</span> post-event. 
                            Min threshold: <span className="font-semibold text-ink">{formatMoney(payout_schedule?.minimum_payout_amount ?? 1000, event.currency)}</span>.
                        </p>
                    </div>
                </div>
                <Button variant="outline" size="sm" onClick={() => setScheduleModalOpen(true)}>
                    Change Settings
                </Button>
            </div>

            {/* Double-Entry General Ledger & Trial Balance */}
            {trial_balance && (
                <div className="border border-border bg-surface">
                    <div className="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-border">
                        <div className="flex items-center gap-2">
                            <Scale className="h-4 w-4 text-accent" />
                            <b className="text-sm font-semibold text-ink">Double-Entry Trial Balance</b>
                            <span className="text-xs text-ink-tertiary">| General Ledger</span>
                        </div>
                        <div className="flex items-center gap-3">
                            {trial_balance.is_balanced ? (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-600">
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                    Equilibrium Balanced (&Sigma; Debits = &Sigma; Credits)
                                </span>
                            ) : (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-2.5 py-1 text-xs font-medium text-amber-600">
                                    <AlertTriangle className="h-3.5 w-3.5" />
                                    Ledger Discrepancy
                                </span>
                            )}
                            <div className="text-xs font-mono text-ink-secondary">
                                Debits: <b className="text-ink">{formatMoney(trial_balance.total_debits, event.currency)}</b> | Credits: <b className="text-ink">{formatMoney(trial_balance.total_credits, event.currency)}</b>
                            </div>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <Thead>
                                <Th>Account Code</Th>
                                <Th>Account Name</Th>
                                <Th>Type</Th>
                                <Th numeric>Total Debits</Th>
                                <Th numeric>Total Credits</Th>
                                <Th numeric>Net Balance</Th>
                            </Thead>
                            <tbody>
                                {trial_balance.accounts.map((acc) => (
                                    <Tr key={acc.id || acc.code}>
                                        <Td><span className="font-mono text-xs font-semibold text-ink-secondary">{acc.code}</span></Td>
                                        <Td><span className="font-medium text-ink">{acc.name}</span></Td>
                                        <Td>
                                            <span className="inline-block uppercase tracking-wider text-[10px] font-semibold px-2 py-0.5 rounded bg-surface-subtle text-ink-secondary border border-border">
                                                {acc.type}
                                            </span>
                                        </Td>
                                        <Td numeric className="font-mono text-xs">{formatMoney(acc.debits, event.currency)}</Td>
                                        <Td numeric className="font-mono text-xs">{formatMoney(acc.credits, event.currency)}</Td>
                                        <Td numeric className="font-mono text-xs font-semibold">
                                            {formatMoney(acc.balance, event.currency)}
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                </div>
            )}

            {/* Payout Accounts & Payout History */}
            <div className="grid gap-5 lg:grid-cols-2">
                <div className="border border-border bg-surface">
                    <div className="flex items-center justify-between p-4 border-b border-border">
                        <b className="text-sm font-semibold text-ink">Verified Payout Accounts</b>
                        <span className="text-xs text-ink-secondary">{accounts.length} linked</span>
                    </div>
                    {accounts.length > 0 ? (
                        <ul className="divide-y divide-border">
                            {accounts.map((a) => (
                                <li key={a.id} className="flex items-center gap-3 px-4 py-3">
                                    {a.type === 'mobile_money' ? (
                                        <Smartphone className="h-4 w-4 shrink-0 text-ink-secondary" strokeWidth={1.5} />
                                    ) : (
                                        <Landmark className="h-4 w-4 shrink-0 text-ink-secondary" strokeWidth={1.5} />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-medium text-ink">{a.label}</div>
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
                        <p className="px-4 py-3 text-[13px] text-ink-secondary">No payout accounts yet.</p>
                    )}
                    <NewAccountForm onDone={load} />
                </div>

                <div className="border border-border bg-surface">
                    <div className="p-4 border-b border-border">
                        <b className="text-sm font-semibold text-ink">Disbursement History</b>
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
                                        <Td numeric className="font-semibold">{formatMoney(p.amount, event.currency)}</Td>
                                        <Td><StatusPill status={p.status === 'paid' ? 'success' : 'pending'}>{p.status === 'paid' ? 'Paid' : 'Scheduled'}</StatusPill></Td>
                                        <Td>{p.status !== 'paid' && <Button size="sm" onClick={() => markPaid(p)}>Mark paid</Button>}</Td>
                                    </Tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                    <NewPayoutForm event={event} accounts={accounts} onDone={load} />
                </div>
            </div>

            {/* Payout Schedule Policy Modal */}
            <PayoutScheduleModal
                open={scheduleModalOpen}
                onClose={() => setScheduleModalOpen(false)}
                event={event}
                schedule={payout_schedule}
                accounts={accounts}
                onSaved={load}
            />
        </div>
    );
}
