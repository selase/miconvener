import { useState, useMemo } from 'react';
import { router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import StatusPill from '@/Components/Console/StatusPill';
import {
    ChevronLeft,
    ChevronRight,
    Plus,
    Building2,
    Clock,
    Users,
    AlertCircle,
    Trash2,
    ExternalLink,
    Lock,
} from 'lucide-react';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function VenueCalendarIndex({
    shop,
    spaces = [],
    events = [],
    month,
    selectedSpaceId = null,
}) {
    // Current viewed month: Date object initialized from month string (YYYY-MM)
    const [currentYear, currentMonthIndex] = useMemo(() => {
        if (!month || !month.includes('-')) {
            const now = new Date();
            return [now.getFullYear(), now.getMonth()];
        }
        const [y, m] = month.split('-').map(Number);
        return [y, m - 1];
    }, [month]);

    const [filterSpaceId, setFilterSpaceId] = useState(selectedSpaceId || 'all');
    const [selectedEvent, setSelectedEvent] = useState(null);
    const [blockModalOpen, setBlockModalOpen] = useState(false);
    const [deleteBlockModalOpen, setDeleteBlockModalOpen] = useState(false);
    const [blockToDelete, setBlockToDelete] = useState(null);

    // Block Date Form
    const {
        data: blockData,
        setData: setBlockData,
        post: postBlock,
        processing: blockProcessing,
        errors: blockErrors,
        reset: resetBlockForm,
    } = useForm({
        store_listing_id: spaces[0]?.id || '',
        starts_at: '',
        ends_at: '',
        reason: '',
    });

    const monthName = useMemo(() => {
        const d = new Date(currentYear, currentMonthIndex, 1);
        return d.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    }, [currentYear, currentMonthIndex]);

    // Navigate Month
    const handleMonthChange = (offset) => {
        const target = new Date(currentYear, currentMonthIndex + offset, 1);
        const y = target.getFullYear();
        const m = String(target.getMonth() + 1).padStart(2, '0');
        const nextMonthParam = `${y}-${m}`;

        router.get(
            route('tenant.venue.calendar.index'),
            {
                month: nextMonthParam,
                space_id: filterSpaceId !== 'all' ? filterSpaceId : undefined,
            },
            { preserveState: true }
        );
    };

    const handleToday = () => {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const nextMonthParam = `${y}-${m}`;

        router.get(
            route('tenant.venue.calendar.index'),
            {
                month: nextMonthParam,
                space_id: filterSpaceId !== 'all' ? filterSpaceId : undefined,
            },
            { preserveState: true }
        );
    };

    const handleFilterSpace = (spaceId) => {
        setFilterSpaceId(spaceId);
        const currentParam = `${currentYear}-${String(currentMonthIndex + 1).padStart(2, '0')}`;

        router.get(
            route('tenant.venue.calendar.index'),
            {
                month: currentParam,
                space_id: spaceId !== 'all' ? spaceId : undefined,
            },
            { preserveState: true }
        );
    };

    // Calculate calendar grid days for current month
    const calendarDays = useMemo(() => {
        const firstDayOfMonth = new Date(currentYear, currentMonthIndex, 1);
        const lastDayOfMonth = new Date(currentYear, currentMonthIndex + 1, 0);

        const startDayOfWeek = firstDayOfMonth.getDay(); // 0 for Sunday
        const totalDays = lastDayOfMonth.getDate();

        const toDateKey = (d) =>
            `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

        const days = [];

        // Previous month filler days
        const prevMonthLastDay = new Date(currentYear, currentMonthIndex, 0).getDate();
        for (let i = startDayOfWeek - 1; i >= 0; i--) {
            const date = new Date(currentYear, currentMonthIndex - 1, prevMonthLastDay - i);
            days.push({
                date,
                dayNumber: prevMonthLastDay - i,
                isCurrentMonth: false,
                dateKey: toDateKey(date),
            });
        }

        // Current month days
        for (let i = 1; i <= totalDays; i++) {
            const date = new Date(currentYear, currentMonthIndex, i);
            days.push({
                date,
                dayNumber: i,
                isCurrentMonth: true,
                dateKey: toDateKey(date),
            });
        }

        // Next month filler days to complete grid to 35 or 42 cells
        const remaining = 7 - (days.length % 7);
        if (remaining < 7) {
            for (let i = 1; i <= remaining; i++) {
                const date = new Date(currentYear, currentMonthIndex + 1, i);
                days.push({
                    date,
                    dayNumber: i,
                    isCurrentMonth: false,
                    dateKey: toDateKey(date),
                });
            }
        }

        return days;
    }, [currentYear, currentMonthIndex]);

    // Map events into date keys
    const eventsByDate = useMemo(() => {
        const map = {};

        events.forEach((ev) => {
            const startDate = new Date(ev.starts_at);
            const endDate = new Date(ev.ends_at);

            // Iterate through every date spanned by this booking
            const curr = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
            const last = new Date(endDate.getFullYear(), endDate.getMonth(), endDate.getDate());

            while (curr <= last) {
                const key = `${curr.getFullYear()}-${String(curr.getMonth() + 1).padStart(2, '0')}-${String(curr.getDate()).padStart(2, '0')}`;
                if (!map[key]) {
                    map[key] = [];
                }
                map[key].push(ev);
                curr.setDate(curr.getDate() + 1);
            }
        });

        return map;
    }, [events]);

    const todayKey = useMemo(() => {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }, []);

    const submitBlock = (e) => {
        e.preventDefault();
        postBlock(route('tenant.venue.calendar.blocks.store'), {
            onSuccess: () => {
                setBlockModalOpen(false);
                resetBlockForm();
            },
        });
    };

    const confirmDeleteBlock = (ev) => {
        setBlockToDelete(ev);
        setSelectedEvent(null);
        setDeleteBlockModalOpen(true);
    };

    const handleDeleteBlock = () => {
        if (!blockToDelete) return;
        router.delete(route('tenant.venue.calendar.blocks.destroy', { block: blockToDelete.id }), {
            onSuccess: () => {
                setDeleteBlockModalOpen(false);
                setBlockToDelete(null);
            },
        });
    };

    const formatCurrency = (pesewas) => {
        if (!pesewas && pesewas !== 0) return 'GHS 0.00';
        return `GHS ${(pesewas / 100).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
    };

    return (
        <ConsoleLayout>
            <div className="space-y-6">
                <div>
                    <PageHeader
                        title="Venue Master Calendar"
                        actions={
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="default"
                                    onClick={() => setBlockModalOpen(true)}
                                    className="flex items-center gap-1.5"
                                >
                                    <Plus className="h-4 w-4" />
                                    Block Dates
                                </Button>
                            </div>
                        }
                    />
                    <p className="text-xs text-ink-secondary mt-1">
                        Occupancy schedule, 48-hour holds, and blackout slots across {shop?.name || 'Venue Spaces'}.
                    </p>
                </div>

                {/* Filter and Month Navigation Bar */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between rounded-xl border border-border bg-surface p-4 shadow-2xs">
                    {/* Space Filter Tabs */}
                    <div className="flex flex-wrap items-center gap-1.5">
                        <button
                            type="button"
                            onClick={() => handleFilterSpace('all')}
                            className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                                filterSpaceId === 'all'
                                    ? 'bg-accent text-white font-semibold'
                                    : 'bg-surface-sunken text-ink-secondary hover:text-ink'
                            }`}
                        >
                            All Spaces ({spaces.length})
                        </button>
                        {spaces.map((sp) => (
                            <button
                                key={sp.id}
                                type="button"
                                onClick={() => handleFilterSpace(sp.id)}
                                className={`rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                                    filterSpaceId === sp.id
                                        ? 'bg-accent text-white font-semibold'
                                        : 'bg-surface-sunken text-ink-secondary hover:text-ink'
                                }`}
                            >
                                {sp.title}
                            </button>
                        ))}
                    </div>

                    {/* Month Controls & Legend */}
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => handleMonthChange(-1)}
                                aria-label="Previous Month"
                                className="grid h-8 w-8 place-items-center rounded-lg border border-border text-ink-secondary hover:bg-surface-hover hover:text-ink"
                            >
                                <ChevronLeft className="h-4 w-4" />
                            </button>
                            <span className="min-w-36 text-center font-bold text-sm text-ink">
                                {monthName}
                            </span>
                            <button
                                type="button"
                                onClick={() => handleMonthChange(1)}
                                aria-label="Next Month"
                                className="grid h-8 w-8 place-items-center rounded-lg border border-border text-ink-secondary hover:bg-surface-hover hover:text-ink"
                            >
                                <ChevronRight className="h-4 w-4" />
                            </button>
                            <Button variant="default" onClick={handleToday} className="text-xs py-1 px-2.5">
                                Today
                            </Button>
                        </div>

                        {/* Legend */}
                        <div className="hidden sm:flex items-center gap-3 border-l border-border pl-4 text-[11px] text-ink-secondary">
                            <div className="flex items-center gap-1.5">
                                <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                <span>Confirmed</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className="h-2.5 w-2.5 rounded-full bg-amber-500" />
                                <span>Hold / Pending</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className="h-2.5 w-2.5 rounded-full bg-slate-400" />
                                <span>Blackout Block</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Calendar Month Grid */}
                <div className="overflow-hidden rounded-xl border border-border bg-surface shadow-2xs">
                    {/* Weekday Header */}
                    <div className="grid grid-cols-7 border-b border-border bg-surface-sunken text-center text-xs font-semibold text-ink-secondary">
                        {WEEKDAYS.map((day) => (
                            <div key={day} className="py-2.5 border-r border-border last:border-r-0">
                                {day}
                            </div>
                        ))}
                    </div>

                    {/* Day Cells */}
                    <div className="grid grid-cols-7 auto-rows-fr">
                        {calendarDays.map((cell, idx) => {
                            const isToday = cell.dateKey === todayKey;
                            const dayEvents = eventsByDate[cell.dateKey] || [];

                            return (
                                <div
                                    key={cell.dateKey + idx}
                                    className={`min-h-32 border-b border-r border-border p-2 transition-colors ${
                                        (idx + 1) % 7 === 0 ? 'border-r-0' : ''
                                    } ${cell.isCurrentMonth ? 'bg-surface' : 'bg-surface-sunken/40 text-ink-tertiary'}`}
                                >
                                    <div className="flex items-center justify-between">
                                        <span
                                            className={`grid h-6 w-6 place-items-center rounded-full text-xs font-medium ${
                                                isToday
                                                    ? 'bg-accent text-white font-bold'
                                                    : cell.isCurrentMonth
                                                    ? 'text-ink font-semibold'
                                                    : 'text-ink-tertiary'
                                            }`}
                                        >
                                            {cell.dayNumber}
                                        </span>
                                        {dayEvents.length > 0 && (
                                            <span className="text-[10px] text-ink-tertiary font-mono">
                                                {dayEvents.length} {dayEvents.length === 1 ? 'slot' : 'slots'}
                                            </span>
                                        )}
                                    </div>

                                    {/* Events on this day */}
                                    <div className="mt-2 space-y-1.5">
                                        {dayEvents.slice(0, 3).map((ev) => {
                                            const isConfirmed = ev.status === 'confirmed';
                                            const isBlocked = ev.is_blocked;

                                            let badgeStyle = 'bg-amber-500/10 text-amber-700 border-amber-300 dark:border-amber-800 dark:text-amber-400';
                                            if (isConfirmed) {
                                                badgeStyle = 'bg-emerald-500/10 text-emerald-700 border-emerald-300 dark:border-emerald-800 dark:text-emerald-400';
                                            } else if (isBlocked) {
                                                badgeStyle = 'bg-slate-500/10 text-slate-700 border-slate-300 dark:border-slate-700 dark:text-slate-300 border-dashed';
                                            }

                                            return (
                                                <button
                                                    key={ev.id + cell.dateKey}
                                                    type="button"
                                                    onClick={() => setSelectedEvent(ev)}
                                                    className={`w-full rounded border px-1.5 py-1 text-left text-[11px] leading-tight transition-transform hover:scale-[1.02] ${badgeStyle}`}
                                                >
                                                    <div className="truncate font-semibold flex items-center gap-1">
                                                        {isBlocked && <Lock className="h-2.5 w-2.5 shrink-0" />}
                                                        <span className="truncate">{ev.space_title}</span>
                                                    </div>
                                                    <div className="truncate text-[10px] opacity-80">
                                                        {ev.title}
                                                    </div>
                                                </button>
                                            );
                                        })}

                                        {dayEvents.length > 3 && (
                                            <button
                                                type="button"
                                                onClick={() => setSelectedEvent(dayEvents[0])}
                                                className="w-full text-center text-[10px] font-semibold text-accent hover:underline"
                                            >
                                                +{dayEvents.length - 3} more
                                            </button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* Event Details Flyout Modal */}
            <Modal
                open={selectedEvent !== null}
                onClose={() => setSelectedEvent(null)}
                title={selectedEvent?.is_blocked ? 'Blackout / Maintenance Window' : 'Venue Reservation Details'}
                className="max-w-lg"
            >
                {selectedEvent && (
                    <div className="space-y-4">
                        <div className="flex items-center justify-between border-b border-border pb-3">
                            <div>
                                <div className="font-mono text-xs font-bold text-ink-tertiary">
                                    {selectedEvent.booking_reference}
                                </div>
                                <div className="text-base font-bold text-ink">
                                    {selectedEvent.title}
                                </div>
                            </div>
                            <StatusPill
                                status={
                                    selectedEvent.status === 'confirmed'
                                        ? 'success'
                                        : selectedEvent.is_blocked
                                        ? 'neutral'
                                        : 'pending'
                                }
                            >
                                {selectedEvent.status.replace('_', ' ')}
                            </StatusPill>
                        </div>

                        <div className="grid grid-cols-2 gap-3 text-xs">
                            <div className="rounded-lg border border-border bg-surface-sunken/40 p-2.5">
                                <div className="text-ink-tertiary flex items-center gap-1">
                                    <Building2 className="h-3.5 w-3.5 text-accent" />
                                    <span>Venue Space</span>
                                </div>
                                <div className="mt-1 font-semibold text-ink">
                                    {selectedEvent.space_title}
                                </div>
                            </div>

                            <div className="rounded-lg border border-border bg-surface-sunken/40 p-2.5">
                                <div className="text-ink-tertiary flex items-center gap-1">
                                    <Clock className="h-3.5 w-3.5 text-accent" />
                                    <span>Time Window</span>
                                </div>
                                <div className="mt-1 font-semibold text-ink">
                                    {new Date(selectedEvent.starts_at).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                    })}{' '}
                                    –{' '}
                                    {new Date(selectedEvent.ends_at).toLocaleDateString('en-US', {
                                        month: 'short',
                                        day: 'numeric',
                                        year: 'numeric',
                                    })}
                                </div>
                            </div>
                        </div>

                        {selectedEvent.is_blocked ? (
                            <div className="rounded-lg border border-border bg-surface-sunken/30 p-3 space-y-2">
                                <div className="text-xs font-semibold text-ink flex items-center gap-1.5">
                                    <AlertCircle className="h-4 w-4 text-ink-secondary" />
                                    <span>Blackout Reason / Host Notes:</span>
                                </div>
                                <p className="text-xs text-ink-secondary italic">
                                    "{selectedEvent.host_notes || 'Scheduled maintenance / internal hotel event'}"
                                </p>
                            </div>
                        ) : (
                            <div className="rounded-lg border border-border bg-surface-sunken/30 p-3 space-y-2 text-xs">
                                <div className="font-semibold text-ink flex items-center gap-1.5">
                                    <Users className="h-3.5 w-3.5 text-accent" />
                                    <span>Planner & Organization</span>
                                </div>
                                <div className="text-ink font-medium">
                                    {selectedEvent.planner_name}
                                    {selectedEvent.planner_company && (
                                        <span className="text-ink-secondary"> ({selectedEvent.planner_company})</span>
                                    )}
                                </div>
                                <div className="text-ink-tertiary">
                                    {selectedEvent.planner_email} {selectedEvent.planner_phone && `• ${selectedEvent.planner_phone}`}
                                </div>
                                <div className="pt-2 border-t border-border flex items-center justify-between text-ink">
                                    <span>Layout: <strong className="capitalize">{selectedEvent.layout_style}</strong> ({selectedEvent.guest_count} guests)</span>
                                    <span>Total: <strong className="text-accent">{formatCurrency(selectedEvent.total_amount_pesewas)}</strong></span>
                                </div>
                            </div>
                        )}

                        {/* Action buttons */}
                        <div className="pt-4 border-t border-border flex items-center justify-between">
                            {selectedEvent.is_blocked ? (
                                <Button
                                    onClick={() => confirmDeleteBlock(selectedEvent)}
                                    className="border-danger-fg/40 text-danger-fg hover:bg-danger-bg flex items-center gap-1.5 text-xs"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                    Remove Blackout
                                </Button>
                            ) : (
                                <Button
                                    variant="default"
                                    href={route('tenant.venue.inquiries.show', { inquiry: selectedEvent.id })}
                                    className="flex items-center gap-1.5 text-xs"
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />
                                    Open Lead Dossier
                                </Button>
                            )}

                            <Button onClick={() => setSelectedEvent(null)}>
                                Close
                            </Button>
                        </div>
                    </div>
                )}
            </Modal>

            {/* Block Dates Modal */}
            <Modal
                open={blockModalOpen}
                onClose={() => setBlockModalOpen(false)}
                title="Schedule Venue Blackout / Maintenance"
                className="max-w-md"
            >
                <form onSubmit={submitBlock} className="space-y-4">
                    <p className="text-xs text-ink-secondary">
                        Blocked dates prevent public event planners and AI agents from inquiring or reserving this space.
                    </p>

                    <div>
                        <label className="block text-xs font-semibold text-ink mb-1">
                            Select Venue Space
                        </label>
                        <select
                            value={blockData.store_listing_id}
                            onChange={(e) => setBlockData('store_listing_id', e.target.value)}
                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                        >
                            {spaces.map((sp) => (
                                <option key={sp.id} value={sp.id}>
                                    {sp.title}
                                </option>
                            ))}
                        </select>
                        {blockErrors.store_listing_id && (
                            <p className="mt-1 text-[11px] text-danger-fg">{blockErrors.store_listing_id}</p>
                        )}
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-semibold text-ink mb-1">
                                Starts At
                            </label>
                            <input
                                type="datetime-local"
                                value={blockData.starts_at}
                                onChange={(e) => setBlockData('starts_at', e.target.value)}
                                className="w-full rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
                                required
                            />
                            {blockErrors.starts_at && (
                                <p className="mt-1 text-[11px] text-danger-fg">{blockErrors.starts_at}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-ink mb-1">
                                Ends At
                            </label>
                            <input
                                type="datetime-local"
                                value={blockData.ends_at}
                                onChange={(e) => setBlockData('ends_at', e.target.value)}
                                className="w-full rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
                                required
                            />
                            {blockErrors.ends_at && (
                                <p className="mt-1 text-[11px] text-danger-fg">{blockErrors.ends_at}</p>
                            )}
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-semibold text-ink mb-1">
                            Reason / Blackout Notes
                        </label>
                        <input
                            type="text"
                            placeholder="e.g. Scheduled HVAC maintenance, VIP Wedding..."
                            value={blockData.reason}
                            onChange={(e) => setBlockData('reason', e.target.value)}
                            className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                            required
                        />
                        {blockErrors.reason && (
                            <p className="mt-1 text-[11px] text-danger-fg">{blockErrors.reason}</p>
                        )}
                    </div>

                    <div className="pt-4 border-t border-border flex justify-end gap-2">
                        <Button type="button" onClick={() => setBlockModalOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="default" disabled={blockProcessing}>
                            {blockProcessing ? 'Blocking...' : 'Confirm Blackout'}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Confirm Delete Blackout Modal */}
            <ConfirmModal
                open={deleteBlockModalOpen}
                onClose={() => setDeleteBlockModalOpen(false)}
                onConfirm={handleDeleteBlock}
                title="Remove Blackout Block?"
                description={`Are you sure you want to release the blackout block on ${blockToDelete?.space_title}? These dates will become immediately bookable on the marketplace.`}
                confirmLabel="Release Dates"
                cancelLabel="Keep Blocked"
                danger={true}
            />
        </ConsoleLayout>
    );
}
