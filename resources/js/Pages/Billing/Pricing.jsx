import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import SegmentedControl from '@/Components/Console/SegmentedControl';
import Button from '@/Components/Console/Button';

export default function Pricing({ packages, currentPackageSlug }) {
    const [interval, setInterval] = useState('month');

    const choose = (pkg) => {
        router.post(route('billing.checkout'), { plan: pkg.slug, interval });
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Plans" />

            <div className="px-8 py-6">
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
                            <div key={pkg.id} className="flex flex-col rounded-lg border border-border p-6">
                                <h3 className="text-lg font-bold text-ink">{pkg.name}</h3>
                                <div className="num mt-2 text-3xl font-extrabold text-ink">
                                    {pkg.is_free ? 'Free' : `$${price.toFixed(2)}`}
                                    {!pkg.is_free && <span className="text-sm font-medium text-ink-secondary">/{interval}</span>}
                                </div>

                                <ul className="mt-4 flex-1 space-y-2">
                                    {pkg.features.map((feature) => (
                                        <li key={feature} className="text-sm text-ink-secondary">✓ {feature}</li>
                                    ))}
                                </ul>

                                <Button onClick={() => choose(pkg)} disabled={isCurrent} className="mt-6 justify-center">
                                    {isCurrent ? 'Current plan' : 'Choose plan'}
                                </Button>
                            </div>
                        );
                    })}
                </div>
            </div>
        </ConsoleLayout>
    );
}
