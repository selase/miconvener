import { Head } from '@inertiajs/react';

/**
 * Placeholder for the public dues pay page. The server already sends the
 * organisation, the member, their open charges (mode "pay") or paid charges
 * (mode "receipt") and canPayOnline; the full page replaces this in a later
 * milestone task.
 */
export default function MemberPay({ organisation, member, mode }) {
    return (
        <main>
            <Head title={mode === 'receipt' ? 'Receipt' : 'Pay dues'} />
            <h1>{organisation?.name}</h1>
            <p>Hello {member?.first_name}</p>
        </main>
    );
}
