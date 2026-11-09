import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { sendJson, toMinorUnits } from '@/lib/dues';

const money = (value) =>
    new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(
        (value ?? 0) / 100
    );
const weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const amountFields = {
    minimum_amount: 'Minimum payout (GHS)',
    maximum_amount: 'Maximum per payout (GHS)',
    daily_limit: 'Daily limit (GHS)',
    rolling_limit: 'Rolling 24-hour limit (GHS)',
    reserve_amount: 'Minimum reserve (GHS)',
};

export default function Schedules({
    policies,
    proposals,
    accounts,
    pagination,
    canManage,
    canPay,
    securityReady,
    platformReady,
}) {
    const [form, setForm] = useState({
        category: 'dues',
        payout_account_id: '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Accra',
        cadence: 'weekly',
        weekday: 1,
        local_time: '09:00',
        minimum_amount: '',
        maximum_amount: '',
        daily_limit: '',
        rolling_limit: '',
        reserve_amount: '',
    });
    const [busy, setBusy] = useState(false);
    const [reauthRequired, setReauthRequired] = useState(false);
    const [message, setMessage] = useState('');
    const [errors, setErrors] = useState({});
    const [otp, setOtp] = useState({});
    const [now, setNow] = useState(Date.now());
    const keys = useRef({});
    useEffect(() => {
        const refresh = () => router.reload({ only: ['policies', 'proposals', 'platformReady'] });
        const timer = setInterval(() => setNow(Date.now()), 1000);
        window.addEventListener('focus', refresh);
        return () => {
            clearInterval(timer);
            window.removeEventListener('focus', refresh);
        };
    }, []);
    const change = (key) => (event) => setForm((old) => ({ ...old, [key]: event.target.value }));
    const perform = async (url, payload = {}) => {
        if (busy) return;
        setBusy(true);
        setErrors({});
        setMessage('');
        try {
            const result = await sendJson('POST', url, payload);
            if (!result.ok) {
                setReauthRequired(result.status === 423);
                setErrors(result.errors ?? {});
                setMessage(
                    result.message || 'The request could not be completed. Refresh and try again.'
                );
                return;
            }
            setMessage(
                result.body?.message || 'Saved. No transfer is complete until verified by Paystack.'
            );
            router.reload({ only: ['policies', 'proposals', 'platformReady'] });
        } catch {
            setMessage(
                'Connection interrupted. Refresh to check the original request before trying again.'
            );
        } finally {
            setBusy(false);
        }
    };
    const request = (event) => {
        event.preventDefault();
        const amounts = Object.fromEntries(
            Object.keys(amountFields).map((key) => [key, toMinorUnits(form[key])])
        );
        if (
            Object.values(amounts).some((value) => value === null || !Number.isSafeInteger(value))
        ) {
            setMessage('Enter each amount explicitly, including zero for an agreed zero reserve.');
            return;
        }
        perform(route('tenant.finance.schedules.store'), { ...form, ...amounts, currency: 'GHS' });
    };
    const action = (proposal, verb) => {
        const payload = {};
        if (verb === 'approve') {
            keys.current[proposal.id] ??= crypto.randomUUID();
            payload.operation_key = keys.current[proposal.id];
        }
        if (verb === 'finalize') {
            payload.otp = otp[proposal.id] || '';
            setOtp((old) => ({ ...old, [proposal.id]: '' }));
        }
        perform(route(`tenant.finance.schedules.${verb}`, { proposal: proposal.id }), payload);
    };
    return (
        <ConsoleLayout>
            <PageHeader title="Payout schedules" />
            <main className="space-y-6 p-4 md:p-8">
                <div className="border border-border bg-surface-subtle p-5 text-sm text-ink-secondary">
                    The schedule prepares a payout for review. No transfer is sent until an
                    authorised person submits it.
                    <p className="mt-2">
                        Paystack’s account-owner code remains required. Existing manual payouts
                        remain available.
                    </p>
                    {!platformReady && (
                        <p className="mt-2 font-medium text-ink">
                            Scheduled submission awaits platform approval of liquidity controls and
                            limits.
                        </p>
                    )}
                    {!securityReady && (
                        <p className="mt-2">
                            <Link className="text-accent underline" href={route('tenant.account')}>
                                Enable two-factor authentication
                            </Link>{' '}
                            before requesting or approving schedules.
                        </p>
                    )}
                </div>
                {reauthRequired && (
                    <p className="text-sm text-accent underline">
                        <a href={route('password.confirm')}>
                            Confirm your password, then return to review this payout.
                        </a>
                    </p>
                )}
                {message && (
                    <p
                        role="status"
                        className="break-words border border-border p-4 text-sm text-ink"
                    >
                        {message}
                    </p>
                )}
                {Object.keys(errors).length > 0 && (
                    <div role="alert" className="text-danger-fg">
                        {Object.entries(errors).map(([key, value]) => (
                            <p key={key}>{Array.isArray(value) ? value[0] : value}</p>
                        ))}
                    </div>
                )}
                <section aria-labelledby="policy-title" className="space-y-4">
                    <h2 id="policy-title" className="text-lg font-semibold text-ink">
                        Approved policies and requests
                    </h2>
                    {!policies.length && (
                        <p className="text-sm text-ink-secondary">
                            Manual payouts are the default. Request a schedule below for platform
                            review.
                        </p>
                    )}
                    {policies.map((p) => (
                        <article
                            key={p.id}
                            className="space-y-2 border border-border p-5 text-sm text-ink"
                        >
                            <h3 className="font-semibold capitalize">
                                {p.category} · version {p.version} · {p.status}
                            </h3>
                            <p>
                                {p.cadence === 'weekly' ? weekdays[p.weekday] : 'Daily'} at{' '}
                                {p.local_time} · {p.timezone} · {p.destination}
                            </p>
                            <p>
                                Minimum {money(p.minimum_amount)} · maximum{' '}
                                {money(p.maximum_amount)} · reserve {money(p.reserve_amount)}
                            </p>
                            <p>
                                Daily limit {money(p.daily_limit)} · rolling limit{' '}
                                {money(p.rolling_limit)}
                            </p>
                            {p.active_after && (
                                <p>
                                    Eligible after {new Date(p.active_after).toLocaleString()}{' '}
                                    subject to reconciliation and available funds.
                                </p>
                            )}
                            {canManage && p.status !== 'paused' && (
                                <Button
                                    disabled={busy}
                                    onClick={() =>
                                        perform(
                                            route('tenant.finance.schedules.pause', {
                                                policy: p.id,
                                            })
                                        )
                                    }
                                >
                                    Pause schedule
                                </Button>
                            )}
                        </article>
                    ))}
                </section>
                <section aria-labelledby="proposal-title" className="space-y-4">
                    <h2 id="proposal-title" className="text-lg font-semibold text-ink">
                        Payouts awaiting review
                    </h2>
                    {!proposals.length && (
                        <p className="border border-border p-5 text-sm text-ink-secondary">
                            No payout proposals yet. An approved schedule prepares proposals only
                            when reconciliation and funds are ready.
                        </p>
                    )}
                    {proposals.map((p) => {
                        const policy = policies.find((item) => item.id === p.policy_id);
                        const mayPay =
                            canManage &&
                            securityReady &&
                            (policy?.category === 'commerce' ? canPay.commerce : canPay.other);
                        const expired = new Date(p.expires_at).getTime() <= now;
                        const approvalExpired =
                            p.submit_before && new Date(p.submit_before).getTime() <= now;
                        return (
                            <article
                                key={p.id}
                                className="space-y-3 border border-border p-5 text-sm text-ink"
                            >
                                <h3 className="font-semibold">
                                    {money(p.amount)} ·{' '}
                                    {p.scope_label || policy?.category || 'Payout'} ·{' '}
                                    {p.payout_status || (expired ? 'Expired' : p.status)}
                                </h3>
                                <p>
                                    Entitled {money(p.snapshot.entitlement)} · reserved or withheld{' '}
                                    {money(p.snapshot.reserve)} · available{' '}
                                    {money(p.snapshot.available)}
                                </p>
                                <p>
                                    Transfer fee {money(p.snapshot.fee)} · recipient receives{' '}
                                    {money(p.snapshot.net)}
                                </p>
                                <p>Proposal expires {new Date(p.expires_at).toLocaleString()}</p>
                                {p.submit_before && (
                                    <p>
                                        Submission approval expires{' '}
                                        {new Date(p.submit_before).toLocaleString()}
                                    </p>
                                )}
                                {(p.reason || p.failure_reason) && (
                                    <p role="status">{p.reason || p.failure_reason}</p>
                                )}
                                {mayPay && (
                                    <div className="flex flex-wrap gap-3">
                                        {p.status === 'proposed' && !expired && (
                                            <Button
                                                disabled={busy}
                                                onClick={() => action(p, 'approve')}
                                            >
                                                Approve payout
                                            </Button>
                                        )}
                                        {p.status === 'approved' && !approvalExpired && (
                                            <Button
                                                variant="primary"
                                                disabled={busy || !platformReady}
                                                onClick={() => action(p, 'submit')}
                                            >
                                                Submit payout
                                            </Button>
                                        )}
                                        {['proposed', 'approved'].includes(p.status) &&
                                            !expired && (
                                                <Button
                                                    disabled={busy}
                                                    onClick={() => action(p, 'refresh')}
                                                >
                                                    Refresh proposal
                                                </Button>
                                            )}
                                        {['proposed', 'approved'].includes(p.status) && (
                                            <Button
                                                disabled={busy}
                                                onClick={() => action(p, 'cancel')}
                                            >
                                                Cancel unsent payout
                                            </Button>
                                        )}
                                        {p.payout_status === 'awaiting_otp' && (
                                            <form
                                                className="flex flex-wrap items-end gap-3"
                                                onSubmit={(event) => {
                                                    event.preventDefault();
                                                    action(p, 'finalize');
                                                }}
                                            >
                                                <Input
                                                    label="Paystack owner code"
                                                    type="password"
                                                    autoComplete="off"
                                                    value={otp[p.id] || ''}
                                                    onChange={(event) =>
                                                        setOtp((old) => ({
                                                            ...old,
                                                            [p.id]: event.target.value,
                                                        }))
                                                    }
                                                    required
                                                />
                                                <Button type="submit" disabled={busy}>
                                                    Release with code
                                                </Button>
                                            </form>
                                        )}
                                    </div>
                                )}
                            </article>
                        );
                    })}
                    <div className="flex gap-4 text-sm text-accent">
                        {pagination.previous && (
                            <Link href={pagination.previous}>Previous proposals</Link>
                        )}
                        {pagination.next && <Link href={pagination.next}>Next proposals</Link>}
                    </div>
                </section>
                {canManage && (
                    <section className="border border-border p-5" aria-labelledby="request-title">
                        <h2 id="request-title" className="mb-4 text-lg font-semibold text-ink">
                            Request a schedule
                        </h2>
                        <form onSubmit={request} className="grid gap-4 md:grid-cols-2">
                            <Select
                                label="Money stream"
                                value={form.category}
                                onChange={change('category')}
                            >
                                {[
                                    ['event', 'Event tickets'],
                                    ['dues', 'Member dues'],
                                    ['giving', 'Giving'],
                                    ['commerce', 'Commerce (goods and eligible venue earnings)'],
                                ].map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                label="Verified destination"
                                value={form.payout_account_id}
                                onChange={change('payout_account_id')}
                                required
                            >
                                <option value="">Choose a destination</option>
                                {accounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                label="Frequency"
                                value={form.cadence}
                                onChange={change('cadence')}
                            >
                                <option value="weekly">Weekly</option>
                                <option value="daily">Daily (requires platform approval)</option>
                            </Select>
                            {form.cadence === 'weekly' && (
                                <Select
                                    label="Day"
                                    value={form.weekday}
                                    onChange={change('weekday')}
                                >
                                    {weekdays.map((day, index) => (
                                        <option value={index} key={day}>
                                            {day}
                                        </option>
                                    ))}
                                </Select>
                            )}
                            <Input
                                label="Local time"
                                type="time"
                                value={form.local_time}
                                onChange={change('local_time')}
                                required
                            />
                            <Input
                                label="Timezone"
                                value={form.timezone}
                                onChange={change('timezone')}
                                required
                            />
                            {Object.entries(amountFields).map(([key, label]) => (
                                <Input
                                    key={key}
                                    label={label}
                                    inputMode="decimal"
                                    value={form[key]}
                                    onChange={change(key)}
                                    required
                                />
                            ))}
                            <div className="md:col-span-2">
                                <Button
                                    type="submit"
                                    disabled={busy || !securityReady || !accounts.length}
                                >
                                    Request schedule approval
                                </Button>
                            </div>
                        </form>
                    </section>
                )}
            </main>
        </ConsoleLayout>
    );
}
