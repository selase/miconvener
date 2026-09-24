import { useState } from 'react';
import { AlertCircle, Award, Check, Download, ExternalLink, Smartphone, Sparkles, Trash2, WifiOff } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';
import { getOfflineTicket, removeOfflineTicket, saveOfflineTicket } from '@/lib/offlineTicketStore';

export default function MyTicketPanel({ event, registration, isOnline = true, certificate = null }) {
    const [transferring, setTransferring] = useState(false);
    // 'details' collects who it goes to; 'code' confirms it from the current
    // holder's inbox. The ticket does not move until the second step.
    const [stage, setStage] = useState('details');
    const [form, setForm] = useState({ full_name: '', email: '', phone: '' });
    const [code, setCode] = useState('');
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null);
    const [error, setError] = useState(null);
    const [savedOffline, setSavedOffline] = useState(Boolean(getOfflineTicket(registration.id)));
    const [showOfflineModal, setShowOfflineModal] = useState(false);

    const canTransfer = registration.status !== 'checked_in' && isOnline;

    const handleSaveOffline = () => {
        const snapshot = saveOfflineTicket(registration, event);
        if (snapshot) {
            setSavedOffline(true);
            setShowOfflineModal(false);
            setMessage('Ticket saved to this device for offline access.');
        }
    };

    const handleRemoveOffline = () => {
        removeOfflineTicket(registration.id);
        setSavedOffline(false);
        setMessage('Offline ticket copy removed from this device.');
    };

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

                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <a
                        href={`/my/events/${registration.id}/ticket`}
                        className="inline-flex h-control items-center gap-1.5 border border-border px-4 text-sm text-ink hover:border-accent"
                    >
                        <Download className="h-4 w-4" />
                        Download PDF
                    </a>

                    {savedOffline ? (
                        <div className="inline-flex h-control items-center gap-2 rounded-md border border-emerald-300 bg-emerald-50 px-3 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                            <span className="inline-flex items-center gap-1">
                                <Check className="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
                                Saved offline
                            </span>
                            <button
                                type="button"
                                onClick={handleRemoveOffline}
                                className="text-emerald-700 underline hover:text-emerald-900 dark:text-emerald-400 dark:hover:text-emerald-200 cursor-pointer"
                                title="Remove offline snapshot from this browser"
                            >
                                Remove
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setShowOfflineModal(true)}
                            className="inline-flex h-control items-center gap-1.5 border border-border px-4 text-sm text-ink hover:border-accent cursor-pointer"
                        >
                            <Smartphone className="h-4 w-4" />
                            Save ticket offline
                        </button>
                    )}

                    {canTransfer ? (
                        <button
                            onClick={() => setTransferring(!transferring)}
                            className="inline-flex h-control items-center border border-border px-4 text-sm text-ink hover:border-accent cursor-pointer"
                        >
                            Transfer
                        </button>
                    ) : !isOnline && registration.status !== 'checked_in' ? (
                        <span
                            className="inline-flex h-control items-center gap-1 border border-border/60 bg-surface-subtle px-3 text-xs text-ink-secondary cursor-not-allowed"
                            title="Internet connection required to transfer this ticket"
                        >
                            <WifiOff className="h-3.5 w-3.5" />
                            Transfer (offline)
                        </span>
                    ) : null}
                </div>

                {showOfflineModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="w-full max-w-md rounded-xl border border-border bg-surface p-6 shadow-xl space-y-4">
                            <div className="flex items-start gap-3">
                                <AlertCircle className="h-5 w-5 text-amber-500 shrink-0 mt-0.5" />
                                <div>
                                    <h3 className="text-sm font-semibold text-ink">
                                        Save ticket to this device?
                                    </h3>
                                    <p className="mt-1.5 text-xs text-ink-secondary leading-relaxed">
                                        This stores your ticket and QR code directly in this browser so you can view it even without an internet connection at the venue.
                                    </p>
                                    <p className="mt-2 text-xs font-medium text-amber-800 dark:text-amber-300">
                                        Do not save on shared or public computers. Anyone who opens this browser can see your ticket.
                                    </p>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setShowOfflineModal(false)}
                                    className="rounded-lg border border-border px-3.5 py-2 text-xs font-medium text-ink hover:bg-surface-subtle cursor-pointer"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={handleSaveOffline}
                                    className="rounded-lg bg-accent px-4 py-2 text-xs font-medium text-white hover:opacity-90 cursor-pointer"
                                >
                                    Save offline copy
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {certificate && (
                    <div className="mt-5 rounded-xl border border-border bg-surface-subtle p-4 space-y-3">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-border/60 pb-3">
                            <div className="flex items-center gap-2">
                                <Award className="h-4 w-4 text-accent" />
                                <h3 className="text-xs font-semibold text-ink uppercase tracking-wide">
                                    Official Certificate
                                </h3>
                                {certificate.role && (
                                    <span className="rounded-md bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                                        {certificate.role}
                                    </span>
                                )}
                            </div>
                            {certificate.cpd_hours && Number(certificate.cpd_hours) > 0 && (
                                <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    <Sparkles className="h-3 w-3" />
                                    <span>{certificate.cpd_hours} CPD hours</span>
                                </span>
                            )}
                        </div>

                        <p className="text-xs text-ink-secondary">
                            Your certificate of participation has been issued and verified for this registration.
                        </p>

                        <div className="flex flex-wrap items-center gap-2 pt-1">
                            {certificate.download_url && (
                                <a
                                    href={certificate.download_url}
                                    download
                                    className="inline-flex h-control items-center gap-1.5 border border-border bg-surface px-3 text-xs font-medium text-ink hover:border-accent"
                                >
                                    <Download className="h-3.5 w-3.5" />
                                    Download PDF
                                </a>
                            )}
                            {certificate.verification_url && (
                                <a
                                    href={certificate.verification_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex h-control items-center gap-1.5 border border-accent/30 bg-accent/5 px-3 text-xs font-medium text-accent hover:bg-accent/10"
                                >
                                    <span>Verify</span>
                                    <ExternalLink className="h-3 w-3 opacity-70" />
                                </a>
                            )}
                        </div>
                    </div>
                )}

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
