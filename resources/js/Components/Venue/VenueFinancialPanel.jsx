import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { localToOffsetIso, formatInZone } from '@/lib/venueTime';
import { CHARGE_STATES, PURPOSE_LABELS, formatMoney, newOperationKey, pesewasFromInput } from '@/lib/venueMoney';

const field = 'w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none';
const label = 'mb-1 block text-xs font-medium text-ink-secondary';

function Row({ title, value }) {
    return (
        <div className="flex items-baseline justify-between gap-4 text-sm">
            <span className="text-ink-secondary">{title}</span>
            <span className="font-medium text-ink">{value}</span>
        </div>
    );
}

function QuoteForm({ inquiry, timezone }) {
    const key = useRef(newOperationKey());
    const [values, setValues] = useState({
        rental: '', extras: '', security: '', plan: 'full', deposit: '', balanceDue: '', securityDue: '',
        cancellation: '', support: '',
    });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const set = (name) => (event) => setValues((current) => ({ ...current, [name]: event.target.value }));

    const submit = (event) => {
        event.preventDefault();
        const next = {};
        const rental = pesewasFromInput(values.rental);
        const extras = values.extras === '' ? 0 : pesewasFromInput(values.extras);
        const security = values.security === '' ? 0 : pesewasFromInput(values.security);
        const deposit = values.plan === 'deposit' ? pesewasFromInput(values.deposit) : null;
        if (rental === null) next.rental = 'Enter the hire price, for example 1500.00.';
        if (extras === null) next.extras = 'Enter a valid amount.';
        if (security === null) next.security = 'Enter a valid amount.';
        if (values.plan === 'deposit' && (deposit === null || deposit < 1)) next.deposit = 'Enter the deposit amount.';
        const toIso = (local, name, required) => {
            if (!local) {
                if (required) next[name] = 'Choose a date and time.';
                return null;
            }
            const result = localToOffsetIso(local, timezone);
            if (!result.ok) {
                next[name] = 'That time does not exist or is ambiguous in ' + timezone + '. Pick another.';
                return null;
            }
            return result.iso;
        };
        const balanceDue = toIso(values.balanceDue, 'balanceDue', values.plan === 'deposit');
        const securityDue = toIso(values.securityDue, 'securityDue', Boolean(security));
        if (!values.cancellation.trim()) next.cancellation = 'State your actual cancellation and refund terms.';
        if (!values.support.trim()) next.support = 'Give the buyer a way to reach you.';
        setErrors(next);
        if (Object.keys(next).length) return;

        setBusy(true);
        router.post(
            route('tenant.venue.inquiries.financial-quote', { inquiry: inquiry.id }),
            {
                operation_key: key.current,
                rental_pesewas: rental, extras_pesewas: extras, security_pesewas: security,
                payment_plan: values.plan, deposit_pesewas: deposit,
                balance_due_at: balanceDue, security_due_at: securityDue,
                cancellation_terms: values.cancellation.trim(), support_contact: values.support.trim(),
            },
            { preserveScroll: true, onFinish: () => { setBusy(false); key.current = newOperationKey(); } }
        );
    };

    const input = (name, text, props = {}) => (
        <div>
            <label htmlFor={`fq-${name}`} className={label}>{text}</label>
            <input id={`fq-${name}`} value={values[name]} onChange={set(name)} aria-invalid={Boolean(errors[name])} aria-describedby={errors[name] ? `fq-${name}-error` : undefined} className={field} {...props} />
            {errors[name] && <p id={`fq-${name}-error`} className="mt-1 text-xs text-danger-fg">{errors[name]}</p>}
        </div>
    );

    return (
        <form onSubmit={submit} noValidate className="space-y-4">
            <p className="text-xs text-ink-secondary">
                Amounts are in GHS. Times are in {timezone}. Your commission is taken from what you receive; the buyer pays exactly what you enter.
            </p>
            <div className="grid gap-4 sm:grid-cols-3">
                {input('rental', 'Hire price (GHS)', { inputMode: 'decimal' })}
                {input('extras', 'Extras (GHS, optional)', { inputMode: 'decimal' })}
                {input('security', 'Refundable security (GHS, optional)', { inputMode: 'decimal' })}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor="fq-plan" className={label}>How the hire is paid</label>
                    <select id="fq-plan" value={values.plan} onChange={set('plan')} className={field}>
                        <option value="full">In full, at once</option>
                        <option value="deposit">A deposit now, the balance later</option>
                    </select>
                </div>
                {values.plan === 'deposit' && input('deposit', 'Deposit (GHS)', { inputMode: 'decimal' })}
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                {values.plan === 'deposit' && input('balanceDue', 'Balance due by', { type: 'datetime-local' })}
                {input('securityDue', 'Security due by', { type: 'datetime-local' })}
            </div>
            <div>
                <label htmlFor="fq-cancellation" className={label}>Cancellation and refund terms</label>
                <textarea id="fq-cancellation" rows={3} value={values.cancellation} onChange={set('cancellation')} aria-invalid={Boolean(errors.cancellation)} className={field} />
                {errors.cancellation && <p className="mt-1 text-xs text-danger-fg">{errors.cancellation}</p>}
            </div>
            {input('support', 'How the buyer reaches you about this booking')}
            <button type="submit" disabled={busy} className="inline-flex h-control items-center rounded-md border border-accent bg-accent px-4 text-sm font-medium text-accent-ink hover:opacity-90 disabled:opacity-50">
                {busy ? 'Sending…' : 'Send final quote'}
            </button>
        </form>
    );
}

/** The host's final quote, payments and completion for an approval-first booking. */
export default function VenueFinancialPanel({ inquiry, can }) {
    const financial = inquiry.financial;
    const timezone = inquiry.approval?.timezone ?? 'UTC';
    const status = inquiry.approval?.effective_status;
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(null);
    const completeKey = useRef(newOperationKey());
    const withdrawKey = useRef(newOperationKey());

    useEffect(() => {
        const refresh = () => router.reload({ only: ['inquiry'] });
        window.addEventListener('focus', refresh);

        return () => window.removeEventListener('focus', refresh);
    }, []);

    const canQuote = status === 'approved_hold' && (!financial || ['withdrawn', 'expired'].includes(financial.quote.status));
    const quote = financial?.quote;
    const at = (iso) => (iso ? formatInZone(iso, timezone) : '—');
    const act = (name, url, extra = {}) => {
        if (!reason.trim()) return;
        setBusy(name);
        router.post(url, { operation_key: name === 'withdraw' ? withdrawKey.current : completeKey.current, reason: reason.trim(), ...extra }, {
            preserveScroll: true,
            onSuccess: () => setReason(''),
            onFinish: () => setBusy(null),
        });
    };

    if (!financial && status !== 'approved_hold') return null;

    return (
        <section aria-labelledby="host-quote-heading" className="space-y-4 rounded-xl border border-border bg-surface-sunken p-5">
            <h2 id="host-quote-heading" className="text-base font-semibold text-ink">Final quote and payments</h2>

            {quote && (
                <div className="space-y-2">
                    <Row title={`Version ${quote.version}`} value={quote.status} />
                    <Row title="Hire and extras" value={formatMoney(quote.contract_total_pesewas)} />
                    <Row title="Your commission on this" value={formatMoney(quote.commission_pesewas)} />
                    <Row title="Refundable security" value={formatMoney(quote.security_pesewas)} />
                    <Row title="Buyer must accept by" value={at(quote.expires_at)} />
                    {quote.accepted_at && <Row title="Accepted" value={at(quote.accepted_at)} />}
                </div>
            )}

            {financial?.notices?.length > 0 && (
                <p role="status" className="text-xs text-ink-secondary">
                    {(() => {
                        const notice = financial.notices[financial.notices.length - 1];
                        const what = notice.kind === 'withdrawn' ? 'withdrawal notice' : 'quote email';
                        if (notice.state === 'sent') return `The buyer was sent the ${what}.`;
                        if (notice.state === 'failed') return `The ${what} could not be delivered after ${notice.attempts} attempts. Contact the buyer directly.`;
                        return `Sending the ${what} to the buyer… (attempt ${notice.attempts}; it is retried automatically).`;
                    })()}
                </p>
            )}

            {financial?.charges.length > 0 && (
                <ul className="space-y-2" aria-label="Payments">
                    {financial.charges.map((charge) => (
                        <li key={charge.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface p-3 text-sm">
                            <span>{PURPOSE_LABELS[charge.purpose]} · {formatMoney(charge.amount_pesewas)}</span>
                            <span className="text-xs text-ink-secondary">
                                {(CHARGE_STATES[charge.state] ?? CHARGE_STATES.due).label}
                                {charge.refund ? ` · refund ${charge.refund.status.replace('_', ' ')}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {financial?.settlement && financial.settlement.held_pesewas + financial.settlement.released_pesewas > 0 && (
                <div className="space-y-1 border-t border-border pt-3">
                    <Row title="Earnings held until the event is complete" value={formatMoney(financial.settlement.held_pesewas)} />
                    <Row title="Released to your payout balance" value={formatMoney(financial.settlement.released_pesewas)} />
                </div>
            )}

            {canQuote && (
                <>
                    {quote?.status === 'expired' && <p className="text-sm text-ink-secondary">The last quote expired. Send a new one while the dates are held.</p>}
                    <QuoteForm inquiry={inquiry} timezone={timezone} />
                </>
            )}

            {quote?.status === 'open' && (
                <div className="space-y-2 border-t border-border pt-3">
                    <label htmlFor="withdraw-reason" className={label}>Withdraw this quote (the buyer sees your reason)</label>
                    <input id="withdraw-reason" value={reason} onChange={(event) => setReason(event.target.value)} className={field} maxLength={500} />
                    <button type="button" disabled={!reason.trim() || busy === 'withdraw'} onClick={() => act('withdraw', route('tenant.venue.financial-quotes.withdraw', { quote: quote.id }))} className="inline-flex h-control items-center rounded-md border border-border px-4 text-sm text-ink hover:bg-surface-hover disabled:opacity-50">
                        Withdraw quote
                    </button>
                </div>
            )}

            {can?.complete && financial?.settlement?.held_pesewas > 0 && !financial.settlement.completed && (
                <div className="space-y-2 border-t border-border pt-3">
                    <label htmlFor="complete-reason" className={label}>The event has finished and everything went as agreed</label>
                    <input id="complete-reason" value={reason} onChange={(event) => setReason(event.target.value)} className={field} maxLength={500} placeholder="A short note for the record" />
                    <button type="button" disabled={!reason.trim() || busy === 'complete'} onClick={() => act('complete', route('tenant.venue.inquiries.complete', { inquiry: inquiry.id }))} className="inline-flex h-control items-center rounded-md border border-accent bg-accent px-4 text-sm font-medium text-accent-ink hover:opacity-90 disabled:opacity-50">
                        Mark event complete
                    </button>
                </div>
            )}
        </section>
    );
}
