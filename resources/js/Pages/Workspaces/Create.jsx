import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Building2 } from 'lucide-react';

export default function Create({ auth, requestKey, defaults, recovering = false }) {
    const form = useForm({
        organization_name: defaults?.organization_name ?? '',
        slug: defaults?.slug ?? '',
        request_key: requestKey,
        plan: 'free',
    });
    const submit = (event) => {
        event.preventDefault();
        form.post(route('workspaces.store'));
    };
    const inputClass =
        'mt-2 w-full rounded-md border border-border bg-surface px-3 py-3 text-sm text-ink focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20 disabled:opacity-60';
    return (
        <div className="min-h-screen bg-canvas px-6 py-10 font-console text-ink">
            <Head title={recovering ? 'Resume organisation setup' : 'Create organisation'} />
            <main className="mx-auto max-w-xl">
                <Link
                    href={route('workspaces.index')}
                    className="inline-flex items-center gap-2 text-sm text-ink-secondary hover:text-ink"
                >
                    <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Your workspaces
                </Link>
                <Building2 className="mt-10 h-8 w-8 text-accent" aria-hidden="true" />
                <h1 className="mt-5 text-3xl font-semibold tracking-tight">
                    {recovering ? 'Finish setting up your organisation' : 'Create an organisation'}
                </h1>
                <p className="mt-3 text-sm text-ink-secondary">
                    Use your existing account, {auth?.user?.email}. You will own this workspace and
                    can invite your team after setup.
                </p>
                <form
                    onSubmit={submit}
                    className="mt-8 space-y-6 rounded-lg border border-border bg-surface p-6"
                    aria-busy={form.processing}
                >
                    <div>
                        <label htmlFor="organization_name" className="text-sm font-medium">
                            Organisation name
                        </label>
                        <input
                            id="organization_name"
                            name="organization_name"
                            value={form.data.organization_name}
                            onChange={(event) =>
                                form.setData('organization_name', event.target.value)
                            }
                            className={inputClass}
                            required
                            maxLength={255}
                            readOnly={recovering}
                            disabled={form.processing}
                            aria-invalid={Boolean(form.errors.organization_name)}
                            aria-describedby={
                                form.errors.organization_name ? 'name-error' : undefined
                            }
                        />
                        {form.errors.organization_name && (
                            <p id="name-error" className="mt-2 text-sm text-danger-fg" role="alert">
                                {form.errors.organization_name}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="slug" className="text-sm font-medium">
                            Workspace address
                        </label>
                        <input
                            id="slug"
                            name="slug"
                            value={form.data.slug}
                            onChange={(event) => form.setData('slug', event.target.value)}
                            className={inputClass}
                            required
                            minLength={3}
                            maxLength={30}
                            pattern="[a-z0-9]+(-[a-z0-9]+)*"
                            autoCapitalize="none"
                            spellCheck={false}
                            readOnly={recovering}
                            disabled={form.processing}
                            aria-invalid={Boolean(form.errors.slug)}
                            aria-describedby="slug-help slug-error"
                        />
                        <p id="slug-help" className="mt-2 text-xs text-ink-secondary">
                            Choose 3–30 lowercase letters, numbers or single hyphens for your
                            organisation’s address.
                        </p>
                        <p
                            id="slug-error"
                            className="mt-2 text-sm text-danger-fg"
                            role={form.errors.slug ? 'alert' : undefined}
                        >
                            {form.errors.slug}
                        </p>
                    </div>
                    <div className="rounded-md border border-border bg-canvas p-4">
                        <p className="text-sm font-semibold">Start on Free</p>
                        <p className="mt-1 text-sm text-ink-secondary">
                            No payment required. You can choose a paid plan from billing after
                            setup.
                        </p>
                        {form.errors.plan && (
                            <p className="mt-2 text-sm text-danger-fg" role="alert">
                                {form.errors.plan}
                            </p>
                        )}
                    </div>
                    {form.errors.request_key && (
                        <p className="text-sm text-danger-fg" role="alert">
                            {form.errors.request_key}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-md bg-accent px-4 py-3 text-sm font-medium text-accent-ink hover:opacity-90 disabled:cursor-wait disabled:opacity-60"
                    >
                        {form.processing
                            ? 'Preparing your workspace…'
                            : recovering
                              ? 'Resume setup'
                              : 'Create organisation'}
                    </button>
                </form>
            </main>
        </div>
    );
}
