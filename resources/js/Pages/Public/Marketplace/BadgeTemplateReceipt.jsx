import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';

export default function BadgeTemplateReceipt({ purchase }) {
    return (
        <PublicLayout>
            <Head title="Badge template licence" />
            <main className="mx-auto max-w-3xl space-y-6 px-5 py-10">
                <a href="/my/badge-template-purchases" className="text-sm text-accent underline">
                    All badge licences
                </a>
                <h1 className="text-2xl font-semibold">Badge template licence</h1>
                <h2 className="text-xl">{purchase.event.name}</h2>
                <p>GH₵50 · {purchase.state}</p>
                <p>{purchase.terms.licence}</p>
                <p>{purchase.terms.refund_terms}</p>
                {purchase.receipt ? (
                    <section className="space-y-3 border border-border p-5">
                        <h2 className="font-semibold">Receipt {purchase.receipt.number}</h2>
                        <p>{purchase.receipt.seller}</p>
                        <p>{purchase.receipt.receipt_terms}</p>
                        <p>Support · {purchase.receipt.support_contact}</p>
                    </section>
                ) : (
                    <p role="status">
                        Your receipt will appear here after payment confirmation. Refresh this page
                        to check again.
                    </p>
                )}
                <p>
                    Licence {purchase.licence_state}.{' '}
                    {purchase.used
                        ? 'Clean output has been requested. Contact support for refund assistance.'
                        : 'Clean output has not been requested.'}
                </p>
                {purchase.event_badges_url ? (
                    <a
                        href={purchase.event_badges_url}
                        className="inline-block text-accent underline"
                    >
                        Return to event badges
                    </a>
                ) : (
                    <p className="text-sm text-ink-secondary">
                        This event is archived. Your purchase history remains available.
                    </p>
                )}
            </main>
        </PublicLayout>
    );
}
