import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';

export default function GuestPollSettings({ event }) {
    const { auth } = usePage().props;
    const canUpdate = auth?.can?.update_event === true;
    const [settings, setSettings] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const load = async (toggle = false) => {
        setBusy(true);
        setError(null);
        try {
            const response = await csrfFetch(route(toggle ? 'tenant.events.polls.guests.update' : 'tenant.events.polls.guests.show', { event: event.id }), toggle ? {
                method: 'PATCH', body: JSON.stringify({ enabled: !settings.enabled }),
            } : undefined);
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to load guest participation.');
            setSettings(data);
        } catch (problem) {
            setError(problem.message || 'Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    };
    useEffect(() => { load(); }, [event.id]);

    return (
        <section className="space-y-3 rounded-lg border border-border p-4" aria-label="Guest poll participation">
            <h3 className="text-sm font-semibold text-ink">Join live polls without registration</h3>
            <p className="text-xs text-ink-secondary">Optional for public events. Guests answer during the event without a ticket, name or email. One answer per browser session, not verified one-person voting. Registered attendees can continue using their tickets.</p>
            {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
            {settings && canUpdate && <Button disabled={busy} onClick={() => load(true)}>{settings.enabled ? 'Pause guest polls' : 'Enable guest polls'}</Button>}
            {!settings && <Button disabled={busy} onClick={() => load()}>{busy ? 'Loading…' : 'Retry'}</Button>}
            {settings?.enabled && <div className="space-y-2">
                <p className="text-xs text-ink-secondary">Guest polls enabled. The results screen QR also opens this page.</p>
                <a className="block break-all text-sm text-accent underline" href={settings.url} target="_blank" rel="noopener noreferrer">Open guest join page</a>
                <p className="break-all text-xs text-ink-secondary">{settings.url}</p>
                <img src={settings.qr} alt="Scan to join live polls" className="h-36 w-36 bg-white p-2" />
            </div>}
        </section>
    );
}
