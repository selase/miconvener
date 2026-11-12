import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

export default function BadgeTemplatePurchases({ purchases }) {
    return (
        <PublicLayout>
            <Head title="Your badge licences" />
            <main className="mx-auto max-w-3xl space-y-6 px-5 py-10">
                <nav
                    className="flex flex-wrap gap-4 text-sm text-accent underline"
                    aria-label="Purchases"
                >
                    <a href="/my">My MiConvener</a>
                    <a href="/my/store-orders">Store orders</a>
                </nav>
                <h1 className="text-2xl font-semibold">Your badge licences</h1>
                <p className="text-sm text-ink-secondary">
                    Event design purchases made with your verified email.
                </p>
                {purchases.data.length ? (
                    purchases.data.map((purchase) => (
                        <article
                            className="space-y-2 border border-border p-5"
                            key={purchase.purchase_id}
                        >
                            <h2 className="font-semibold">
                                {purchase.terms.design_name || 'Badge template licence'}
                            </h2>
                            <p>{purchase.event.name}</p>
                            <p className="text-sm">
                                GH₵50 · {purchase.state} · Licence {purchase.licence_state}
                            </p>
                            <a
                                className="inline-block py-2 text-accent underline"
                                href={route('badge-template-purchases.show', {
                                    purchase: purchase.purchase_id,
                                })}
                            >
                                View licence and receipt
                            </a>
                        </article>
                    ))
                ) : (
                    <p>No badge template purchases at this verified address.</p>
                )}
                <nav
                    className="flex gap-5 text-sm text-accent underline"
                    aria-label="Licence pages"
                >
                    {purchases.prev_page_url && <a href={purchases.prev_page_url}>Previous page</a>}
                    {purchases.next_page_url && <a href={purchases.next_page_url}>Next page</a>}
                </nav>
            </main>
        </PublicLayout>
    );
}
