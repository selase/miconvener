import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Building2, Plus } from 'lucide-react';
import ThemeToggle from '@/Components/Console/ThemeToggle';

export default function Index({
    auth,
    workspaces = [],
    pendingWorkspaces = [],
    canCreateOrganisation = false,
}) {
    return (
        <div className="min-h-screen bg-canvas font-console text-ink">
            <Head title="Your workspaces" />
            <header className="border-b border-border bg-surface">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-5">
                    <span className="text-sm font-semibold">MiConvener</span>
                    <div className="flex items-center gap-4">
                        <span className="max-w-48 truncate text-sm text-ink-secondary">
                            {auth?.user?.email}
                        </span>
                        <Link
                            href={route('workspaces.account')}
                            className="text-sm text-accent hover:underline"
                        >
                            My account
                        </Link>
                        <ThemeToggle />
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            className="text-sm text-ink-secondary hover:text-ink"
                        >
                            Sign out
                        </Link>
                    </div>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-6 py-12">
                <div className="flex flex-wrap items-end justify-between gap-5">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-ink-tertiary">
                            Your account
                        </p>
                        <h1 className="mt-2 text-3xl font-semibold tracking-tight">
                            Your workspaces
                        </h1>
                        <p className="mt-3 max-w-xl text-sm text-ink-secondary">
                            Choose an organisation to continue. Each workspace keeps its own team,
                            events and settings.
                        </p>
                    </div>
                    {canCreateOrganisation && (
                        <Link
                            href={route('workspaces.create')}
                            className="inline-flex items-center gap-2 rounded-md bg-accent px-4 py-3 text-sm font-medium text-accent-ink hover:opacity-90"
                        >
                            <Plus className="h-4 w-4" aria-hidden="true" /> Create organisation
                        </Link>
                    )}
                </div>
                {!canCreateOrganisation && (
                    <p role="status" className="mt-6 text-sm text-ink-secondary">
                        Organisation creation temporarily unavailable
                    </p>
                )}
                {pendingWorkspaces.length > 0 && (
                    <section aria-label="Organisations being prepared" className="mt-8 space-y-3">
                        {pendingWorkspaces.map((workspace) => (
                            <div
                                key={workspace.retry_url}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface p-5"
                                role="status"
                            >
                                <div>
                                    <p className="text-sm font-medium">{workspace.name}</p>
                                    <p className="mt-1 text-sm text-ink-secondary">
                                        {!canCreateOrganisation
                                            ? 'Your setup is saved. Resume when organisation creation is available.'
                                            : workspace.state === 'failed'
                                              ? 'Setup needs another attempt. Your organisation is saved.'
                                              : 'Setup has started. You can safely check or resume it.'}
                                    </p>
                                </div>
                                {canCreateOrganisation && (
                                    <Link
                                        href={workspace.retry_url}
                                        className="text-sm font-medium text-accent hover:underline"
                                    >
                                        Resume setup
                                    </Link>
                                )}
                            </div>
                        ))}
                    </section>
                )}
                {workspaces.length === 0 ? (
                    <section className="mt-8 rounded-lg border border-dashed border-border-strong bg-surface px-6 py-14 text-center">
                        <Building2
                            className="mx-auto h-9 w-9 text-ink-tertiary"
                            aria-hidden="true"
                        />
                        <h2 className="mt-5 text-lg font-semibold">
                            A workspace for your organisation
                        </h2>
                        <p className="mx-auto mt-2 max-w-md text-sm text-ink-secondary">
                            {canCreateOrganisation
                                ? 'You have no active organisation memberships. Create an organisation, or ask your administrator for an invitation.'
                                : 'You have no active organisation memberships. Ask your administrator for an invitation.'}
                        </p>
                        {canCreateOrganisation && (
                            <Link
                                href={route('workspaces.create')}
                                className="mt-5 inline-block text-sm font-medium text-accent hover:underline"
                            >
                                Create your first organisation
                            </Link>
                        )}
                    </section>
                ) : (
                    <section
                        aria-label="Your organisations"
                        className="mt-8 grid gap-4 sm:grid-cols-2"
                    >
                        {workspaces.map((workspace) => (
                            <article
                                key={workspace.id}
                                className="rounded-lg border border-border bg-surface p-6"
                            >
                                <Building2 className="h-6 w-6 text-accent" aria-hidden="true" />
                                <h2 className="mt-5 break-words text-lg font-semibold">
                                    {workspace.name}
                                </h2>
                                <p className="mt-1 text-sm text-ink-secondary">{workspace.slug}</p>
                                {workspace.available ? (
                                    <a
                                        href={workspace.url}
                                        className="mt-6 inline-flex items-center gap-2 text-sm font-medium text-accent hover:underline"
                                    >
                                        Open workspace{' '}
                                        <ArrowUpRight className="h-4 w-4" aria-hidden="true" />
                                        <span className="sr-only">for {workspace.name}</span>
                                    </a>
                                ) : (
                                    <p className="mt-6 text-sm text-ink-secondary">
                                        This organisation is currently unavailable.
                                    </p>
                                )}
                            </article>
                        ))}
                    </section>
                )}
            </main>
        </div>
    );
}
