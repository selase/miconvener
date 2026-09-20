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
            <PageHeader title="Plans" />

            <div className="px-4 py-6 sm:px-8">
                <SegmentedControl
                    value={interval}
                    onChange={setInterval}
                    options={[
                        { value: 'month', label: 'Monthly' },
                        { value: 'year', label: 'Yearly' },
                    ]}
                />

                <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
                    {packages.map((pkg) => {
                        const isCurrent = pkg.slug === currentPackageSlug;
                        const price = interval === 'year' ? pkg.yearly_price : pkg.price;

                        return (
                            <div
                                key={pkg.id}
                                className="flex flex-col rounded-lg border border-border p-6"
                            >
                                <h3 className="text-lg font-bold text-ink">{pkg.name}</h3>
                                <div className="num mt-2 text-3xl font-extrabold text-ink">
                                    {pkg.is_free ? 'Free' : `${currency} ${price.toFixed(2)}`}
                                    {!pkg.is_free && (
                                        <span className="text-sm font-medium text-ink-secondary">
                                            /{interval}
                                        </span>
                                    )}
                                </div>

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

                                <Button
                                    onClick={() => choose(pkg)}
                                    disabled={isCurrent}
                                    className="mt-4 justify-center"
                                >
                                    {isCurrent ? 'Current plan' : 'Choose plan'}
                                </Button>
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
