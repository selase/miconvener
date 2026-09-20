import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Checkbox from '@/Components/Console/Checkbox';
import StatusPill from '@/Components/Console/StatusPill';
import { useToast } from '@/Components/Console/Toast';
import { Copy } from 'lucide-react';

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
            'Payments go straight into your own Stripe or Paystack account, configured below.',
    },
];

const PROVIDERS = {
    stripe: {
        name: 'Stripe',
        secretPlaceholder: 'sk_live_…',
        publicLabel: 'Publishable key',
        publicPlaceholder: 'pk_live_…',
        publicHint: 'Used for client-side checkout.',
        hasWebhookSecret: true,
        webhookHint: 'Add this URL in your Stripe Dashboard under Developers › Webhooks.',
    },
    paystack: {
        name: 'Paystack',
        secretPlaceholder: 'sk_live_…',
        publicLabel: 'Public key',
        publicPlaceholder: 'pk_live_…',
        publicHint: 'Used for inline or popup checkout.',
        hasWebhookSecret: false,
        webhookHint:
            'Add this URL in your Paystack Dashboard under Settings › API Keys & Webhooks.',
    },
};

function Card({ title, description, aside, children }) {
    return (
        <section className="rounded-lg border border-border p-5 sm:p-6">
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

function SettlementModeForm({ settlementMode, platformSettlementAvailable }) {
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
                                        : option.description}
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

function GatewayForm({ provider, gateway }) {
    const config = PROVIDERS[provider];
    const { data, setData, post, processing, errors } = useForm({
        provider,
        api_key: '',
        public_key: gateway.public_key ?? '',
        webhook_secret: '',
        is_active: gateway.connected ? gateway.is_active : true,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.settings.payments.update'), {
            preserveScroll: true,
            onSuccess: () =>
                setData((current) => ({ ...current, api_key: '', webhook_secret: '' })),
        });
    };

    const savedPlaceholder = 'Saved. Leave blank to keep it.';

    return (
        <Card
            title={config.name}
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
                    placeholder={gateway.connected ? savedPlaceholder : config.secretPlaceholder}
                    onChange={(event) => setData('api_key', event.target.value)}
                    error={errors.api_key}
                />
                <div>
                    <Input
                        label={config.publicLabel}
                        type="text"
                        value={data.public_key}
                        placeholder={config.publicPlaceholder}
                        onChange={(event) => setData('public_key', event.target.value)}
                        error={errors.public_key}
                    />
                    <p className="mt-1 text-xs text-ink-secondary">{config.publicHint}</p>
                </div>
                {config.hasWebhookSecret && (
                    <div>
                        <Input
                            label="Webhook signing secret"
                            type="password"
                            autoComplete="off"
                            value={data.webhook_secret}
                            placeholder={gateway.has_webhook_secret ? savedPlaceholder : 'whsec_…'}
                            onChange={(event) => setData('webhook_secret', event.target.value)}
                            error={errors.webhook_secret}
                        />
                        <p className="mt-1 text-xs text-ink-secondary">
                            Verifies that webhook events really come from Stripe.
                        </p>
                    </div>
                )}
                <Checkbox
                    label={`Accept payments through ${config.name}`}
                    checked={data.is_active}
                    onChange={(event) => setData('is_active', event.target.checked)}
                />

                <div className="border-t border-border pt-5">
                    <WebhookUrl url={gateway.webhook_url} hint={config.webhookHint} />
                </div>

                <div className="flex justify-end">
                    <Button type="submit" variant="primary" disabled={processing}>
                        Save {config.name}
                    </Button>
                </div>
            </form>
        </Card>
    );
}

export default function Payments({
    settlementMode,
    platformSettlementAvailable,
    gateways,
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
                />

                {canSetPlatformFee && <PlatformFeeForm platformFee={platformFee} />}

                <div>
                    <h2 className="text-sm font-semibold text-ink-secondary">Your own gateway</h2>
                    <p className="mt-1 text-sm text-ink-secondary">
                        {settlementMode === 'own_gateway'
                            ? 'Ticket payments go to the provider you mark active here.'
                            : 'Only used when the settlement mode is Own gateway.'}
                    </p>
                </div>
                <div className="grid gap-6 xl:grid-cols-2">
                    <GatewayForm provider="stripe" gateway={gateways.stripe} />
                    <GatewayForm provider="paystack" gateway={gateways.paystack} />
                </div>
            </div>
        </ConsoleLayout>
    );
}
