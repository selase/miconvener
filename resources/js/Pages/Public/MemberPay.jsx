import { useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Info, AlertCircle } from 'lucide-react';
import ThemeToggle from '@/Components/Console/ThemeToggle';
import { formatDuesMoney } from '@/lib/dues';

function Notice({ tone, children }) {
    const styles = {
        error: 'bg-danger-bg text-danger-fg',
        info: 'bg-warning-bg text-warning-fg',
        success: 'bg-success-bg text-success-fg',
    };
    const Icon = { error: AlertCircle, info: Info, success: CheckCircle2 }[tone];

    return (
        <div
            role="status"
            className={`flex items-start gap-2 rounded-lg px-4 py-3 text-sm ${styles[tone]}`}
        >
            <Icon className="mt-0.5 h-4 w-4 shrink-0" strokeWidth={1.9} />
            <span>{children}</span>
        </div>
    );
}

function Shell({ organisation, children }) {
    const { flash, errors } = usePage().props;
    // A refused checkout comes back as validation errors keyed by charge
    // (charge_ids.0, charge_ids.1, ...), often with the same message.
    const validationMessages = [...new Set(Object.values(errors ?? {}))];

    return (
        <div className="min-h-screen bg-canvas font-console text-ink">
            <main className="mx-auto w-full max-w-md px-4 py-8 sm:py-12">
                <div className="mb-6 flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        {organisation.logo_url && (
                            <img
                                src={organisation.logo_url}
                                alt=""
                                className="h-10 w-10 shrink-0 rounded-md object-cover"
                            />
                        )}
                        <span className="truncate text-sm font-semibold text-ink-secondary">
                            {organisation.name}
                        </span>
                    </div>
                    <ThemeToggle />
                </div>

                <div className="space-y-3">
                    {flash?.error && <Notice tone="error">{flash.error}</Notice>}
                    {validationMessages.map((message) => (
                        <Notice key={message} tone="error">
                            {message}
                        </Notice>
                    ))}
                    {flash?.info && <Notice tone="info">{flash.info}</Notice>}
                    {flash?.success && <Notice tone="success">{flash.success}</Notice>}
                </div>

                {children}
            </main>
        </div>
    );
}

function PayCharges({ organisation, member, charges, canPayOnline, checkoutUrl }) {
    const [selected, setSelected] = useState(() => new Set(charges.map((charge) => charge.id)));
    const [processing, setProcessing] = useState(false);

    const chosen = charges.filter((charge) => selected.has(charge.id));
    const currency = charges[0]?.currency;
    const total = chosen.reduce((sum, charge) => sum + charge.amount, 0);

    const toggle = (id) => {
        const next = new Set(selected);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        setSelected(next);
    };

    const pay = () => {
        setProcessing(true);
        router.post(
            checkoutUrl,
            { charge_ids: chosen.map((charge) => charge.id) },
            { onFinish: () => setProcessing(false) }
        );
    };

    return (
        <Shell organisation={organisation}>
            <Head title={`Pay dues to ${organisation.name}`} />
            <h1 className="mt-6 text-2xl font-bold tracking-tight text-ink">
                Hello {member.first_name}
            </h1>

            {charges.length === 0 ? (
                <div className="mt-6 rounded-lg border border-border bg-surface p-6 text-center">
                    <CheckCircle2 className="mx-auto h-8 w-8 text-success-fg" strokeWidth={1.75} />
                    <p className="mt-3 text-lg font-semibold text-ink">You are all caught up</p>
                    <p className="mt-1 text-sm text-ink-secondary">
                        You have no dues to pay to {organisation.name} right now.
                    </p>
                </div>
            ) : (
                <>
                    <p className="mt-1 text-sm text-ink-secondary">
                        These dues are open for {organisation.name}. Untick any you are not paying
                        today.
                    </p>

                    <ul className="mt-6 divide-y divide-border rounded-lg border border-border bg-surface">
                        {charges.map((charge) => (
                            <li key={charge.id}>
                                <label className="flex cursor-pointer items-center gap-3 px-4 py-4">
                                    <input
                                        type="checkbox"
                                        checked={selected.has(charge.id)}
                                        onChange={() => toggle(charge.id)}
                                        className="h-5 w-5 rounded border-border text-accent focus:ring-accent"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium text-ink">
                                            {charge.period_label}
                                        </span>
                                        <span className="block text-xs text-ink-secondary">
                                            Due {charge.due_on}
                                        </span>
                                    </span>
                                    {charge.is_overdue && (
                                        <span className="rounded-full bg-danger-bg px-2.5 py-0.5 text-xs font-medium text-danger-fg">
                                            Overdue
                                        </span>
                                    )}
                                    <span className="font-semibold text-ink tabular-nums">
                                        {formatDuesMoney(charge.amount, charge.currency)}
                                    </span>
                                </label>
                            </li>
                        ))}
                    </ul>

                    {canPayOnline ? (
                        <button
                            type="button"
                            onClick={pay}
                            disabled={processing || chosen.length === 0}
                            className="mt-6 h-12 w-full rounded-lg bg-accent text-base font-semibold text-accent-ink transition-opacity hover:opacity-90 disabled:opacity-50"
                        >
                            {chosen.length === 0
                                ? 'Choose a charge to pay'
                                : `Pay ${formatDuesMoney(total, currency)}`}
                        </button>
                    ) : (
                        <p className="mt-6 rounded-lg border border-border bg-surface p-4 text-sm text-ink-secondary">
                            Online payment is not available for {organisation.name} right now.
                            Please contact them to pay.
                        </p>
                    )}
                </>
            )}
        </Shell>
    );
}

function Receipt({ organisation, member, paidCharges, payUrl }) {
    return (
        <Shell organisation={organisation}>
            <Head title="Payment receipt" />
            <h1 className="mt-6 text-2xl font-bold tracking-tight text-ink">
                Thank you, {member.first_name}
            </h1>
            <p className="mt-1 text-sm text-ink-secondary">
                Your payments to {organisation.name}, newest first.
            </p>

            {paidCharges.length === 0 ? (
                <p className="mt-6 text-sm text-ink-secondary">No payments recorded yet.</p>
            ) : (
                <ul className="mt-6 divide-y divide-border rounded-lg border border-border bg-surface">
                    {paidCharges.map((charge) => (
                        <li
                            key={charge.id}
                            className="flex items-center justify-between gap-3 px-4 py-4"
                        >
                            <span>
                                <span className="block font-medium text-ink">
                                    {charge.period_label}
                                </span>
                                {charge.payment_reference && (
                                    <span className="block text-xs text-ink-secondary">
                                        Ref {charge.payment_reference}
                                    </span>
                                )}
                            </span>
                            <span className="font-semibold text-ink tabular-nums">
                                {formatDuesMoney(charge.amount, charge.currency)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            <a
                href={payUrl}
                className="mt-6 inline-block text-sm font-medium text-accent hover:underline"
            >
                Back to your dues
            </a>
        </Shell>
    );
}

export default function MemberPay(props) {
    return props.mode === 'receipt' ? <Receipt {...props} /> : <PayCharges {...props} />;
}
