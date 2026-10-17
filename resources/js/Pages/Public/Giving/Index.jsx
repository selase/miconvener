import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
export default function Index({ organisation, funds }) {
    return (
        <PublicLayout>
            <Head title={`Contribute · ${organisation.name}`} />
            <main className="mx-auto max-w-5xl px-5 py-12">
                <p className="text-sm uppercase tracking-widest text-accent">{organisation.name}</p>
                <h1 className="mt-3 text-4xl font-semibold text-ink">Make a contribution</h1>
                <p className="mt-4 max-w-xl text-ink-secondary">
                    Support a cause, an offering or a shared project. Every contribution is
                    voluntary. You do not need to become a member.
                </p>
                <div className="mt-10 grid gap-5 sm:grid-cols-2">
                    {funds.map((fund) => (
                        <a
                            key={fund.slug}
                            href={fund.url}
                            className="border border-border bg-surface p-6 hover:border-accent focus-visible:outline-2 focus-visible:outline-accent"
                        >
                            <h2 className="text-xl font-semibold text-ink">{fund.name}</h2>
                            <p className="mt-3 line-clamp-3 text-ink-secondary">
                                {fund.description}
                            </p>
                            <span className="mt-6 block text-accent">
                                {fund.state === 'closed' ? 'View fund' : fund.button_label} →
                            </span>
                        </a>
                    ))}
                </div>
                {funds.length === 0 && (
                    <p className="mt-10 border border-border p-6 text-ink-secondary">
                        There are no public contribution funds yet. Please check back later.
                    </p>
                )}
            </main>
        </PublicLayout>
    );
}
