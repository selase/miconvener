import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import { formatDuesMoney } from '@/lib/dues';
export default function Result({
    status,
    amount,
    currency,
    receiptUrl = null,
    eventUrl = null,
    fundUrl = null,
}) {
    const confirmed = status === 'confirmed';
    return (
        <PublicLayout>
            <Head title="Your contribution" />
            <main className="mx-auto max-w-xl px-5 py-16">
                <p className="text-sm uppercase tracking-widest text-accent">Your contribution</p>
                <h1 className="mt-4 text-3xl font-semibold text-ink">
                    {confirmed ? 'Thank you for your contribution' : 'We are checking your payment'}
                </h1>
                <p className="mt-6 text-xl text-ink">{formatDuesMoney(amount, currency)}</p>
                <p role="status" className="mt-4 text-ink-secondary">
                    {confirmed
                        ? 'Your payment is confirmed. Keep your receipt for your records.'
                        : 'Keep this page open and refresh to check its status. Please do not start another payment while the outcome is being checked.'}
                </p>
                {confirmed && receiptUrl && (
                    <a href={receiptUrl} className="mt-6 inline-block text-accent underline">
                        Download receipt
                    </a>
                )}
                {confirmed && fundUrl && (
                    <a href={fundUrl} className="mt-6 block min-h-11 text-accent underline">
                        Return to the fund · optional email reminders
                    </a>
                )}
                {eventUrl && (
                    <a href={eventUrl} className="mt-6 block min-h-11 text-accent underline">
                        Return to the event
                    </a>
                )}
                <p className="mt-8 text-sm text-ink-secondary">
                    Making a contribution does not create a membership or subscribe you to
                    announcements.
                </p>
            </main>
        </PublicLayout>
    );
}
