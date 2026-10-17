import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import FollowControl from './FollowControl';
import Join from './Join';

export default function Organisation({
    organisation,
    givingUrl = null,
    events = [],
    identity,
    following,
    followingEnabled,
    joinOptions,
    followUrl,
    joinUrl,
    signInUrl,
    portalUrl,
    joinRequests = [],
}) {
    return (
        <PublicLayout>
            <Head title={`${organisation.name} · Community`} />
            <main className="mx-auto max-w-5xl space-y-10 px-5 py-12 sm:py-16">
                <header className="max-w-2xl space-y-4">
                    {organisation.logo_url && (
                        <img
                            src={organisation.logo_url}
                            alt=""
                            className="h-16 w-16 rounded-xl object-contain"
                        />
                    )}
                    <p className="text-xs font-semibold uppercase tracking-widest text-accent">
                        Our community
                    </p>
                    <h1 className="text-4xl font-semibold tracking-tight text-ink">
                        {organisation.name}
                    </h1>
                    <p className="text-base leading-relaxed text-ink-secondary">
                        Stay connected with the organisation and its upcoming events. Follow for
                        announcements or request a membership when joining is open.
                    </p>
                    <a
                        href={portalUrl}
                        className="inline-block text-sm font-medium text-accent underline"
                    >
                        My Communities and membership access
                    </a>
                </header>
                {givingUrl && (
                    <section className="border border-border p-6">
                        <h2 className="text-xl font-semibold text-ink">Support our work</h2>
                        <p className="my-3 text-ink-secondary">
                            Contribute to the causes and projects you care about. Membership is
                            optional.
                        </p>
                        <a href={givingUrl} className="text-accent underline">
                            View contribution funds
                        </a>
                    </section>
                )}
                <div className="grid gap-6 md:grid-cols-2">
                    {followingEnabled && (
                        <section
                            id="follow"
                            className="space-y-5 rounded-xl border border-border bg-surface p-6"
                        >
                            <h2 className="text-xl font-semibold text-ink">Stay in the loop</h2>
                            <FollowControl
                                identity={identity}
                                initialFollowing={following}
                                followUrl={followUrl}
                                signInUrl={signInUrl}
                            />
                        </section>
                    )}
                    <section
                        id="join"
                        className="space-y-5 rounded-xl border border-border bg-surface p-6"
                    >
                        <h2 className="text-xl font-semibold text-ink">Become a member</h2>
                        <Join
                            initialRequests={joinRequests}
                            identity={identity}
                            joinOptions={joinOptions}
                            joinUrl={joinUrl}
                            signInUrl={portalUrl}
                        />
                    </section>
                </div>
                <section className="space-y-5">
                    <h2 className="text-2xl font-semibold text-ink">Coming up</h2>
                    {events.length ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {events.map((event) => (
                                <a
                                    key={event.id}
                                    href={event.url}
                                    className="space-y-2 rounded-xl border border-border p-5 transition-colors hover:bg-surface-hover"
                                >
                                    <p className="text-xs font-medium text-accent">
                                        {new Intl.DateTimeFormat(undefined, {
                                            dateStyle: 'medium',
                                            timeZone: event.timezone,
                                        }).format(new Date(event.starts_at))}
                                    </p>
                                    <h3 className="font-semibold text-ink">{event.name}</h3>
                                    <span className="inline-block text-sm text-ink-secondary">
                                        View event →
                                    </span>
                                </a>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-ink-secondary">
                            There are no public upcoming events to show yet.
                        </p>
                    )}
                </section>
            </main>
        </PublicLayout>
    );
}
