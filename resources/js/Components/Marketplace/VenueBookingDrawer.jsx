import { useState, useEffect, useCallback, useMemo } from 'react';
import { router } from '@inertiajs/react';
import {
    Calendar,
    CheckCircle2,
    AlertCircle,
    X,
    CreditCard,
    Sparkles,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';

export default function VenueBookingDrawer({ isOpen, onClose, venue }) {
    const isPriceOnRequest = venue.price_visibility === 'on_request' || !venue.rental_price_pesewas;

    // Tomorrow's date formatted as YYYY-MM-DD
    const tomorrowStr = () => {
        const d = new Date();
        d.setDate(d.getDate() + 1);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    };

    const todayLocalStr = () => {
        const d = new Date();
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
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

    // Mini visual calendar state
    const [calendarDate, setCalendarDate] = useState(() => new Date());
    const [bookedSlots, setBookedSlots] = useState([]);
    const [loadingSlots, setLoadingSlots] = useState(false);

    const [checking, setChecking] = useState(false);
    const [availability, setAvailability] = useState({
        available: true,
        pricing: null,
    });
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    // Fetch occupied and blackout slots for this listing
    useEffect(() => {
        if (!isOpen || !venue?.slug) return;
        let isMounted = true;
        setLoadingSlots(true);

        fetch(`/marketplace/venues/${venue.slug}/booked-slots`)
            .then((res) => (res.ok ? res.json() : { slots: [] }))
            .then((data) => {
                if (isMounted) {
                    setBookedSlots(data.slots || []);
                }
            })
            .catch((err) => console.error('Failed to load booked slots:', err))
            .finally(() => {
                if (isMounted) setLoadingSlots(false);
            });

        return () => {
            isMounted = false;
        };
    }, [isOpen, venue?.slug]);

    const getSlotForDate = useCallback(
        (dayStr) => {
            return bookedSlots.find((slot) => {
                const startDay = slot.starts_at.slice(0, 10);
                const endDay = slot.ends_at.slice(0, 10);
                return dayStr >= startDay && dayStr <= endDay;
            });
        },
        [bookedSlots]
    );

    const calendarDays = useMemo(() => {
        const year = calendarDate.getFullYear();
        const month = calendarDate.getMonth();
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        const days = [];
        for (let i = 0; i < firstDay; i++) {
            days.push(null);
        }
        for (let d = 1; d <= daysInMonth; d++) {
            const mStr = String(month + 1).padStart(2, '0');
            const dStr = String(d).padStart(2, '0');
            const dateStr = `${year}-${mStr}-${dStr}`;
            days.push({
                day: d,
                dateStr,
            });
        }
        return days;
    }, [calendarDate]);

    const handleCalendarDayClick = (dayObj) => {
        if (!dayObj) return;
        const todayStr = todayLocalStr();
        if (dayObj.dateStr < todayStr) return;

        const slot = getSlotForDate(dayObj.dateStr);
        if (slot) return;

        if (dateMode === 'single') {
            setStartDate(dayObj.dateStr);
            setEndDate(dayObj.dateStr);
        } else {
            if (!startDate || (startDate && endDate && startDate !== endDate)) {
                setStartDate(dayObj.dateStr);
                setEndDate(dayObj.dateStr);
            } else if (dayObj.dateStr < startDate) {
                setStartDate(dayObj.dateStr);
                setEndDate(dayObj.dateStr);
            } else {
                setEndDate(dayObj.dateStr);
            }
        }
    };

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
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-1.5 font-semibold text-ink text-sm">
                                <Calendar className="h-4 w-4 text-accent" />
                                <span>Availability & Date Schedule</span>
                            </div>
                            <span className="text-[11px] text-ink-muted">
                                Click a date on calendar or input below
                            </span>
                        </div>

                        {/* Interactive Availability Calendar */}
                        <div className="rounded-lg border border-border bg-surface p-3 space-y-2">
                            <div className="flex items-center justify-between pb-1.5 border-b border-border/60">
                                <span className="font-semibold text-ink text-xs">
                                    {calendarDate.toLocaleString('default', { month: 'long', year: 'numeric' })}
                                </span>
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => setCalendarDate((prev) => new Date(prev.getFullYear(), prev.getMonth() - 1, 1))}
                                        className="p-1 rounded hover:bg-surface-hover text-ink-secondary hover:text-ink transition-colors"
                                        title="Previous Month"
                                    >
                                        <ChevronLeft className="h-3.5 w-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setCalendarDate(new Date())}
                                        className="px-1.5 py-0.5 text-[10px] rounded border border-border text-ink-secondary hover:text-ink hover:bg-surface-hover transition-colors"
                                    >
                                        Today
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setCalendarDate((prev) => new Date(prev.getFullYear(), prev.getMonth() + 1, 1))}
                                        className="p-1 rounded hover:bg-surface-hover text-ink-secondary hover:text-ink transition-colors"
                                        title="Next Month"
                                    >
                                        <ChevronRight className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>

                            {/* Days of week header */}
                            <div className="grid grid-cols-7 text-center text-[10px] font-medium text-ink-muted py-0.5">
                                <span>Su</span>
                                <span>Mo</span>
                                <span>Tu</span>
                                <span>We</span>
                                <span>Th</span>
                                <span>Fr</span>
                                <span>Sa</span>
                            </div>

                            {/* Month days grid */}
                            <div className="grid grid-cols-7 gap-1">
                                {calendarDays.map((dayObj, idx) => {
                                    if (!dayObj) {
                                        return <div key={`empty-${idx}`} className="h-7" />;
                                    }
                                    const todayStr = todayLocalStr();
                                    const isPast = dayObj.dateStr < todayStr;
                                    const slot = getSlotForDate(dayObj.dateStr);
                                    const isBlocked = !!slot;
                                    const isSelected =
                                        dateMode === 'single'
                                            ? dayObj.dateStr === startDate
                                            : dayObj.dateStr >= startDate && dayObj.dateStr <= endDate;

                                    let cellClasses = 'h-7 w-full flex items-center justify-center rounded-md text-[11px] transition-colors ';

                                    if (isSelected) {
                                        cellClasses += 'bg-accent text-white font-bold shadow-2xs';
                                    } else if (isBlocked) {
                                        cellClasses += 'bg-rose-500/10 text-rose-500 line-through cursor-not-allowed opacity-70';
                                    } else if (isPast) {
                                        cellClasses += 'text-ink-muted/40 cursor-not-allowed';
                                    } else {
                                        cellClasses += 'text-ink hover:bg-accent/15 cursor-pointer font-medium';
                                    }

                                    return (
                                        <button
                                            type="button"
                                            key={dayObj.dateStr}
                                            disabled={isPast || isBlocked}
                                            onClick={() => handleCalendarDayClick(dayObj)}
                                            title={isBlocked ? 'Unavailable / Reserved' : isPast ? 'Past Date' : `Select ${dayObj.dateStr}`}
                                            className={cellClasses}
                                        >
                                            {dayObj.day}
                                        </button>
                                    );
                                })}
                            </div>

                            {/* Calendar Legend */}
                            <div className="flex items-center justify-between pt-1.5 border-t border-border/60 text-[10px] text-ink-muted">
                                <div className="flex items-center gap-3">
                                    <div className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-full bg-emerald-500" />
                                        <span>Available</span>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-full bg-rose-500" />
                                        <span>Reserved / Blocked</span>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <span className="h-2 w-2 rounded-full bg-accent" />
                                        <span>Selected</span>
                                    </div>
                                </div>
                                {loadingSlots && <span>Updating...</span>}
                            </div>
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
