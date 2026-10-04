import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import SegmentedControl from '@/Components/Console/SegmentedControl';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';

export default function Pricing({ packages, currentPackageSlug, currency = 'GHS' }) {
    const [interval, setInterval] = useState('month');
    // A plan that costs the organizer something is confirmed first, with what
    // it costs spelled out. Moving up, or to a plan with nothing at stake,
    // goes straight through.
    const [confirming, setConfirming] = useState(null);

    const go = (pkg) => router.post(route('billing.checkout'), { plan: pkg.slug, interval });

    const choose = (pkg) => {
        if (pkg.warnings?.length) {
            setConfirming(pkg);
            return;
        }
        go(pkg);
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title="Plans"
                actions={
                    <Button href={route('billing.addons.index')}>
                        Modular Add-Ons
                    </Button>
                }
            />

            <div className="px-4 py-6 sm:px-8">
                <SegmentedControl
                    value={interval}
                    onChange={setInterval}
                    options={[
                        { value: 'month', label: 'Monthly' },
                        { value: 'year', label: 'Yearly' },
                    ]}
                />

                <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    {packages.map((pkg) => {
                        const isCurrent = pkg.slug === currentPackageSlug;
                        const price = interval === 'year' ? pkg.yearly_price : pkg.price;

                        return (
                            <div
                                key={pkg.id}
                                className={`flex flex-col rounded-lg border p-6 transition-all ${
                                    isCurrent ? 'border-accent bg-accent-soft/20' : 'border-border bg-surface'
                                }`}
                            >
                                <div className="flex items-center justify-between">
                                    <h3 className="text-lg font-bold text-ink">{pkg.name}</h3>
                                    {isCurrent && (
                                        <span className="rounded-full bg-accent px-2 py-0.5 text-[10px] font-bold text-accent-ink uppercase tracking-wider">
                                            Current
                                        </span>
                                    )}
                                </div>
                                <div className="num mt-2 text-3xl font-extrabold text-ink">
                                    {pkg.is_free ? (
                                        'Free'
                                    ) : pkg.is_custom ? (
                                        'Custom'
                                    ) : (
                                        `${currency} ${price.toFixed(2)}`
                                    )}
                                    {!pkg.is_free && !pkg.is_custom && (
                                        <span className="text-sm font-medium text-ink-secondary">
                                            /{interval}
                                        </span>
                                    )}
                                </div>
                                {pkg.is_custom && (
                                    <div className="mt-1 text-xs text-ink-secondary">
                                        Negotiated limits & terms
                                    </div>
                                )}

                                <ul className="mt-4 flex-1 space-y-2">
                                    {pkg.features.map((feature) => (
                                        <li key={feature} className="text-sm text-ink-secondary">
                                            ✓ {feature}
                                        </li>
                                    ))}
                                </ul>

                                {pkg.warnings?.length > 0 && !isCurrent && (
                                    <p className="mt-4 text-xs text-warning-fg">
                                        {pkg.warnings.length === 1
                                            ? '1 thing to check before moving'
                                            : `${pkg.warnings.length} things to check before moving`}
                                    </p>
                                )}

                                {pkg.is_custom ? (
                                    <Button
                                        href="mailto:sales@miconvener.com?subject=Enterprise%20Inquiry"
                                        variant="default"
                                        className="mt-4 justify-center"
                                    >
                                        Contact sales
                                    </Button>
                                ) : (
                                    <Button
                                        onClick={() => choose(pkg)}
                                        disabled={isCurrent}
                                        variant={isCurrent ? 'default' : 'primary'}
                                        className="mt-4 justify-center"
                                    >
                                        {isCurrent ? 'Current plan' : 'Choose plan'}
                                    </Button>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            {confirming && (
                <ConfirmModal
                    open
                    title={`Move to ${confirming.name}?`}
                    description={
                        // ConfirmModal renders this inside a <p>, so these stay inline elements.
                        <>
                            {confirming.warnings.map((warning) => (
                                <span key={warning} className="mb-2 block last:mb-0">
                                    {warning}
                                </span>
                            ))}
                        </>
                    }
                    confirmLabel={`Move to ${confirming.name}`}
                    onConfirm={() => {
                        const pkg = confirming;
                        setConfirming(null);
                        go(pkg);
                    }}
                    onClose={() => setConfirming(null)}
                />
            )}
        </ConsoleLayout>
    );
}
