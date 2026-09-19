import { useForm, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Checkbox from '@/Components/Console/Checkbox';

export default function Settings({ org }) {
    const { flash, auth } = usePage().props;

    const { data, setData, transform, post, processing, errors } = useForm({
        name: org.name ?? '',
        email: org.email ?? '',
        phone_number: org.phone_number ?? '',
        primary_color: org.primary_color ?? '#009EF7',
        require_2fa: org.require_2fa ?? false,
        custom_domain: org.can_use_custom_domain ? (org.custom_domain ?? '') : '',
        logo: null,
    });

    const domainForm = useForm({});

    const submit = (event) => {
        event.preventDefault();
        transform((values) => {
            if (org.can_use_custom_domain) return values;
            const { custom_domain, ...otherValues } = values;
            return otherValues;
        });
        post(route('tenant.settings.update'), { forceFormData: true });
    };

    const verifyDomain = () => {
        domainForm.post(route('tenant.settings.verify-domain'));
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Organization Settings" />

            <div className="max-w-2xl px-8 py-6">
                {flash?.success && (
                    <div className="mb-6 rounded-lg border border-success-fg/30 bg-success-bg px-4 py-3 text-sm text-success-fg">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mb-6 rounded-lg border border-danger-fg/30 bg-danger-bg px-4 py-3 text-sm text-danger-fg">
                        {flash.error}
                    </div>
                )}

                <form
                    onSubmit={submit}
                    className="space-y-6 rounded-lg border border-border bg-surface p-6"
                >
                    <div className="flex items-center gap-4">
                        <img
                            src={org.logo}
                            alt=""
                            className="h-16 w-16 rounded-lg border border-border object-cover"
                        />
                        {org.can_use_own_logo && (
                            <div>
                                <label className="inline-block cursor-pointer rounded-lg border border-border px-3 py-1.5 text-sm font-medium text-ink hover:bg-surface-sunken">
                                    Change logo
                                    <input
                                        type="file"
                                        accept="image/png,image/jpeg,image/svg+xml"
                                        className="hidden"
                                        onChange={(event) => setData('logo', event.target.files[0])}
                                    />
                                </label>
                                {errors.logo && (
                                    <p className="mt-1 text-sm text-danger-fg">{errors.logo}</p>
                                )}
                            </div>
                        )}
                    </div>

                    <Input
                        label="Organization name"
                        type="text"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        error={errors.name}
                    />

                    <Input
                        label="Email"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                        error={errors.email}
                    />

                    <Input
                        label="Phone number"
                        type="text"
                        value={data.phone_number}
                        onChange={(event) => setData('phone_number', event.target.value)}
                        error={errors.phone_number}
                    />

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-ink">
                            Brand color
                        </label>
                        <div className="flex items-center gap-3">
                            <input
                                type="color"
                                value={data.primary_color}
                                onChange={(event) => setData('primary_color', event.target.value)}
                                className="h-9 w-14 rounded border border-border"
                            />
                            <span className="text-sm text-ink-secondary">{data.primary_color}</span>
                        </div>
                        {errors.primary_color && (
                            <p className="mt-1 text-sm text-danger-fg">{errors.primary_color}</p>
                        )}
                    </div>

                    <Checkbox
                        label={
                            <span className="text-sm font-medium text-ink">
                                Require two-factor authentication for all members
                            </span>
                        }
                        checked={data.require_2fa}
                        onChange={(event) => setData('require_2fa', event.target.checked)}
                    />

                    {org.can_use_custom_domain && (
                        <div className="space-y-4 border-t border-border pt-6">
                            <div>
                                <h2 className="text-sm font-semibold text-ink">Custom domain</h2>
                                <p className="mt-1 text-sm text-ink-secondary">
                                    Use your own domain for this organization.
                                </p>
                            </div>
                            <Input
                                label="Domain"
                                type="text"
                                placeholder="events.example.com"
                                value={data.custom_domain}
                                onChange={(event) => setData('custom_domain', event.target.value)}
                                error={errors.custom_domain}
                            />
                            {org.custom_domain && (
                                <div className="flex items-center justify-between rounded-lg bg-surface-sunken px-4 py-3">
                                    <span className="text-sm text-ink-secondary">
                                        Status:{' '}
                                        <span className="font-medium text-ink">
                                            {org.custom_domain_status ?? 'pending'}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        onClick={verifyDomain}
                                        disabled={domainForm.processing}
                                    >
                                        Verify domain
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}

                    <Button type="submit" disabled={processing}>
                        Save changes
                    </Button>
                </form>

                {/*
                 * The old settings hub was the only way in to these pages, and it
                 * now redirects here. Without these links, payment settings in
                 * particular is reachable only by typing the URL.
                 */}
                <div className="mt-6 rounded-lg border border-border bg-surface p-6">
                    <h2 className="text-sm font-medium text-ink">Other settings</h2>
                    <div className="mt-4 flex flex-col gap-2">
                        {auth?.can?.manage_payment_settings && (
                            <a
                                href={route('tenant.settings.payments.index')}
                                className="text-sm text-accent hover:underline"
                            >
                                Payments and payouts
                            </a>
                        )}
                        <a
                            href={route('tenant.settings.usage')}
                            className="text-sm text-accent hover:underline"
                        >
                            Plan usage
                        </a>
                    </div>
                </div>
            </div>
        </ConsoleLayout>
    );
}
