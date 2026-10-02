import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import {
    Inbox,
    Calendar,
    Users,
    Clock,
    Building2,
    ShieldCheck,
    CreditCard,
    ArrowLeft,
    CheckCircle2,
    XCircle,
    FileText,
    Send,
    Phone,
    Mail,
    X,
} from 'lucide-react';

const STATUS_MAP = {
    pending_quote: 'pending',
    pending_payment: 'pending',
    confirmed: 'success',
    completed: 'neutral',
    rejected: 'failed',
    cancelled: 'neutral',
};

export default function InquiryShow({ shop, inquiry }) {
    const [quoteModalOpen, setQuoteModalOpen] = useState(false);
    const [declineModalOpen, setDeclineModalOpen] = useState(false);
    const [holdModalOpen, setHoldModalOpen] = useState(false);

    const [rentalGhs, setRentalGhs] = useState(
        inquiry.rental_amount_pesewas ? inquiry.rental_amount_pesewas / 100 : 0
    );
    const [depositGhs, setDepositGhs] = useState(
        inquiry.security_deposit_pesewas ? inquiry.security_deposit_pesewas / 100 : 0
    );
    const [requiredDepositGhs, setRequiredDepositGhs] = useState(
        inquiry.deposit_required_pesewas
            ? inquiry.deposit_required_pesewas / 100
            : (inquiry.rental_amount_pesewas ? inquiry.rental_amount_pesewas / 100 : 0) * 0.25
    );
    const [hostNotes, setHostNotes] = useState(inquiry.host_notes || '');
    const [declineReason, setDeclineReason] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const formatCurrency = (pesewas) => {
        if (!pesewas && pesewas !== 0) return 'GHS 0.00';
        return `GHS ${(pesewas / 100).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    const formatTime = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        });
    };

    const handleSendQuote = (e) => {
        e.preventDefault();
        setSubmitting(true);
        router.post(
            route('tenant.venue.inquiries.quote', { inquiry: inquiry.id }),
            {
                rental_amount_ghs: rentalGhs,
                security_deposit_ghs: depositGhs,
                deposit_required_ghs: requiredDepositGhs,
                host_notes: hostNotes,
            },
            {
                onFinish: () => {
                    setSubmitting(false);
                    setQuoteModalOpen(false);
                },
            }
        );
    };

    const handleHold = () => {
        router.post(
            route('tenant.venue.inquiries.hold', { inquiry: inquiry.id }),
            {},
            {
                onFinish: () => setHoldModalOpen(false),
            }
        );
    };

    const handleDecline = () => {
        router.post(
            route('tenant.venue.inquiries.reject', { inquiry: inquiry.id }),
            { reason: declineReason },
            {
                onFinish: () => setDeclineModalOpen(false),
            }
        );
    };

    return (
        <ConsoleLayout>
            <div className="space-y-6">
                <PageHeader
                    title={`Lead Dossier: ${inquiry.booking_reference}`}
                    actions={
                        <div className="flex items-center gap-3">
                            <Button
                                href={route('tenant.venue.inquiries.index')}
                                variant="default"
                                icon={ArrowLeft}
                            >
                                Back to Inquiries
                            </Button>
                        </div>
                    }
                />

                {/* Lead Header Summary */}
                <div className="rounded-xl border border-border bg-surface p-6 shadow-xs space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-5">
                        <div>
                            <div className="flex items-center gap-2 mb-1">
                                <StatusPill status={STATUS_MAP[inquiry.status] || 'neutral'}>
                                    {inquiry.status.replace('_', ' ')}
                                </StatusPill>
                                <span className="font-mono text-xs font-bold text-ink">
                                    {inquiry.booking_reference}
                                </span>
                            </div>
                            <h2 className="text-xl font-bold text-ink tracking-tight">
                                {inquiry.event_type}
                            </h2>
                            <p className="text-xs text-ink-secondary mt-1">
                                For {inquiry.planner_name}{' '}
                                {inquiry.planner_company ? `• ${inquiry.planner_company}` : ''}
                            </p>
                        </div>

                        {/* Host Action Buttons */}
                        <div className="flex flex-wrap items-center gap-2">
                            {inquiry.status === 'pending_quote' && (
                                <Button
                                    type="button"
                                    onClick={() => setQuoteModalOpen(true)}
                                    variant="primary"
                                    icon={Send}
                                >
                                    Send Custom Quote
                                </Button>
                            )}

                            {inquiry.status !== 'confirmed' && inquiry.status !== 'rejected' && (
                                <Button
                                    type="button"
                                    onClick={() => setHoldModalOpen(true)}
                                    variant="default"
                                    icon={Clock}
                                >
                                    Place 48h Hold
                                </Button>
                            )}

                            {inquiry.status !== 'rejected' && (
                                <Button
                                    type="button"
                                    onClick={() => setDeclineModalOpen(true)}
                                    variant="default"
                                    className="border-danger-fg/40 text-danger-fg hover:bg-danger-bg"
                                    icon={XCircle}
                                >
                                    Decline Lead
                                </Button>
                            )}
                        </div>
                    </div>

                    {/* Specifications Grid */}
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {/* Event Details */}
                        <div className="rounded-xl border border-border bg-canvas/40 p-4 space-y-3 text-xs">
                            <div className="font-semibold text-ink flex items-center gap-1.5">
                                <Calendar className="h-4 w-4 text-accent" />
                                <span>Event Schedule</span>
                            </div>
                            <div className="space-y-1.5 text-ink-secondary">
                                <div>
                                    <span className="font-medium text-ink">Date: </span>
                                    {formatDate(inquiry.starts_at)}
                                </div>
                                <div>
                                    <span className="font-medium text-ink">Time Window: </span>
                                    {formatTime(inquiry.starts_at)} — {formatTime(inquiry.ends_at)}
                                </div>
                                <div>
                                    <span className="font-medium text-ink">Duration: </span>
                                    {inquiry.duration_units}{' '}
                                    {inquiry.time_slot_type === 'hourly' ? 'hours' : 'days'}
                                </div>
                                <div>
                                    <span className="font-medium text-ink">Guests & Layout: </span>
                                    {inquiry.guest_count} attendees ({inquiry.layout_style} layout)
                                </div>
                            </div>
                        </div>

                        {/* Planner Contact */}
                        <div className="rounded-xl border border-border bg-canvas/40 p-4 space-y-3 text-xs">
                            <div className="font-semibold text-ink flex items-center gap-1.5">
                                <Users className="h-4 w-4 text-accent" />
                                <span>Organizer Contact</span>
                            </div>
                            <div className="space-y-1.5 text-ink-secondary">
                                <div className="font-medium text-ink flex items-center gap-1.5">
                                    <span>{inquiry.planner_name}</span>
                                    {inquiry.planner_tenant && (
                                        <span className="rounded bg-accent/10 px-1.5 py-0.5 text-[9px] font-bold text-accent">
                                            Agency Tier
                                        </span>
                                    )}
                                </div>
                                <div className="flex items-center gap-1.5">
                                    <Mail className="h-3 w-3 text-accent" />
                                    <span>{inquiry.planner_email}</span>
                                </div>
                                {inquiry.planner_phone && (
                                    <div className="flex items-center gap-1.5">
                                        <Phone className="h-3 w-3 text-accent" />
                                        <span>{inquiry.planner_phone}</span>
                                    </div>
                                )}
                                {inquiry.planner_company && (
                                    <div>
                                        <span className="font-medium text-ink">Company: </span>
                                        {inquiry.planner_company}
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Space & Financial Status */}
                        <div className="rounded-xl border border-border bg-canvas/40 p-4 space-y-3 text-xs">
                            <div className="font-semibold text-ink flex items-center gap-1.5">
                                <CreditCard className="h-4 w-4 text-accent" />
                                <span>Financial Quote</span>
                            </div>
                            <div className="space-y-1.5 text-ink-secondary">
                                <div className="flex justify-between">
                                    <span>Base Rental:</span>
                                    <span className="font-medium text-ink">
                                        {formatCurrency(inquiry.rental_amount_pesewas)}
                                    </span>
                                </div>
                                {inquiry.security_deposit_pesewas > 0 && (
                                    <div className="flex justify-between">
                                        <span>Security Deposit:</span>
                                        <span className="font-medium text-ink">
                                            {formatCurrency(inquiry.security_deposit_pesewas)}
                                        </span>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-border pt-1 font-bold text-ink">
                                    <span>Total Value:</span>
                                    <span>{formatCurrency(inquiry.total_amount_pesewas)}</span>
                                </div>
                                <div className="flex justify-between text-accent font-semibold pt-1">
                                    <span>Deposit Captured:</span>
                                    <span>{formatCurrency(inquiry.amount_paid_pesewas)}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Special Logistics Notes */}
                    {inquiry.special_requests && (
                        <div className="rounded-xl border border-border bg-surface p-4 space-y-2 text-xs">
                            <div className="font-semibold text-ink">
                                Planner's Logistics & Equipment Requests
                            </div>
                            <p className="text-ink-secondary leading-relaxed whitespace-pre-line">
                                {inquiry.special_requests}
                            </p>
                        </div>
                    )}

                    {/* Host Notes / Decline Reason */}
                    {inquiry.host_notes && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 space-y-1 text-xs">
                            <div className="font-semibold text-amber-700 dark:text-amber-400">
                                Host Feedback / Notes to Planner
                            </div>
                            <p className="text-ink-secondary leading-relaxed whitespace-pre-line">
                                {inquiry.host_notes}
                            </p>
                        </div>
                    )}
                </div>

                {/* Send Custom Quote Modal */}
                {quoteModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                        <div className="w-full max-w-lg rounded-2xl border border-border bg-surface p-6 shadow-2xl space-y-5 text-xs">
                            <div className="flex items-center justify-between border-b border-border pb-3">
                                <div>
                                    <h3 className="text-sm font-bold text-ink">
                                        Formulate Custom Quotation
                                    </h3>
                                    <p className="text-[11px] text-ink-secondary mt-0.5">
                                        Ref: {inquiry.booking_reference} • {inquiry.listing?.title}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setQuoteModalOpen(false)}
                                    className="text-ink-secondary hover:text-ink"
                                >
                                    <X className="h-5 w-5" />
                                </button>
                            </div>

                            <form onSubmit={handleSendQuote} className="space-y-4">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-ink-secondary font-medium mb-1">
                                            Base Rental Fee (GHS)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={rentalGhs}
                                            onChange={(e) => setRentalGhs(parseFloat(e.target.value) || 0)}
                                            required
                                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-ink-secondary font-medium mb-1">
                                            Security Deposit (GHS)
                                        </label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={depositGhs}
                                            onChange={(e) => setDepositGhs(parseFloat(e.target.value) || 0)}
                                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-ink-secondary font-medium mb-1">
                                        Reservation Deposit to Lock Calendar (GHS)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={requiredDepositGhs}
                                        onChange={(e) => setRequiredDepositGhs(parseFloat(e.target.value) || 0)}
                                        required
                                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                    />
                                    <p className="text-[10px] text-ink-tertiary mt-1">
                                        The planner will receive a direct Paystack checkout link to pay this deposit.
                                    </p>
                                </div>

                                <div>
                                    <label className="block text-ink-secondary font-medium mb-1">
                                        Host Notes & Special Inclusions (Optional)
                                    </label>
                                    <textarea
                                        rows="3"
                                        placeholder="e.g. Includes AV technician, 3-phase power generator backup, and complimentary bridal room."
                                        value={hostNotes}
                                        onChange={(e) => setHostNotes(e.target.value)}
                                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                    />
                                </div>

                                <div className="flex items-center justify-end gap-2 border-t border-border pt-4">
                                    <Button
                                        type="button"
                                        variant="default"
                                        onClick={() => setQuoteModalOpen(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        disabled={submitting}
                                        icon={Send}
                                    >
                                        {submitting ? 'Sending...' : 'Send Quote to Planner'}
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* Decline Modal */}
                {declineModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                        <div className="w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-2xl space-y-4 text-xs">
                            <h3 className="text-sm font-bold text-ink">
                                Decline Lead {inquiry.booking_reference}
                            </h3>
                            <p className="text-ink-secondary leading-relaxed">
                                Please specify a reason for declining this request. The planner will be notified.
                            </p>
                            <textarea
                                rows="3"
                                placeholder="Space unavailable on this date, renovation ongoing..."
                                value={declineReason}
                                onChange={(e) => setDeclineReason(e.target.value)}
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                            />
                            <div className="flex items-center justify-end gap-2 pt-2">
                                <Button
                                    type="button"
                                    variant="default"
                                    onClick={() => setDeclineModalOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    variant="default"
                                    className="border-danger-fg/40 text-danger-fg hover:bg-danger-bg"
                                    onClick={handleDecline}
                                >
                                    Confirm Decline
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                {/* 48h Hold Confirm Modal */}
                <ConfirmModal
                    open={holdModalOpen}
                    title="Place 48-Hour Tentative Hold?"
                    description="This will mark the booking as pending payment and place a temporary hold on the date for 48 hours to prevent competing reservations."
                    confirmLabel="Place on Hold"
                    onConfirm={handleHold}
                    onClose={() => setHoldModalOpen(false)}
                />
            </div>
        </ConsoleLayout>
    );
}
