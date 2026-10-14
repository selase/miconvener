import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Modal from '@/Components/Console/Modal';
import StatusPill from '@/Components/Console/StatusPill';
import { useToast } from '@/Components/Console/Toast';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { formatDuesMoney, sendJson, toMinorUnits } from '@/lib/dues';
import csrfFetch from '@/lib/csrfFetch';

const PAYOUT_LABEL = {
    scheduled: 'Scheduled',
    awaiting_otp: 'Awaiting code',
    processing: 'Sending',
    paid: 'Paid',
    failed: 'Failed',
};

const PAYOUT_PILL = {
    scheduled: 'pending',
    awaiting_otp: 'pending',
    processing: 'pending',
    paid: 'success',
    failed: 'failed',
};

function RequestPayoutModal({ balances, accounts, onClose, onDone, onAddAccount }) {
    const showToast = useToast();
    const [form, setForm] = useState({
        payout_account_id: accounts[0]?.id ?? '',
        currency: balances[0]?.currency ?? '',
        amount: '',
    });
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    const balance = balances.find((b) => b.currency === form.currency);

    const submit = async (event) => {
        event.preventDefault();
        const amount = toMinorUnits(form.amount);

        if (amount === null || Number.isNaN(amount) || amount < 1) {
            setErrors({ amount: 'Enter an amount greater than zero, such as 50 or 49.99.' });
            return;
        }

        setProcessing(true);
        const result = await sendJson('POST', route('tenant.dues.payouts.store'), {
            ...form,
            amount,
        });
        setProcessing(false);

        if (!result.ok) {
            setErrors(result.errors);
            if (Object.keys(result.errors).length === 0) {
                showToast?.(result.message);
            }
            return;
        }

        showToast?.('Payout requested. Send it from the list below.');
        onDone();
    };

    return (
        <Modal open onClose={onClose} title="Request payout">
            <form onSubmit={submit} className="space-y-4">
                <Select
                    label="Pay into"
                    value={form.payout_account_id}
                    onChange={(e) => setForm({ ...form, payout_account_id: e.target.value })}
                    error={errors.payout_account_id}
                >
                    {accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.label} · {account.masked_account_number}
                        </option>
                    ))}
                </Select>
                {onAddAccount && (
                    <button
                        type="button"
                        onClick={onAddAccount}
                        className="-mt-2 text-xs font-medium text-accent hover:underline"
                    >
                        Add payout account
                    </button>
                )}
                {balances.length > 1 && (
                    <Select
                        label="Currency"
                        value={form.currency}
                        onChange={(e) => setForm({ ...form, currency: e.target.value })}
                        error={errors.currency}
                    >
                        {balances.map((b) => (
                            <option key={b.currency} value={b.currency}>
                                {b.currency}
                            </option>
                        ))}
                    </Select>
                )}
                <Input
                    label={`Amount (${form.currency})`}
                    inputMode="decimal"
                    value={form.amount}
                    onChange={(e) => setForm({ ...form, amount: e.target.value })}
                    error={errors.amount}
                    hint={
                        balance
                            ? `Available now: ${formatDuesMoney(balance.available, balance.currency)}`
                            : undefined
                    }
                    required
                />
                <Button type="submit" variant="primary" disabled={processing}>
                    Request payout
                </Button>
            </form>
        </Modal>
    );
}

function AddAccountModal({ onClose, onDone }) {
    const showToast = useToast();
    const [form, setForm] = useState({
        type: 'bank',
        label: '',
        account_name: '',
        account_number: '',
        bank_code: '',
    });
    const [banks, setBanks] = useState([]);
    const [errors, setErrors] = useState({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        csrfFetch(route('tenant.payout-accounts.banks'))
            .then((r) => (r.ok ? r.json() : []))
            .then((list) => setBanks(Array.isArray(list) ? list : []))
            .catch(() => setBanks([]));
    }, []);

    const submit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        const result = await sendJson('POST', route('tenant.payout-accounts.store'), form);
        setProcessing(false);

        if (!result.ok) {
            setErrors(result.errors);
            if (Object.keys(result.errors).length === 0) {
                showToast?.(result.message);
            }
            return;
        }

        showToast?.('Payout account added.');
        onDone();
    };

    const set = (field) => (e) => setForm({ ...form, [field]: e.target.value });

    return (
        <Modal open onClose={onClose} title="Add payout account">
            <form onSubmit={submit} className="space-y-4">
                <Select label="Type" value={form.type} onChange={set('type')} error={errors.type}>
                    <option value="bank">Bank account</option>
                    <option value="mobile_money">Mobile money</option>
                </Select>
                <Select
                    label={form.type === 'mobile_money' ? 'Network' : 'Bank'}
                    value={form.bank_code}
                    onChange={set('bank_code')}
                    error={errors.bank_code}
                    required
                >
                    <option value="">Select…</option>
                    {banks.map((b) => (
                        <option key={b.code} value={b.code}>
                            {b.name}
                        </option>
                    ))}
                </Select>
                <Input
                    label="Label"
                    placeholder="e.g. Union current account"
                    value={form.label}
                    onChange={set('label')}
                    error={errors.label}
                    required
                />
                <Input
                    label="Account name"
                    value={form.account_name}
                    onChange={set('account_name')}
                    error={errors.account_name}
                    required
                />
                <Input
                    label="Account number"
                    value={form.account_number}
                    onChange={set('account_number')}
                    error={errors.account_number}
                    hint="We check this account with the bank before saving it."
                    required
                />
                <Button type="submit" variant="primary" disabled={processing}>
                    Add account
                </Button>
            </form>
        </Modal>
    );
}

function OtpModal({ payout, onClose, onDone }) {
    const showToast = useToast();
    const [otp, setOtp] = useState('');
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);

    const submit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        const result = await sendJson(
            'POST',
            route('tenant.dues.payouts.finalize', { payout: payout.id }),
            { otp }
        );
        setProcessing(false);

        if (!result.ok) {
            setError(result.errors.otp ?? result.message);
            return;
        }

        showToast?.(result.body.message ?? 'Payout released');
        onDone();
    };

    return (
        <Modal open onClose={onClose} title="Enter the one-time code">
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-ink-secondary">
                    Paystack sent a one-time code to the account owner to release{' '}
                    {formatDuesMoney(payout.amount, payout.currency)}.
                </p>
                <Input
                    label="One-time code"
                    value={otp}
                    onChange={(e) => setOtp(e.target.value)}
                    error={error}
                    autoComplete="one-time-code"
                    required
                />
                <Button type="submit" variant="primary" disabled={processing || otp === ''}>
                    Release payout
                </Button>
            </form>
        </Modal>
    );
}

export default function Payouts({
    balances,
    accounts,
    payouts,
    canManagePayouts = false,
    canManagePaymentSettings = false,
}) {
    const showToast = useToast();
    const [modal, setModal] = useState(null);
    const [sending, setSending] = useState(null);

    const refresh = () =>
        router.reload({ only: ['balances', 'payouts', 'accounts'], preserveScroll: true });

    const send = async (payout) => {
        setSending(payout.id);
        const result = await sendJson(
            'POST',
            route('tenant.dues.payouts.send', { payout: payout.id })
        );
        setSending(null);

        if (!result.ok) {
            showToast?.(result.message);
            refresh();
            return;
        }

        showToast?.(result.body.message);
        if (result.body.status === 'awaiting_otp') {
            setModal({ kind: 'otp', payout });
        }
        refresh();
    };

    const canRequest = balances.length > 0 && accounts.length > 0;

    return (
        <ConsoleLayout>
            <PageHeader
                title="Dues payouts"
                actions={
                    canManagePayouts && (
                        <Button
                            icon={Plus}
                            variant="primary"
                            disabled={!canRequest}
                            onClick={() => setModal({ kind: 'request' })}
                        >
                            Request payout
                        </Button>
                    )
                }
            />

            <div className="space-y-8 px-8 py-6">
                {balances.length === 0 ? (
                    <p className="text-sm text-ink-secondary">
                        No dues have been collected yet. Collected dues appear here, ready to pay
                        out.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {balances.map((balance) => (
                            <div key={balance.currency} className="border border-border p-5">
                                <div className="text-[13px] text-ink-secondary">
                                    Available to pay out
                                </div>
                                <div className="mt-1 text-2xl font-bold text-ink">
                                    {formatDuesMoney(balance.available, balance.currency)}
                                </div>
                                <div className="mt-3 text-xs text-ink-secondary">
                                    Collected {formatDuesMoney(balance.payable, balance.currency)} ·
                                    In payouts{' '}
                                    {formatDuesMoney(balance.in_flight, balance.currency)}
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {accounts.length === 0 && (
                    <div className="border border-border p-5">
                        <div className="text-sm font-medium text-ink">No payout account yet</div>
                        <p className="mt-1 text-sm text-ink-secondary">
                            Add the bank or mobile money account collected dues are paid into.
                            {!canManagePaymentSettings &&
                                ' Ask someone who manages payment settings to add one.'}
                        </p>
                        {canManagePaymentSettings && (
                            <Button
                                className="mt-3"
                                icon={Plus}
                                variant="primary"
                                onClick={() => setModal({ kind: 'account' })}
                            >
                                Add payout account
                            </Button>
                        )}
                    </div>
                )}

                <Table>
                    <Thead>
                        <Th>Requested</Th>
                        <Th>Amount</Th>
                        <Th>Account</Th>
                        <Th>Status</Th>
                        <Th>
                            <span className="sr-only">Actions</span>
                        </Th>
                    </Thead>
                    <tbody>
                        {payouts.map((payout) => (
                            <Tr key={payout.id}>
                                <Td muted numeric>
                                    {payout.created_at
                                        ? new Date(payout.created_at).toLocaleDateString()
                                        : '—'}
                                </Td>
                                <Td numeric>{formatDuesMoney(payout.amount, payout.currency)}</Td>
                                <Td muted>
                                    {payout.payout_account_label ?? '—'}
                                    {payout.payout_account_masked_number &&
                                        ` · ${payout.payout_account_masked_number}`}
                                </Td>
                                <Td>
                                    <StatusPill status={PAYOUT_PILL[payout.status]}>
                                        {PAYOUT_LABEL[payout.status] ?? payout.status}
                                    </StatusPill>
                                    {payout.status === 'failed' && payout.failure_reason && (
                                        <div className="mt-1 text-xs text-danger-fg">
                                            {payout.failure_reason}
                                        </div>
                                    )}
                                </Td>
                                <Td>
                                    <div className="flex justify-end gap-2">
                                        {canManagePayouts &&
                                            ['scheduled', 'failed'].includes(payout.status) && (
                                                <Button
                                                    disabled={sending === payout.id}
                                                    onClick={() => send(payout)}
                                                >
                                                    {payout.status === 'failed'
                                                        ? 'Send again'
                                                        : 'Send'}
                                                </Button>
                                            )}
                                        {canManagePayouts && payout.status === 'awaiting_otp' && (
                                            <Button
                                                onClick={() => setModal({ kind: 'otp', payout })}
                                            >
                                                Enter code
                                            </Button>
                                        )}
                                    </div>
                                </Td>
                            </Tr>
                        ))}
                        {payouts.length === 0 && (
                            <tr>
                                <td colSpan={5}>
                                    <TableEmpty title="No payouts yet" />
                                </td>
                            </tr>
                        )}
                    </tbody>
                </Table>
            </div>

            {modal?.kind === 'request' && (
                <RequestPayoutModal
                    balances={balances}
                    accounts={accounts}
                    onClose={() => setModal(null)}
                    onAddAccount={
                        canManagePaymentSettings ? () => setModal({ kind: 'account' }) : undefined
                    }
                    onDone={() => {
                        setModal(null);
                        refresh();
                    }}
                />
            )}

            {modal?.kind === 'account' && (
                <AddAccountModal
                    onClose={() => setModal(null)}
                    onDone={() => {
                        setModal(null);
                        refresh();
                    }}
                />
            )}

            {modal?.kind === 'otp' && (
                <OtpModal
                    payout={modal.payout}
                    onClose={() => setModal(null)}
                    onDone={() => {
                        setModal(null);
                        refresh();
                    }}
                />
            )}
        </ConsoleLayout>
    );
}
