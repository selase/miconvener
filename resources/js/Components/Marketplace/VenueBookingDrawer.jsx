import { useState, useEffect, useCallback } from 'react';
import { router } from '@inertiajs/react';
import {
    Calendar,
    Clock,
    Users,
    ShieldCheck,
    CheckCircle2,
    AlertCircle,
    X,
    FileText,
    CreditCard,
    Building2,
    Sparkles,
} from 'lucide-react';

export default function VenueBookingDrawer({ isOpen, onClose, venue }) {
    const isPriceOnRequest = venue.price_visibility === 'on_request' || !venue.rental_price_pesewas;

    // Tomorrow's date formatted as YYYY-MM-DD
    const tomorrowStr = () => {
        const d = new Date();
        d.setDate(d.getDate() + 1);
        return d.toISOString().split('T')[0];
    };

    const [dateMode, setDateMode] = useState('single'); // 'single' | 'multi'
    const [startDate, setStartDate] = useState(tomorrowStr());
    const [endDate, setEndDate] = useState(tomorrowStr());
    const [startTime, setStartTime] = useState('09:00');
    const [endTime, setEndTime] = useState('17:00');
    const [guestCount, setGuestCount] = useState(50);
    const [layoutStyle, setLayoutStyle] = useState('banquet');
    const [eventType, setEventType] = useState('Corporate Event');
    const [plannerName, setPlannerName] = useState('');
    const [plannerEmail, setPlannerEmail] = useState('');
    const [plannerPhone, setPlannerPhone] = useState('');
    const [plannerCompany, setPlannerCompany] = useState('');
    const [specialRequests, setSpecialRequests] = useState('');
    const [agreedToTerms, setAgreedToTerms] = useState(false);

    const [checking, setChecking] = useState(false);
    const [availability, setAvailability] = useState({
        available: true,
        pricing: null,
    });
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    const formatCurrency = (pesewas) => {
        if (!pesewas && pesewas !== 0) return 'GHS 0.00';
        return `GHS ${(pesewas / 100).toLocaleString('en-GH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };

    // Calculate startsAt and endsAt strings in ISO / standard format
    const getTimestamps = useCallback(() => {
        const start = `${startDate}T${startTime}:00`;
        const end = dateMode === 'multi' ? `${endDate}T${endTime}:00` : `${startDate}T${endTime}:00`;
        return { start, end };
    }, [startDate, endDate, startTime, endTime, dateMode]);

    // Live Slot & Pricing Check
    useEffect(() => {
        if (!isOpen) return;

        const { start, end } = getTimestamps();
        if (new Date(end) <= new Date(start)) {
            setAvailability({
                available: false,
                pricing: null,
                errorMessage: 'End time must be after start time.',
            });
            return;
        }

        const timer = setTimeout(async () => {
            setChecking(true);
            try {
                const csrfToken =
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                    '';
                const res = await fetch(`/marketplace/venues/${venue.slug}/check-availability`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        starts_at: start,
                        ends_at: end,
                        guest_count: parseInt(guestCount, 10) || 1,
                        layout_style: layoutStyle,
                    }),
                });

                if (res.ok) {
                    const data = await res.json();
                    setAvailability(data);
                }
            } catch (err) {
                console.error('Availability check failed:', err);
            } finally {
                setChecking(false);
            }
        }, 300);

        return () => clearTimeout(timer);
    }, [isOpen, getTimestamps, guestCount, layoutStyle, venue.slug]);

    const handleSubmit = (e) => {
        e.preventDefault();
        setErrors({});

        if (!agreedToTerms) {
            setErrors({ agreed_to_terms: 'You must agree to the venue terms and policies.' });
            return;
        }

        const { start, end } = getTimestamps();

        setSubmitting(true);
        router.post(
            route('marketplace.venues.book', venue.slug),
            {
                starts_at: start.replace('T', ' ').substring(0, 16),
                ends_at: end.replace('T', ' ').substring(0, 16),
                guest_count: parseInt(guestCount, 10) || 1,
                layout_style: layoutStyle,
                event_type: eventType,
                planner_name: plannerName,
                planner_email: plannerEmail,
                planner_phone: plannerPhone,
                planner_company: plannerCompany,
                special_requests: specialRequests,
                agreed_to_terms: agreedToTerms,
                request_quote_only: isPriceOnRequest,
            },
            {
                onError: (errs) => {
                    setErrors(errs);
                    setSubmitting(false);
                },
                onFinish: () => setSubmitting(false),
            }
        );
    };

    if (!isOpen) return null;

    const pricing = availability.pricing;
    const capacityLimit = venue.capacity_breakdown?.[layoutStyle] || null;

    return (
        <div className="fixed inset-0 z-50 overflow-hidden bg-black/60 backdrop-blur-xs flex justify-end transition-opacity">
            <div className="relative w-full max-w-xl bg-surface border-l border-border h-full flex flex-col shadow-2xl overflow-y-auto">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-border p-5 sticky top-0 bg-surface/95 backdrop-blur-xs z-10">
                    <div>
                        <div className="text-[11px] font-semibold text-accent uppercase tracking-wider">
                            Venue Booking & Reservation
                        </div>
                        <h2 className="text-base font-bold text-ink">{venue.title}</h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1.5 text-ink-secondary hover:bg-surface-hover hover:text-ink transition-colors"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-6 space-y-6 flex-1 text-xs">
                    {/* Mode Toggle: Single Day vs Multi-Day */}
                    <div className="flex rounded-lg border border-border bg-canvas p-1">
                        <button
                            type="button"
                            onClick={() => setDateMode('single')}
                            className={`flex-1 rounded-md py-1.5 text-center font-medium transition-colors ${
                                dateMode === 'single'
                                    ? 'bg-surface text-ink font-semibold shadow-2xs'
                                    : 'text-ink-secondary hover:text-ink'
                            }`}
                        >
                            Single Day Booking
                        </button>
                        <button
                            type="button"
                            onClick={() => setDateMode('multi')}
                            className={`flex-1 rounded-md py-1.5 text-center font-medium transition-colors ${
                                dateMode === 'multi'
                                    ? 'bg-surface text-ink font-semibold shadow-2xs'
                                    : 'text-ink-secondary hover:text-ink'
                            }`}
                        >
                            Multi-Day Event
                        </button>
                    </div>

                    {/* Preferred Date & Time Selection */}
                    <div className="space-y-4 rounded-xl border border-border bg-canvas/40 p-4">
                        <div className="flex items-center gap-1.5 font-semibold text-ink text-sm">
                            <Clock className="h-4 w-4 text-accent" />
                            <span>Preferred Date & Time Schedule</span>
                        </div>

                        {dateMode === 'single' ? (
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    Event Date
                                </label>
                                <input
                                    type="date"
                                    min={tomorrowStr()}
                                    value={startDate}
                                    onChange={(e) => {
                                        setStartDate(e.target.value);
                                        setEndDate(e.target.value);
                                    }}
                                    required
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-ink-secondary font-medium mb-1">
                                        Start Date
                                    </label>
                                    <input
                                        type="date"
                                        min={tomorrowStr()}
                                        value={startDate}
                                        onChange={(e) => setStartDate(e.target.value)}
                                        required
                                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                    />
                                </div>
                                <div>
                                    <label className="block text-ink-secondary font-medium mb-1">
                                        End Date
                                    </label>
                                    <input
                                        type="date"
                                        min={startDate}
                                        value={endDate}
                                        onChange={(e) => setEndDate(e.target.value)}
                                        required
                                        className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                    />
                                </div>
                            </div>
                        )}

                        {/* Preferred Time Slots */}
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    Start Time
                                </label>
                                <input
                                    type="time"
                                    value={startTime}
                                    onChange={(e) => setStartTime(e.target.value)}
                                    required
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    End Time
                                </label>
                                <input
                                    type="time"
                                    value={endTime}
                                    onChange={(e) => setEndTime(e.target.value)}
                                    required
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                        </div>

                        {/* Live Slot Availability Feedback */}
                        {checking ? (
                            <div className="text-[11px] text-ink-secondary italic">
                                Checking calendar availability...
                            </div>
                        ) : availability.available ? (
                            <div className="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400 font-medium text-[11px]">
                                <CheckCircle2 className="h-3.5 w-3.5" />
                                <span>Date and time slot available for reservation!</span>
                            </div>
                        ) : (
                            <div className="flex items-center gap-1.5 text-rose-600 dark:text-rose-400 font-medium text-[11px]">
                                <AlertCircle className="h-3.5 w-3.5" />
                                <span>
                                    {availability.errorMessage ||
                                        'Selected time is already booked or held. Please select another slot.'}
                                </span>
                            </div>
                        )}
                    </div>

                    {/* Seating Layout & Expected Guests */}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-ink-secondary font-medium mb-1">
                                Seating Arrangement
                            </label>
                            <select
                                value={layoutStyle}
                                onChange={(e) => setLayoutStyle(e.target.value)}
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                            >
                                <option value="banquet">Banquet / Gala</option>
                                <option value="theater">Theater / Plenary</option>
                                <option value="cocktail">Cocktail / Standing</option>
                                <option value="classroom">Classroom / Seminar</option>
                                <option value="boardroom">Boardroom</option>
                            </select>
                        </div>
                        <div>
                            <label className="block text-ink-secondary font-medium mb-1">
                                Attendee Count{' '}
                                {capacityLimit && (
                                    <span className="text-[10px] text-ink-tertiary">
                                        (Max: {capacityLimit})
                                    </span>
                                )}
                            </label>
                            <input
                                type="number"
                                min="1"
                                max={capacityLimit ? capacityLimit * 1.2 : 5000}
                                value={guestCount}
                                onChange={(e) => setGuestCount(e.target.value)}
                                required
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                            />
                        </div>
                    </div>

                    {/* Planner Contact Information */}
                    <div className="space-y-3 border-t border-border pt-4">
                        <div className="font-semibold text-ink text-sm">
                            Event & Organizer Details
                        </div>

                        <div>
                            <label className="block text-ink-secondary font-medium mb-1">
                                Event Category / Type
                            </label>
                            <input
                                type="text"
                                placeholder="e.g. Annual General Meeting, Tech Summit, Wedding"
                                value={eventType}
                                onChange={(e) => setEventType(e.target.value)}
                                required
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    Full Name
                                </label>
                                <input
                                    type="text"
                                    placeholder="Your full name"
                                    value={plannerName}
                                    onChange={(e) => setPlannerName(e.target.value)}
                                    required
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    Email Address
                                </label>
                                <input
                                    type="email"
                                    placeholder="name@company.com"
                                    value={plannerEmail}
                                    onChange={(e) => setPlannerEmail(e.target.value)}
                                    required
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    Phone Number
                                </label>
                                <input
                                    type="tel"
                                    placeholder="+233 24 000 0000"
                                    value={plannerPhone}
                                    onChange={(e) => setPlannerPhone(e.target.value)}
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="block text-ink-secondary font-medium mb-1">
                                    Organization / Agency (Optional)
                                </label>
                                <input
                                    type="text"
                                    placeholder="Company name"
                                    value={plannerCompany}
                                    onChange={(e) => setPlannerCompany(e.target.value)}
                                    className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                                />
                            </div>
                        </div>

                        <div>
                            <label className="block text-ink-secondary font-medium mb-1">
                                Special Logistics or Equipment Requests (Optional)
                            </label>
                            <textarea
                                rows="2"
                                placeholder="Stage setup, lighting, sound engineer, 3-phase power, outside catering..."
                                value={specialRequests}
                                onChange={(e) => setSpecialRequests(e.target.value)}
                                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink text-xs focus:border-accent focus:outline-none"
                            />
                        </div>
                    </div>

                    {/* Cost & Deposit Breakdown */}
                    {!isPriceOnRequest && pricing && (
                        <div className="rounded-xl border border-border bg-surface p-4 space-y-2">
                            <div className="font-semibold text-ink flex items-center justify-between">
                                <span>Estimated Pricing Breakdown</span>
                                <span className="font-mono text-[10px] text-accent font-normal">
                                    {pricing.duration_units}{' '}
                                    {pricing.time_slot_type === 'hourly' ? 'hours' : 'days'}
                                </span>
                            </div>

                            <div className="space-y-1.5 text-ink-secondary">
                                <div className="flex justify-between">
                                    <span>Base Rental Fee:</span>
                                    <span className="font-medium text-ink">
                                        {formatCurrency(pricing.rental_amount_pesewas)}
                                    </span>
                                </div>
                                {pricing.security_deposit_pesewas > 0 && (
                                    <div className="flex justify-between">
                                        <span>Refundable Security Deposit:</span>
                                        <span className="font-medium text-ink">
                                            {formatCurrency(pricing.security_deposit_pesewas)}
                                        </span>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-border pt-1 font-bold text-ink">
                                    <span>Total Booking Value:</span>
                                    <span>{formatCurrency(pricing.total_amount_pesewas)}</span>
                                </div>
                                <div className="flex justify-between text-accent font-semibold pt-1">
                                    <span>Reservation Deposit Due Now:</span>
                                    <span>{formatCurrency(pricing.deposit_required_pesewas)}</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Contract Terms Agreement */}
                    <div className="space-y-2 border-t border-border pt-4">
                        <label className="flex items-start gap-2 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={agreedToTerms}
                                onChange={(e) => setAgreedToTerms(e.target.checked)}
                                className="mt-0.5 rounded border-border text-accent focus:ring-accent"
                            />
                            <span className="text-ink-secondary text-[11px] leading-relaxed">
                                I have reviewed and agree to the venue house rules, curfew, and
                                MiConvener Marketplace Rental Terms.
                            </span>
                        </label>
                        {errors.agreed_to_terms && (
                            <p className="text-rose-600 dark:text-rose-400 text-[11px]">
                                {errors.agreed_to_terms}
                            </p>
                        )}
                        {errors.starts_at && (
                            <p className="text-rose-600 dark:text-rose-400 text-[11px]">
                                {errors.starts_at}
                            </p>
                        )}
                        {errors.guest_count && (
                            <p className="text-rose-600 dark:text-rose-400 text-[11px]">
                                {errors.guest_count}
                            </p>
                        )}
                    </div>

                    {/* Submission CTA */}
                    <div className="pt-2">
                        {isPriceOnRequest ? (
                            <button
                                type="submit"
                                disabled={submitting || !availability.available}
                                className="w-full rounded-xl bg-accent py-3 text-xs font-semibold text-white shadow-md hover:bg-accent/90 disabled:opacity-50 transition-all flex items-center justify-center gap-2"
                            >
                                <Sparkles className="h-4 w-4" />
                                <span>{submitting ? 'Submitting...' : 'Submit Quote Request'}</span>
                            </button>
                        ) : (
                            <button
                                type="submit"
                                disabled={submitting || !availability.available}
                                className="w-full rounded-xl bg-accent py-3 text-xs font-semibold text-white shadow-md hover:bg-accent/90 disabled:opacity-50 transition-all flex items-center justify-center gap-2"
                            >
                                <CreditCard className="h-4 w-4" />
                                <span>
                                    {submitting
                                        ? 'Processing...'
                                        : `Pay Reservation Deposit (${formatCurrency(pricing?.deposit_required_pesewas || 0)})`}
                                </span>
                            </button>
                        )}
                    </div>
                </form>
            </div>
        </div>
    );
}
