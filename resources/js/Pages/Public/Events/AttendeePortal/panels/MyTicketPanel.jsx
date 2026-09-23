import { useState } from 'react';
import { Download } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

export default function MyTicketPanel({ event, registration }) {
    const [transferring, setTransferring] = useState(false);
    // 'details' collects who it goes to; 'code' confirms it from the current
    // holder's inbox. The ticket does not move until the second step.
    const [stage, setStage] = useState('details');
    const [form, setForm] = useState({ full_name: '', email: '', phone: '' });
    const [code, setCode] = useState('');
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);

    const canTransfer = registration.status !== 'checked_in';

    const post = async (actionPath, legacyRouteName, body) => {
        const isPlatform = typeof window !== 'undefined' && window.location.pathname.startsWith('/my/events');
        const url = isPlatform
            ? `/my/events/${registration.id}/${actionPath}`
            : route(legacyRouteName, { event: event.slug, registration: registration.id });

        const response = await csrfFetch(url, {
            method: 'POST',
            body: JSON.stringify(body),
        });
        return [response, await response.json()];
    };

    const requestTransfer = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        setMessage(null);
        const [response, json] = await post('transfer', 'public.events.registrations.transfer', form);
        setSaving(false);
        if (!response.ok) {
            setError(json.message ?? 'Could not start this transfer.');
            return;
        }
        setMessage(json.message);
        setStage('code');
    };

    const confirmTransfer = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        const [response, json] = await post('transfer/confirm', 'public.events.registrations.transfer.confirm', {
            code,
        });
        setSaving(false);
        if (!response.ok) {
            setError(json.message ?? 'Could not complete this transfer.');
            return;
        }
        setTransferring(false);
        setStage('details');
        setCode('');
        setMessage(json.message);
        window.location.reload();
    };

    const cancelTransfer = () => {
        setTransferring(false);
        setStage('details');
        setCode('');
        setError(null);
        setMessage(null);
    };

    return (
        <div className="flex flex-col gap-6 sm:flex-row">
            {registration.qr_image && (
                <img
                    src={registration.qr_image}
                    alt="Your ticket QR code"
                    className="mx-auto h-40 w-40 shrink-0 border border-border p-2 sm:mx-0"
                />
            )}
            <div className="min-w-0 flex-1 text-left">
                <h2 className="text-lg font-medium text-ink">{registration.full_name}</h2>
                <p className="text-sm text-ink-secondary">{event.name}</p>
                <dl className="mt-4 space-y-2 text-[13px]">
                    {[
                        ['Entry code', registration.ticket_code],
                        ['Ticket', registration.ticket_type_name ?? 'General admission'],
                        ['Status', registration.status],
                        ...(registration.seat_label
                            ? [['Seat', `${registration.seat_label} · ${registration.room_name}`]]
                            : []),
                    ].map(([k, v]) => (
                        <div key={k} className="flex justify-between border-b border-border pb-2">
                            <dt className="text-ink-secondary">{k}</dt>
                            <dd className="font-mono text-ink">{v}</dd>
                        </div>
                    ))}
                </dl>

                <div className="mt-4 flex gap-2">
                    <a
                        href={`/my/events/${registration.id}/ticket`}
                        className="inline-flex h-control items-center gap-1.5 border border-border px-4 text-sm text-ink hover:border-accent"
                    >
                        <Download className="h-4 w-4" />
                        Download PDF
                    </a>
                    {canTransfer && (
                        <button
                            onClick={() => setTransferring(!transferring)}
                            className="inline-flex h-control items-center border border-border px-4 text-sm text-ink hover:border-accent"
                        >
                            Transfer
                        </button>
                    )}
                </div>

                {message && <p className="mt-3 text-[13px] text-ink-secondary">{message}</p>}
                {error && <p className="mt-3 text-[13px] text-danger-fg">{error}</p>}

                {transferring && stage === 'details' && (
                    <form
                        onSubmit={requestTransfer}
                        className="mt-4 space-y-2.5 border border-border p-4"
                    >
                        <p className="text-xs text-ink-secondary">
                            Give this ticket to someone else. We'll email a code to your address
                            first, so nobody can move it without you.
                        </p>
                        <input
                            value={form.full_name}
                            onChange={(e) => setForm({ ...form, full_name: e.target.value })}
                            placeholder="Their full name"
                            required
                            className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                        />
                        <input
                            type="email"
                            value={form.email}
                            onChange={(e) => setForm({ ...form, email: e.target.value })}
                            placeholder="Their email"
                            required
                            className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                        />
                        <input
                            value={form.phone}
                            onChange={(e) => setForm({ ...form, phone: e.target.value })}
                            placeholder="Their phone (optional)"
                            className="w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none"
                        />
                        <div className="flex gap-2">
                            <button
                                type="submit"
                                disabled={saving}
                                className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink"
                            >
                                {saving ? 'Sending code…' : 'Send me a code'}
                            </button>
                            <button
                                type="button"
                                onClick={cancelTransfer}
                                className="inline-flex h-control items-center border border-border px-4 text-sm text-ink"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                )}

                {transferring && stage === 'code' && (
                    <form
                        onSubmit={confirmTransfer}
                        className="mt-4 space-y-2.5 border border-border p-4"
                    >
                        <p className="text-xs text-ink-secondary">
                            Enter the code we emailed you to hand this ticket to{' '}
                            <span className="text-ink">{form.full_name}</span> ({form.email}). This
                            cannot be undone.
                        </p>
                        <input
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            placeholder="6-digit code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            required
                            className="w-full border border-border bg-surface px-3 py-2 font-mono text-[15px] tracking-[0.3em] text-ink focus:border-accent focus:outline-none"
                        />
                        <div className="flex gap-2">
                            <button
                                type="submit"
                                disabled={saving}
                                className="inline-flex h-control items-center bg-accent px-4 text-sm text-accent-ink"
                            >
                                {saving ? 'Transferring…' : 'Complete transfer'}
                            </button>
                            <button
                                type="button"
                                onClick={cancelTransfer}
                                className="inline-flex h-control items-center border border-border px-4 text-sm text-ink"
                            >
                                Cancel
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
