import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';

export default function Billing({ billingEmail, taxId, billingAddress }) {
    const { data, setData, post, processing, errors, reset, isDirty } = useForm({
        billing_email: billingEmail ?? '',
        tax_id: taxId ?? '',
        billing_address: billingAddress ?? '',
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.settings.billing.update'), { preserveScroll: true });
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title="Billing details"
                actions={<Button href={route('billing.index')}>Back to billing</Button>}
            />

            <div className="max-w-2xl px-4 py-6 sm:px-8">
                <form
                    onSubmit={submit}
                    className="space-y-6 rounded-lg border border-border p-5 sm:p-6"
                >
                    <p className="text-sm text-ink-secondary">
                        Who we contact about billing, and the tax details for your organization.
                    </p>

                    <Input
                        label="Billing email"
                        type="email"
                        required
                        placeholder="billing@company.com"
                        value={data.billing_email}
                        onChange={(event) => setData('billing_email', event.target.value)}
                        error={errors.billing_email}
                    />

                    <Input
                        label="Tax ID or VAT number"
                        type="text"
                        placeholder="e.g. C0012345678"
                        value={data.tax_id}
                        onChange={(event) => setData('tax_id', event.target.value)}
                        error={errors.tax_id}
                    />

                    <div>
                        <label
                            htmlFor="billing_address"
                            className="mb-1.5 block text-sm font-medium text-ink"
                        >
                            Billing address
                        </label>
                        <textarea
                            id="billing_address"
                            rows={3}
                            placeholder="Street address, city, country"
                            value={data.billing_address}
                            onChange={(event) => setData('billing_address', event.target.value)}
                            className="w-full rounded-lg border border-border px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        {errors.billing_address && (
                            <p className="mt-1 text-sm text-danger-fg">{errors.billing_address}</p>
                        )}
                        <p className="mt-1 text-xs text-ink-secondary">
                            Defaults to your organization address from{' '}
                            <Link
                                href={route('tenant.settings.index')}
                                className="text-accent hover:underline"
                            >
                                Settings
                            </Link>
                            .
                        </p>
                    </div>

                    <div className="flex justify-end gap-3 border-t border-border pt-5">
                        <Button
                            type="button"
                            onClick={() => reset()}
                            disabled={!isDirty || processing}
                        >
                            Discard
                        </Button>
                        <Button type="submit" variant="primary" disabled={processing}>
                            Save changes
                        </Button>
                    </div>
                </form>
            </div>
        </ConsoleLayout>
    );
}
