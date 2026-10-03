import { useState } from 'react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';
import { Check, X, FileText, Download, AlertCircle, Loader2 } from 'lucide-react';

export default function OfflineVerificationModal({ open, registration, event, onClose, onSuccess }) {
    const [mode, setMode] = useState('view'); // 'view' | 'reject'
    const [rejectReason, setRejectReason] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    if (!registration) {
        return null;
    }

    const proofUrl = registration.offline_payment_proof_path && window.route
        ? route('tenant.events.registrations.offline-proof', {
              event: event.id,
              registration: registration.id,
          })
        : null;

    const formattedAmount = registration.formatted_amount
        || `GHS ${((registration.amount || 0) / 100).toFixed(2)}`;

    const handleApprove = async () => {
        setSubmitting(true);
        setError(null);

        try {
            const url = window.route
                ? route('tenant.events.registrations.approve-offline', {
                      event: event.id,
                      registration: registration.id,
                  })
                : `/events/${event.id}/registrations/${registration.id}/approve-offline`;

            const res = await csrfFetch(url, { method: 'POST' });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                setError(data.message || 'Failed to approve registration.');
                setSubmitting(false);
                return;
            }

            if (onSuccess) {
                onSuccess();
            }
            onClose();
        } catch {
            setError('A network error occurred. Please check your connection.');
        } finally {
            setSubmitting(false);
        }
    };

    const handleReject = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            const url = window.route
                ? route('tenant.events.registrations.reject-offline', {
                      event: event.id,
                      registration: registration.id,
                  })
                : `/events/${event.id}/registrations/${registration.id}/reject-offline`;

            const res = await csrfFetch(url, {
                method: 'POST',
                body: JSON.stringify({ reason: rejectReason }),
            });

            if (!res.ok) {
                const data = await res.json().catch(() => ({}));
                setError(data.message || 'Failed to reject registration.');
                setSubmitting(false);
                return;
            }

            if (onSuccess) {
                onSuccess();
            }
            onClose();
        } catch {
            setError('A network error occurred. Please check your connection.');
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal open={open} onClose={onClose} title="Verify Offline Payment" className="max-w-lg">
            <div className="space-y-4">
                {error && (
                    <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-400">
                        <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
                        <span>{error}</span>
                    </div>
                )}

                {/* Registration Details Summary */}
                <div className="rounded-lg border border-border bg-surface-subtle p-3.5 space-y-2 text-xs">
                    <div className="flex justify-between items-center pb-2 border-b border-border">
                        <div>
                            <div className="font-semibold text-ink text-sm">
                                {registration.full_name || registration.name || 'Attendee'}
                            </div>
                            <div className="text-ink-secondary">{registration.email}</div>
                        </div>
                        <div className="text-right">
                            <div className="font-bold text-ink text-sm">{formattedAmount}</div>
                            <span className="text-[11px] text-ink-secondary">
                                {registration.payment_method || 'Offline Payment'}
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-2 pt-1 text-[11px]">
                        <div>
                            <span className="text-ink-secondary">Ticket: </span>
                            <span className="font-medium text-ink">
                                {registration.ticket_type_name || registration.ticketType?.name || 'General Admission'}
                            </span>
                        </div>
                        <div>
                            <span className="text-ink-secondary">Reference: </span>
                            <span className="font-mono text-ink">
                                {registration.offline_payment_reference || registration.payment_reference || '—'}
                            </span>
                        </div>
                    </div>

                    {registration.offline_payment_notes && (
                        <div className="pt-1 text-[11px]">
                            <span className="text-ink-secondary">Attendee Notes: </span>
                            <span className="italic text-ink">
                                &ldquo;{registration.offline_payment_notes}&rdquo;
                            </span>
                        </div>
                    )}
                </div>

                {/* Payment Proof Slip */}
                <div className="rounded-lg border border-border p-3.5 space-y-2">
                    <div className="text-xs font-semibold text-ink flex items-center justify-between">
                        <span>Submitted Proof Slip</span>
                        {proofUrl && (
                            <a
                                href={proofUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-xs text-accent hover:underline"
                            >
                                <Download className="h-3.5 w-3.5" />
                                <span>Download File</span>
                            </a>
                        )}
                    </div>

                    {proofUrl ? (
                        <div className="flex items-center gap-3 p-2 bg-surface-subtle rounded border border-border text-xs">
                            <FileText className="h-6 w-6 text-accent shrink-0" />
                            <div className="flex-1 truncate">
                                <a
                                    href={proofUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-medium text-ink hover:underline truncate block"
                                >
                                    View Payment Slip
                                </a>
                                <span className="text-[11px] text-ink-secondary">
                                    Click to inspect deposit receipt in new tab
                                </span>
                            </div>
                        </div>
                    ) : (
                        <p className="text-xs text-ink-secondary italic">
                            No proof slip has been uploaded yet by the attendee.
                        </p>
                    )}
                </div>

                {/* Action View or Reject Mode */}
                {mode === 'view' ? (
                    <div className="flex items-center justify-between gap-2 pt-2 border-t border-border">
                        <Button
                            type="button"
                            onClick={() => setMode('reject')}
                            disabled={submitting}
                        >
                            Reject Slip
                        </Button>
                        <div className="flex items-center gap-2">
                            <Button type="button" onClick={onClose} disabled={submitting}>
                                Close
                            </Button>
                            <Button
                                type="button"
                                variant="primary"
                                onClick={handleApprove}
                                disabled={submitting}
                            >
                                {submitting ? (
                                    <>
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        <span>Approving…</span>
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-3.5 w-3.5" />
                                        <span>Approve &amp; Issue Ticket</span>
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={handleReject} className="space-y-3 pt-2 border-t border-border">
                        <div className="space-y-1">
                            <label
                                htmlFor="rejection_reason"
                                className="block text-xs font-semibold text-ink"
                            >
                                Rejection Reason / Review Note
                            </label>
                            <textarea
                                id="rejection_reason"
                                rows={3}
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                placeholder="Explain why the proof was rejected (e.g. amount mismatch, unclear receipt, unverified transfer)..."
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink placeholder-ink-secondary/60 focus:border-accent focus:outline-hidden focus:ring-1 focus:ring-accent"
                                required
                            />
                            <p className="text-[11px] text-ink-secondary">
                                This message will be emailed to the attendee along with a link to re-upload their proof slip.
                            </p>
                        </div>

                        <div className="flex items-center justify-end gap-2 pt-2">
                            <Button
                                type="button"
                                onClick={() => setMode('view')}
                                disabled={submitting}
                            >
                                Back
                            </Button>
                            <Button
                                type="submit"
                                variant="primary"
                                disabled={submitting}
                            >
                                {submitting ? (
                                    <>
                                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        <span>Rejecting…</span>
                                    </>
                                ) : (
                                    <>
                                        <X className="h-3.5 w-3.5" />
                                        <span>Confirm Rejection</span>
                                    </>
                                )}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </Modal>
    );
}
