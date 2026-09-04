import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';

export default function Wizard({ org }) {
    const { data, setData, post, processing, errors } = useForm({
        name: org.name ?? '',
        primary_color: org.primary_color ?? '#009EF7',
        logo: null,
    });

    const submit = (event) => {
        event.preventDefault();
        post(route('tenant.onboarding.branding.update'), { forceFormData: true });
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Welcome to MiConvener" />

            <div className="max-w-2xl px-8 py-6">
                <p className="mb-6 text-sm text-ink-secondary">
                    Let&apos;s set up your organization&apos;s look before you dive in.
                </p>

                <form onSubmit={submit} className="space-y-6 rounded-lg border border-border bg-surface p-6">
                    <div className="flex items-center gap-4">
                        <img src={org.logo} alt="" className="h-16 w-16 rounded-lg border border-border object-cover" />
                        <div>
                            <label className="inline-block cursor-pointer rounded-lg border border-border px-3 py-1.5 text-sm font-medium text-ink hover:bg-surface-sunken">
                                Upload logo
                                <input
                                    type="file"
                                    accept="image/png,image/jpeg,image/svg+xml"
                                    className="hidden"
                                    onChange={(event) => setData('logo', event.target.files[0])}
                                />
                            </label>
                            {errors.logo && <p className="mt-1 text-sm text-danger-fg">{errors.logo}</p>}
                        </div>
                    </div>

                    <Input
                        label="Organization name"
                        type="text"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                        error={errors.name}
                    />

                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-ink">Brand color</label>
                        <div className="flex items-center gap-3">
                            <input
                                type="color"
                                value={data.primary_color}
                                onChange={(event) => setData('primary_color', event.target.value)}
                                className="h-9 w-14 rounded border border-border"
                            />
                            <span className="text-sm text-ink-secondary">{data.primary_color}</span>
                        </div>
                    </div>

                    <Button type="submit" disabled={processing}>Continue</Button>
                </form>
            </div>
        </ConsoleLayout>
    );
}
