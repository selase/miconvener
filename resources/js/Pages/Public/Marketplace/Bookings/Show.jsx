import { Head, Link, router } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import {
    Calendar,
    Clock,
    Users,
    MapPin,
    ShieldCheck,
    CheckCircle2,
    AlertCircle,
    FileText,
    CreditCard,
    Building2,
    Phone,
    Mail,
    ArrowLeft,
    Download,
    ExternalLink,
} from 'lucide-react';

export default function BookingShow({ booking }) {
    const formatCurrency = (pesewas) => {
        if (!pesewas && pesewas !== 0) return 'GHS 0.00';
        return `GHS ${(pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
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

    const generateIcsFile = () => {
        const startDate = new Date(booking.starts_at)
            .toISOString()
            .replace(/-|:|\.\d+/g, '');
        const endDate = new Date(booking.ends_at)
            .toISOString()
            .replace(/-|:|\.\d+/g, '');

        const icsContent = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MiConvener//Venue Booking//EN',
            'BEGIN:VEVENT',
            `UID:${booking.booking_reference}@miconvener.com`,
            `DTSTAMP:${startDate}`,
            `DTSTART:${startDate}`,
            `DTEND:${endDate}`,
            `SUMMARY:${booking.event_type} at ${booking.venue.title}`,
            `DESCRIPTION:Venue Booking Reference: ${booking.booking_reference}\\nHost: ${booking.shop.name}`,
            `LOCATION:${booking.shop.address}, ${booking.shop.city}`,
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ].join('\r\n');

        const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
        const link = document.createElement('a');
        link.href = window.URL.createObjectURL(blob);
        link.setAttribute('download', `${booking.booking_reference}.ics`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const isConfirmed = booking.status === 'confirmed' || booking.payment_status === 'deposit_paid';
    const isPendingQuote = booking.status === 'pending_quote';

    return (
        <MarketplaceLayout>
            <Head title={`Booking ${booking.booking_reference} | MiConvener Marketplace`} />

            <div className="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
                {/* Back Link */}
                <div className="mb-6">
                    <Link
                        href={`/marketplace/venues/${booking.venue.slug}`}
                        className="inline-flex items-center gap-1.5 text-xs font-medium text-ink-secondary hover:text-ink transition-colors"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        <span>Back to {booking.venue.title}</span>
                    </Link>
                </div>

                {/* Status Hero Card */}
                <div className="rounded-2xl border border-border bg-surface p-6 sm:p-8 shadow-sm space-y-6">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-6">
                        <div>
                            <div className="flex items-center gap-2 mb-1">
                                {isConfirmed ? (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                        <CheckCircle2 className="h-3.5 w-3.5" />
                                        <span>Confirmed & Calendar Locked</span>
                                    </span>
                                ) : isPendingQuote ? (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-blue-500/10 px-3 py-1 text-xs font-semibold text-blue-600 dark:text-blue-400">
                                        <Clock className="h-3.5 w-3.5" />
                                        <span>Inquiry Submitted to Host</span>
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-600 dark:text-amber-400">
                                        <AlertCircle className="h-3.5 w-3.5" />
                                        <span>Pending Payment</span>
                                    </span>
                                )}
                                <span className="text-xs text-ink-secondary">Ref:</span>
                                <span className="font-mono text-xs font-bold text-ink">
                                    {booking.booking_reference}
                                </span>
                            </div>

                            <h1 className="text-2xl font-bold text-ink tracking-tight">
                                {booking.event_type}
                            </h1>
                            <p className="text-xs text-ink-secondary mt-1">
                                Booked for {booking.planner_name}{' '}
                                {booking.planner_company ? `(${booking.planner_company})` : ''}
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={generateIcsFile}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-2 text-xs font-medium text-ink hover:bg-surface-hover transition-colors shadow-2xs"
                            >
                                <Download className="h-3.5 w-3.5 text-accent" />
                                <span>Add to Calendar (.ics)</span>
                            </button>
                        </div>
                    </div>

                    {/* Pending Deposit Action Banner */}
                    {!isConfirmed && booking.status === 'pending_payment' && booking.deposit_required_pesewas > 0 && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div className="space-y-1">
                                <div className="text-sm font-semibold text-amber-900 dark:text-amber-200">
                                    Reservation Awaiting Deposit Payment
                                </div>
                                <p className="text-xs text-ink-secondary">
                                    Pay the required reservation deposit of{' '}
                                    <span className="font-semibold text-ink">
                                        {formatCurrency(booking.deposit_required_pesewas)}
                                    </span>{' '}
                                    to lock the venue calendar and guarantee your booking.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(
                                        route(
                                            'marketplace.bookings.checkout',
                                            booking.booking_reference
                                        )
                                    )
                                }
                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-5 py-2.5 text-xs font-semibold text-white shadow-sm hover:opacity-95 transition-all shrink-0 cursor-pointer"
                            >
                                <CreditCard className="h-4 w-4" />
                                <span>
                                    Pay Deposit ({formatCurrency(booking.deposit_required_pesewas)})
                                </span>
                            </button>
                        </div>
                    )}

                    {/* Schedule & Space Grid */}
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                        {/* Event Schedule */}
                        <div className="space-y-4 rounded-xl border border-border bg-canvas/60 p-5 text-xs">
                            <div className="font-semibold text-ink flex items-center gap-2">
                                <Calendar className="h-4 w-4 text-accent" />
                                <span>Event Schedule</span>
                            </div>

                            <div className="space-y-2 text-ink-secondary">
                                <div>
                                    <span className="font-medium text-ink">Date: </span>
                                    {formatDate(booking.starts_at)}
                                </div>
                                <div>
                                    <span className="font-medium text-ink">Time Window: </span>
                                    {formatTime(booking.starts_at)} — {formatTime(booking.ends_at)}
                                </div>
                                <div>
                                    <span className="font-medium text-ink">Duration: </span>
                                    {booking.duration_units}{' '}
                                    {booking.time_slot_type === 'hourly' ? 'hours' : 'days'}
                                </div>
                                <div>
                                    <span className="font-medium text-ink">Layout & Guests: </span>
                                    {booking.guest_count} attendees ({booking.layout_style} layout)
                                </div>
                            </div>
                        </div>

                        {/* Venue & Host Info */}
                        <div className="space-y-4 rounded-xl border border-border bg-canvas/60 p-5 text-xs">
                            <div className="font-semibold text-ink flex items-center gap-2">
                                <Building2 className="h-4 w-4 text-accent" />
                                <span>Venue & Host</span>
                            </div>

                            <div className="space-y-2 text-ink-secondary">
                                <div className="font-semibold text-ink">{booking.venue.title}</div>
                                <div className="flex items-start gap-1.5">
                                    <MapPin className="h-3.5 w-3.5 text-accent shrink-0 mt-0.5" />
                                    <span>
                                        {booking.shop.address}, {booking.shop.city},{' '}
                                        {booking.shop.region}
                                    </span>
                                </div>
                                <div className="pt-1 flex flex-wrap gap-4 text-ink">
                                    {booking.shop.phone && (
                                        <div className="flex items-center gap-1">
                                            <Phone className="h-3 w-3 text-accent" />
                                            <span>{booking.shop.phone}</span>
                                        </div>
                                    )}
                                    {booking.shop.email && (
                                        <div className="flex items-center gap-1">
                                            <Mail className="h-3 w-3 text-accent" />
                                            <span>{booking.shop.email}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Financial Receipt & Payment Summary */}
                    <div className="rounded-xl border border-border bg-surface p-5 space-y-4 text-xs">
                        <div className="flex items-center justify-between border-b border-border pb-3">
                            <div className="flex items-center gap-2 font-semibold text-ink">
                                <CreditCard className="h-4 w-4 text-accent" />
                                <span>Financial Summary & Receipt</span>
                            </div>
                            <span className="rounded bg-accent/10 px-2.5 py-0.5 font-mono text-[11px] font-semibold text-accent">
                                Paystack Secure
                            </span>
                        </div>

                        <div className="space-y-2 text-ink-secondary">
                            <div className="flex justify-between">
                                <span>Base Rental Fee:</span>
                                <span className="font-medium text-ink">
                                    {formatCurrency(booking.rental_amount_pesewas)}
                                </span>
                            </div>
                            {booking.security_deposit_pesewas > 0 && (
                                <div className="flex justify-between">
                                    <span>Refundable Security Deposit:</span>
                                    <span className="font-medium text-ink">
                                        {formatCurrency(booking.security_deposit_pesewas)}
                                    </span>
                                </div>
                            )}
                            <div className="flex justify-between border-t border-border pt-2 text-sm font-bold text-ink">
                                <span>Total Estimated Amount:</span>
                                <span>{formatCurrency(booking.total_amount_pesewas)}</span>
                            </div>
                            <div className="flex justify-between text-emerald-600 dark:text-emerald-400 font-semibold pt-1">
                                <span>Amount Paid / Captured:</span>
                                <span>{formatCurrency(booking.amount_paid_pesewas)}</span>
                            </div>
                            {booking.paystack_reference && (
                                <div className="flex justify-between text-[11px] text-ink-tertiary pt-1">
                                    <span>Payment Reference:</span>
                                    <span className="font-mono">{booking.paystack_reference}</span>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Special Requests & Contract Terms */}
                    {booking.special_requests && (
                        <div className="rounded-xl border border-border bg-canvas/40 p-5 space-y-2 text-xs">
                            <div className="font-semibold text-ink">
                                Logistics & Special Requirements
                            </div>
                            <p className="text-ink-secondary leading-relaxed whitespace-pre-line">
                                {booking.special_requests}
                            </p>
                        </div>
                    )}

                    {booking.contract_terms_snapshot && (
                        <div className="rounded-xl border border-border bg-canvas/40 p-5 space-y-3 text-xs">
                            <div className="flex items-center gap-2 font-semibold text-ink">
                                <FileText className="h-4 w-4 text-accent" />
                                <span>Agreed Rental Contract & House Rules</span>
                            </div>
                            <p className="text-[11px] text-ink-secondary">
                                Confirmed with digital agreement snapshot on{' '}
                                {formatDate(booking.contract_agreed_at)}. Governed by MiConvener
                                Marketplace Rental Terms v2026.1.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </MarketplaceLayout>
    );
}
