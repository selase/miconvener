import { useState } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Button from '@/Components/Console/Button';
import { sendJson } from '@/lib/dues';
export default function Unsubscribe({ reminderId, token, stopped }) {
    const [done, setDone] = useState(stopped),
        [busy, setBusy] = useState(false),
        [message, setMessage] = useState('');
    const stop = async () => {
        setBusy(true);
        try {
            const response = await sendJson(
                'POST',
                route('public.giving.reminders.stop', { reminder: reminderId }),
                { token }
            );
            if (response.ok) setDone(true);
            else setMessage(response.message ?? 'Please try again.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <PublicLayout>
            <Head title="Giving reminders" />
            <main className="mx-auto max-w-xl px-5 py-16">
                <h1 className="text-3xl font-semibold text-ink">
                    {done ? 'Giving reminders stopped' : 'Stop giving reminders?'}
                </h1>
                <p className="my-6 text-ink-secondary">
                    {done
                        ? 'You will not receive further reminders for this fund.'
                        : 'Confirm below to stop optional email reminders for this fund. Your receipts and contribution history remain available.'}
                </p>
                {!done && (
                    <Button onClick={stop} disabled={busy} variant="primary">
                        {busy ? 'Stopping…' : 'Stop these reminders'}
                    </Button>
                )}
                {message && (
                    <p role="alert" className="mt-5 text-ink">
                        {message}
                    </p>
                )}
            </main>
        </PublicLayout>
    );
}
