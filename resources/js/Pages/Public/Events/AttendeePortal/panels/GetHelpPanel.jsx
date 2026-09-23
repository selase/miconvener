import { useState, useEffect } from 'react';
import {
    CheckCircle2,
    Clock,
    AlertCircle,
    Loader2,
    Send,
    HeartPulse,
    HelpCircle,
    Check,
} from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';

const HELP_TYPES = [
    { value: 'refreshment', label: 'Water / Refreshment' },
    { value: 'assistance', label: 'Seat Assistance' },
    { value: 'technical', label: 'AV / Technical' },
    { value: 'accessibility', label: 'Step-Free Access' },
    { value: 'medical', label: 'First Aid / Medical', urgent: true },
    { value: 'other', label: 'Something Else' },
];

const STATUS_STEPS = [
    { key: 'open', label: 'Open' },
    { key: 'acknowledged', label: 'Acknowledged' },
    { key: 'in_progress', label: 'In Progress' },
    { key: 'resolved', label: 'Resolved' },
];

export default function GetHelpPanel({ event, registration, isOnline = true }) {
    const isPlatform = typeof window !== 'undefined' && window.location.pathname.startsWith('/my/events');
    const [requests, setRequests] = useState([]);
    const [loadingRequests, setLoadingRequests] = useState(isPlatform);
    const [type, setType] = useState('refreshment');
    const [location, setLocation] = useState(
        registration.seat_label ? `${registration.seat_label}, ${registration.room_name || ''}`.trim() : ''
    );
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [successMessage, setSuccessMessage] = useState(null);

    const fetchRequests = async () => {
        if (!isPlatform) return;
        try {
            const res = await fetch(`/my/events/${registration.id}/service-requests`, {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setRequests(data.service_requests || data.requests || []);
            }
        } catch {
            // Tolerated on network fluctuation
        } finally {
            setLoadingRequests(false);
        }
    };

    useEffect(() => {
        if (isOnline && isPlatform) {
            fetchRequests();
        }
    }, [registration.id, isOnline, isPlatform]);

    // Active requests are open, acknowledged, or in_progress
    const activeRequests = requests.filter((r) =>
        ['open', 'acknowledged', 'in_progress'].includes(r.status)
    );

    const isTypeCurrentlyActive = activeRequests.some((r) => r.type === type);

    const submit = async (e) => {
        e.preventDefault();
        setSaving(true);
        setError(null);
        setSuccessMessage(null);

        const url = isPlatform
            ? `/my/events/${registration.id}/service-requests`
            : route('public.events.service-requests.store', {
                  event: event.slug,
                  registration: registration.id,
              });

        try {
            const response = await csrfFetch(url, {
                method: 'POST',
                body: JSON.stringify({ type, location: location || null, note: note || null }),
            });

            const json = await response.json();

            if (!response.ok) {
                setError(json.message || 'Unable to submit request.');
                setSaving(false);
                return;
            }

            setSuccessMessage(
                type === 'medical'
                    ? 'Urgent first aid request received. Medical staff has been alerted.'
                    : 'Help request sent to the floor team.'
            );
            setNote('');
            await fetchRequests();
        } catch {
            setError('A network error occurred. Please try again.');
        } finally {
            setSaving(false);
        }
    };

    const getStepStatus = (requestStatus, stepKey) => {
        const order = ['open', 'acknowledged', 'in_progress', 'resolved'];
        const currentIdx = order.indexOf(requestStatus);
        const stepIdx = order.indexOf(stepKey);

        if (currentIdx === -1) return 'pending'; // e.g. cancelled
        if (stepIdx < currentIdx) return 'completed';
        if (stepIdx === currentIdx) return 'current';
        return 'pending';
    };

    if (!isOnline) {
        return (
            <div className="rounded-xl border border-border bg-surface p-6 text-center text-ink-secondary text-sm">
                <AlertCircle className="mx-auto mb-2 h-6 w-6 text-amber-500" />
                <p>Internet connection required to request on-site assistance.</p>
            </div>
        );
    }

    return (
        <div className="space-y-6 text-left">
            <div>
                <h2 className="text-lg font-semibold tracking-tight text-ink">Get On-Site Help</h2>
                <p className="text-xs text-ink-secondary">
                    Requests go directly to the event floor response and logistics team.
                </p>
            </div>

            {/* Active / Prior Durable Requests */}
            {loadingRequests ? (
                <div className="flex items-center justify-center p-6 text-ink-secondary">
                    <Loader2 className="h-5 w-5 animate-spin text-accent" />
                    <span className="ml-2 text-xs">Checking request status…</span>
                </div>
            ) : requests.length > 0 ? (
                <div className="space-y-4">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                        Your Requests
                    </h3>
                    {requests.map((req) => (
                        <div
                            key={req.id}
                            className={`rounded-xl border p-4 shadow-sm ${
                                req.priority === 'urgent'
                                    ? 'border-red-300 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/20'
                                    : 'border-border bg-surface'
                            }`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        {req.type === 'medical' && (
                                            <HeartPulse className="h-4 w-4 text-red-600 dark:text-red-400" />
                                        )}
                                        <span className="text-sm font-semibold text-ink">
                                            {req.type_label}
                                        </span>
                                        {req.priority === 'urgent' && (
                                            <span className="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-red-800 dark:bg-red-950 dark:text-red-300">
                                                Urgent
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-xs text-ink-secondary">
                                        Raised at{' '}
                                        {new Date(req.created_at).toLocaleTimeString(undefined, {
                                            hour: 'numeric',
                                            minute: '2-digit',
                                        })}
                                        {req.location ? ` • ${req.location}` : ''}
                                    </p>
                                    {req.note && (
                                        <p className="mt-1 text-xs text-ink italic">"{req.note}"</p>
                                    )}
                                </div>
                            </div>

                            {/* Stepper */}
                            <div className="mt-4 pt-3 border-t border-border/60">
                                <div className="grid grid-cols-4 gap-2 text-center">
                                    {STATUS_STEPS.map((step) => {
                                        const state = getStepStatus(req.status, step.key);
                                        return (
                                            <div key={step.key} className="space-y-1">
                                                <div
                                                    className={`h-1.5 w-full rounded-full ${
                                                        state === 'completed'
                                                            ? 'bg-emerald-500'
                                                            : state === 'current'
                                                              ? req.status === 'resolved'
                                                                  ? 'bg-emerald-500'
                                                                  : 'bg-accent animate-pulse'
                                                              : 'bg-border'
                                                    }`}
                                                />
                                                <span
                                                    className={`text-[10px] font-medium block ${
                                                        state === 'current'
                                                            ? 'text-accent font-semibold'
                                                            : state === 'completed'
                                                              ? 'text-ink'
                                                              : 'text-ink-muted'
                                                    }`}
                                                >
                                                    {step.label}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            ) : null}

            {/* Request Form */}
            <form onSubmit={submit} className="rounded-xl border border-border bg-surface p-5 shadow-sm space-y-4">
                <h3 className="text-sm font-semibold text-ink">New Help Request</h3>

                {successMessage && (
                    <div className="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200 flex items-center gap-2">
                        <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                        <span>{successMessage}</span>
                    </div>
                )}

                {error && (
                    <div className="rounded-lg bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                        {error}
                    </div>
                )}

                <div>
                    <label className="mb-2 block text-xs font-semibold text-ink">
                        What do you need?
                    </label>
                    <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        {HELP_TYPES.map((item) => (
                            <button
                                type="button"
                                key={item.value}
                                onClick={() => {
                                    setType(item.value);
                                    setError(null);
                                }}
                                className={`flex items-center justify-between rounded-lg border px-3 py-2 text-xs font-medium min-h-[44px] cursor-pointer transition-colors ${
                                    type === item.value
                                        ? item.urgent
                                            ? 'border-red-500 bg-red-50 text-red-900 font-semibold dark:bg-red-950/50 dark:text-red-200'
                                            : 'border-accent bg-accent-soft text-accent font-semibold'
                                        : 'border-border bg-surface text-ink-secondary hover:border-accent hover:text-ink'
                                }`}
                            >
                                <span>{item.label}</span>
                                {item.urgent && (
                                    <HeartPulse className="h-3.5 w-3.5 text-red-500 shrink-0 ml-1" />
                                )}
                            </button>
                        ))}
                    </div>
                </div>

                {type === 'medical' && (
                    <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-900 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
                        <div className="flex items-center gap-1.5 font-semibold">
                            <HeartPulse className="h-4 w-4 text-red-600" />
                            <span>Urgent Priority Response</span>
                        </div>
                        <p className="mt-1 text-[11px] text-red-800 dark:text-red-300">
                            First aid requests are dispatched with highest priority directly to venue medical personnel.
                        </p>
                    </div>
                )}

                {isTypeCurrentlyActive && (
                    <div className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200 flex items-center gap-2">
                        <AlertCircle className="h-4 w-4 shrink-0 text-amber-600" />
                        <span>
                            You already have an active request for this need. Our team is currently attending to it.
                        </span>
                    </div>
                )}

                <div>
                    <label className="mb-1 block text-xs font-semibold text-ink">
                        Where are you located?
                    </label>
                    <input
                        type="text"
                        value={location}
                        onChange={(e) => setLocation(e.target.value)}
                        placeholder="e.g. Hall B, Row 4, Seat 12, or near the stage"
                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                    />
                </div>

                <div>
                    <label className="mb-1 block text-xs font-semibold text-ink">
                        Additional details (optional)
                    </label>
                    <textarea
                        value={note}
                        onChange={(e) => setNote(e.target.value)}
                        rows={2}
                        placeholder="Any specifics to help us assist you faster..."
                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                    />
                </div>

                <button
                    type="submit"
                    disabled={saving || isTypeCurrentlyActive}
                    className={`inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-lg px-6 text-xs font-semibold text-white transition-opacity cursor-pointer w-full sm:w-auto ${
                        type === 'medical'
                            ? 'bg-red-600 hover:bg-red-700 disabled:opacity-50'
                            : 'bg-accent hover:opacity-90 disabled:opacity-50'
                    }`}
                >
                    {saving ? (
                        <>
                            <Loader2 className="h-4 w-4 animate-spin" />
                            Dispatching…
                        </>
                    ) : (
                        <>
                            <Send className="h-3.5 w-3.5" />
                            {type === 'medical' ? 'Request Immediate First Aid' : 'Send Help Request'}
                        </>
                    )}
                </button>
            </form>
        </div>
    );
}
