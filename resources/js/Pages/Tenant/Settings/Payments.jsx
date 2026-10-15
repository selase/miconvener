import { router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Checkbox from '@/Components/Console/Checkbox';
import StatusPill from '@/Components/Console/StatusPill';
import { useToast } from '@/Components/Console/Toast';
import { Copy, Link2, Unlink } from 'lucide-react';

const SETTLEMENT_OPTIONS = [
    {
        value: 'platform_default',
        title: 'Platform default',
        description:
            'We collect payments on your behalf and pay you out to your bank or mobile-money account. No Paystack or Stripe account needed.',
    },
    {
        value: 'own_gateway',
        title: 'Own gateway',
        description:
            "Payments go straight into your own Paystack or Stripe account, configured below. MiConvener's commission is taken at the time of payment.",
    },
];

function Card({ title, description, aside, children }) {
    return (
        <section className="min-w-0 rounded-lg border border-border p-5 sm:p-6">
            <div className="mb-5 flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-base font-semibold text-ink">{title}</h2>
                    {description && (
                        <p className="mt-1 text-sm text-ink-secondary">{description}</p>
                    )}
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

/**
 * A webhook URL only accepts the provider's POSTs, so unlike CopyField it is
 * offered for copying, never as a link to open.
 */
function WebhookUrl({ url, hint }) {
    const showToast = useToast();

    const copy = async () => {
        await navigator.clipboard.writeText(url);
        showToast?.('Copied to clipboard');
    };

    return (
        <div>
            <div className="mb-1.5 text-sm font-medium text-ink">Webhook URL</div>
            <div className="flex items-center gap-2">
                <code className="num min-w-0 flex-1 truncate rounded-lg border border-border bg-surface-sunken px-3 py-2 text-xs text-ink">
                    {url}
                </code>
                <Button type="button" icon={Copy} onClick={copy} aria-label="Copy webhook URL">
                    Copy
                </Button>
            </div>
            <p className="mt-1 text-xs text-ink-secondary">{hint}</p>
        </div>
    );
}

function SettlementModeForm({
    settlementMode,
    platformSettlementAvailable,
    ticketPaymentsAvailable,
}) {
    const { data, setData, post, processing } = useForm({ settlement_mode: settlementMode });

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.settings.payments.settlement-mode'), { preserveScroll: true });
    };

    return (
        <Card
            title="Settlement mode"
            description="How money for this organization reaches your bank account."
        >
            <form onSubmit={submit} className="space-y-3">
                {SETTLEMENT_OPTIONS.map((option) => {
                    const unavailable =
                        option.value === 'platform_default' && !platformSettlementAvailable;
                    const selected = data.settlement_mode === option.value;

                    return (
                        <label
                            key={option.value}
                            className={`flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors duration-120 ${
                                selected
                                    ? 'border-accent bg-accent-soft'
                                    : 'border-border hover:bg-surface-hover'
                            } ${unavailable ? 'cursor-not-allowed opacity-60' : ''}`}
                        >
                            <input
                                type="radio"
                                name="settlement_mode"
                                value={option.value}
                                checked={selected}
                                disabled={unavailable}
                                onChange={() => setData('settlement_mode', option.value)}
                                className="mt-0.5 border-border text-accent focus:ring-accent"
                            />
                            <span>
                                <span className="block text-sm font-medium text-ink">
                                    {option.title}
                                </span>
                                <span className="mt-0.5 block text-sm text-ink-secondary">
                                    {unavailable
                                        ? 'Not available yet: the platform has not finished setting up payouts.'
                                        : ticketPaymentsAvailable
                                          ? option.description
                                          : option.description.replaceAll(
                                                'Paystack or Stripe',
                                                'Paystack'
                                            )}
                                </span>
                            </span>
                        </label>
                    );
                })}
                <div className="flex justify-end pt-2">
                    <Button type="submit" variant="primary" disabled={processing}>
                        Save settlement mode
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function PlatformFeeForm({ platformFee }) {
    const { data, setData, post, processing, errors } = useForm({
        platform_fee_percentage: platformFee.percentage ?? '',
        platform_fee_cap: platformFee.cap ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.settings.payments.platform-fee'), { preserveScroll: true });
    };

    return (
        <Card
            title="Platform fee"
            description="The commission taken on this organization's ticket sales."
            aside={<StatusPill status="pending">Superadmin only</StatusPill>}
        >
            <form onSubmit={submit} className="space-y-5">
                <div className="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Input
                            label="Fee percentage"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            name="platform_fee_percentage"
                            value={data.platform_fee_percentage}
                            onChange={(event) =>
                                setData('platform_fee_percentage', event.target.value)
                            }
                            error={errors.platform_fee_percentage}
                        />
                        <p className="mt-1 text-xs text-ink-secondary">
                            Set 0 to waive the commission.
                        </p>
                    </div>
                    <div>
                        <Input
                            label="Cap per ticket (GHS)"
                            type="number"
                            step="0.01"
                            min="0.01"
                            name="platform_fee_cap"
                            value={data.platform_fee_cap}
                            onChange={(event) => setData('platform_fee_cap', event.target.value)}
                            error={errors.platform_fee_cap}
                        />
                        <p className="mt-1 text-xs text-ink-secondary">
                            Leave empty to use the package default.
                        </p>
                    </div>
                </div>
                <div className="flex justify-end">
                    <Button type="submit" variant="primary" disabled={processing}>
                        Save fee
                    </Button>
                </div>
            </form>
        </Card>
    );
}

function CommissionSplit({ gateway, destinations, data, setData, errors }) {
    const verifying = useForm({});
    const hasCode = Boolean(gateway.commission_subaccount_code);

    const verify = () =>
        verifying.post(route('tenant.settings.payments.verify-split'), { preserveScroll: true });

    return (
        <div className="space-y-4 border-t border-border pt-5">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h3 className="text-sm font-semibold text-ink">Commission split</h3>
                    <p className="mt-1 text-sm text-ink-secondary">
                        MiConvener&apos;s commission is routed to us when a ticket is paid. Create a
                        subaccount in your Paystack dashboard (Settings › Subaccounts) with the
                        details below, then enter its code.
                    </p>
                </div>
                {hasCode &&
                    (gateway.commission_verified ? (
                        <StatusPill status="success">Verified</StatusPill>
                    ) : (
                        <StatusPill status="failed">Needs attention</StatusPill>
                    ))}
            </div>

            {destinations.map((destination) => (
                <dl
                    key={`${destination.currency}-${destination.account_number}`}
                    className="grid gap-x-6 gap-y-1 rounded-lg border border-border bg-surface-sunken p-3 text-sm sm:grid-cols-[auto_1fr]"
                >
                    <dt className="text-ink-secondary">Currency</dt>
                    <dd className="font-medium text-ink">{destination.currency}</dd>
                    <dt className="text-ink-secondary">Business name</dt>
                    <dd className="font-medium text-ink">{destination.business_name}</dd>
                    {destination.settlement_bank && (
                        <>
                            <dt className="text-ink-secondary">Settlement bank</dt>
                            <dd className="font-medium text-ink">{destination.settlement_bank}</dd>
                        </>
                    )}
                    <dt className="text-ink-secondary">Account number</dt>
                    <dd className="num font-medium text-ink">{destination.account_number}</dd>
                </dl>
            ))}
            {destinations.length > 1 && (
                <p className="text-xs text-ink-secondary">
                    Use the account that matches the currency your events are priced in.
                </p>
            )}

            <div>
                <Input
                    label="Subaccount code"
                    type="text"
                    autoComplete="off"
                    value={data.commission_subaccount_code}
                    placeholder="ACCT_xxxxxxxxxxxx"
                    onChange={(event) =>
                        setData('commission_subaccount_code', event.target.value.trim())
                    }
                    error={errors.commission_subaccount_code}
                />
                {!errors.commission_subaccount_code && gateway.commission_problem && (
                    <p className="mt-1 text-xs text-danger-fg">{gateway.commission_problem}</p>
                )}
                <p className="mt-1 text-xs text-ink-secondary">
                    We check it with Paystack when you save, before every payment, and nightly.
                    Online Paystack payments stay off until it passes.
                </p>
            </div>

            {hasCode && (
                <div>
                    <Button type="button" onClick={verify} disabled={verifying.processing}>
                        Check again
                    </Button>
                </div>
            )}
        </div>
    );
}

function PaystackForm({ gateway, commissionSplit, commissionCredit }) {
    const { data, setData, post, processing, errors } = useForm({
        provider: 'paystack',
        api_key: '',
        public_key: gateway.public_key ?? '',
        commission_subaccount_code: gateway.commission_subaccount_code ?? '',
        is_active: gateway.connected ? gateway.is_active : true,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.settings.payments.update'), {
            preserveScroll: true,
            onSuccess: () => setData((current) => ({ ...current, api_key: '' })),
        });
    };

    return (
        <Card
            title="Paystack"
            aside={
                gateway.connected ? (
                    <StatusPill status={gateway.is_active ? 'success' : 'neutral'}>
                        {gateway.is_active ? 'Active' : 'Inactive'}
                    </StatusPill>
                ) : (
                    <StatusPill>Not connected</StatusPill>
                )
            }
        >
            <form onSubmit={submit} className="space-y-5">
                <Input
                    label="Secret key"
                    type="password"
                    autoComplete="off"
                    value={data.api_key}
                    placeholder={gateway.connected ? 'Saved. Leave blank to keep it.' : 'sk_live_…'}
                    onChange={(event) => setData('api_key', event.target.value)}
                    error={errors.api_key}
                />
                <div>
                    <Input
                        label="Public key"
                        type="text"
                        value={data.public_key}
                        placeholder="pk_live_…"
                        onChange={(event) => setData('public_key', event.target.value)}
                        error={errors.public_key}
                    />
                    <p className="mt-1 text-xs text-ink-secondary">
                        Used for inline or popup checkout.
                    </p>
                </div>
                <Checkbox
                    label="Accept payments through Paystack"
                    checked={data.is_active}
                    onChange={(event) => setData('is_active', event.target.checked)}
                />

                {commissionCredit.length > 0 && (
                    <p className="rounded-lg border border-border bg-surface-sunken p-3 text-sm text-ink-secondary">
                        Commission credit:{' '}
                        <span className="num font-medium text-ink">
                            {commissionCredit
                                .map(
                                    (credit) =>
                                        `${credit.currency} ${(credit.amount / 100).toFixed(2)}`
                                )
                                .join(', ')}
                        </span>
                        . MiConvener returned commission on refunded tickets as credit, and it is
                        taken off the commission on your next sales automatically.
                    </p>
                )}

                {commissionSplit.enforced && (
                    <CommissionSplit
                        gateway={gateway}
                        destinations={commissionSplit.destinations}
                        data={data}
                        setData={setData}
                        errors={errors}
                    />
                )}

                <div className="border-t border-border pt-5">
                    <WebhookUrl
                        url={gateway.webhook_url}
                        hint="Add this URL in your Paystack Dashboard under Settings › API Keys & Webhooks."
                    />
                </div>

                <div className="flex justify-end">
                    <Button type="submit" variant="primary" disabled={processing}>
                        Save Paystack
                    </Button>
                </div>
            </form>
        </Card>
    );
}

const STRIPE_NOTICES = {
    connected: { tone: 'success', text: 'Stripe connected.' },
    declined: { tone: 'neutral', text: 'The Stripe connection was cancelled.' },
    failed: { tone: 'danger', text: 'Stripe could not be connected. Please try again.' },
};

function StripeCard({ stripe }) {
    const notice = STRIPE_NOTICES[stripe.status];

    const disconnect = () => {
        if (
            window.confirm(
                'Disconnect Stripe? Attendees will no longer be able to pay by Stripe for your events.'
            )
        ) {
            router.delete(route('tenant.settings.payments.stripe.disconnect'), {
                preserveScroll: true,
            });
        }
    };

    return (
        <Card
            title="Stripe"
            description="Take card payments, including in USD from attendees abroad, in your own Stripe account."
            aside={
                stripe.connected ? (
                    <StatusPill status="success">Connected</StatusPill>
                ) : (
                    <StatusPill>Not connected</StatusPill>
                )
            }
        >
            <div className="space-y-5">
                {notice && <p className="text-sm text-ink-secondary">{notice.text}</p>}

                {stripe.connected ? (
                    <>
                        <p className="text-sm text-ink-secondary">
                            Linked to account{' '}
                            <code className="num text-ink">{stripe.account_id}</code>. Payments go
                            straight to that account; MiConvener&apos;s commission is taken as an
                            application fee on each ticket.
                        </p>
                        <div className="flex justify-end">
                            <Button type="button" icon={Unlink} onClick={disconnect}>
                                Disconnect Stripe
                            </Button>
                        </div>
                    </>
                ) : stripe.configured ? (
                    <>
                        <p className="text-sm text-ink-secondary">
                            You will sign in to Stripe and approve the connection. We never see or
                            store your Stripe keys, and you can disconnect at any time from here or
                            from your Stripe dashboard.
                        </p>
                        <div className="flex justify-end">
                            <Button
                                variant="primary"
                                icon={Link2}
                                href={route('tenant.settings.payments.stripe.connect')}
                            >
                                Connect with Stripe
                            </Button>
                        </div>
                    </>
                ) : (
                    <p className="text-sm text-ink-secondary">
                        Not available yet: the platform has not finished setting up Stripe.
                    </p>
                )}
            </div>
        </Card>
    );
}

export default function Payments({
    settlementMode,
    platformSettlementAvailable,
    ticketPaymentsAvailable = true,
    gateways,
    stripe,
    commissionSplit,
    commissionCredit,
    canSetPlatformFee,
    platformFee,
}) {
    return (
        <ConsoleLayout>
            <PageHeader title="Payments and payouts" />

            <div className="max-w-4xl space-y-6 px-4 py-6 sm:px-8">
                <SettlementModeForm
                    settlementMode={settlementMode}
                    platformSettlementAvailable={platformSettlementAvailable}
                    ticketPaymentsAvailable={ticketPaymentsAvailable}
                />

                {canSetPlatformFee && <PlatformFeeForm platformFee={platformFee} />}

                <div>
                    <h2 className="text-sm font-semibold text-ink-secondary">Your own gateway</h2>
                    <p className="mt-1 text-sm text-ink-secondary">
                        {settlementMode === 'own_gateway'
                            ? ticketPaymentsAvailable
                                ? 'Ticket payments go to the provider you mark active here. Dues use Paystack only.'
                                : 'Dues payments use your active Paystack account. Stripe is supported for event tickets only.'
                            : 'Only used when the settlement mode is Own gateway.'}
                    </p>
                </div>
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <PaystackForm
                        gateway={gateways.paystack}
                        commissionSplit={commissionSplit}
                        commissionCredit={commissionCredit}
                    />
                    {ticketPaymentsAvailable && <StripeCard stripe={stripe} />}
                </div>
            </div>
        </ConsoleLayout>
    );
}
