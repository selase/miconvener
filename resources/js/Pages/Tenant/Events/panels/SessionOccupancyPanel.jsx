import { useEffect, useState, useCallback, useMemo } from 'react';
import { 
    Users, 
    DoorOpen, 
    AlertTriangle, 
    CheckCircle2, 
    Clock, 
    Download, 
    RefreshCw, 
    QrCode, 
    MapPin, 
    Radio, 
    Search,
    X,
    Maximize2,
    Activity
} from 'lucide-react';
import Button from '@/Components/Console/Button';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

export default function SessionOccupancyPanel({ event, onOpenScannerForSession }) {
    const toast = useToast();
    const [sessions, setSessions] = useState([]);
    const [summary, setSummary] = useState({
        total_sessions: 0,
        active_rooms_count: 0,
        full_rooms_count: 0,
        total_in_sessions: 0,
    });
    const [loading, setLoading] = useState(true);
    const [activeRosterSession, setActiveRosterSession] = useState(null);
    const [roster, setRoster] = useState([]);
    const [rosterFilter, setRosterFilter] = useState('active');
    const [rosterSearch, setRosterSearch] = useState('');
    const [rosterLoading, setRosterLoading] = useState(false);

    const fetchOccupancy = useCallback(async (quiet = false) => {
        if (!quiet) setLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.sessions.occupancy', { event: event.id }));
            const data = await res.json();
            if (data.sessions) {
                setSessions(data.sessions);
                setSummary(data.summary || {});
            }
        } catch (err) {
            console.error('Failed to fetch session occupancy:', err);
        } finally {
            if (!quiet) setLoading(false);
        }
    }, [event.id]);

    useEffect(() => {
        fetchOccupancy();

        // 15-second background polling fallback
        const interval = setInterval(() => {
            fetchOccupancy(true);
        }, 15000);

        // Real-time Reverb WebSocket listener
        if (typeof window !== 'undefined' && window.Echo) {
            const channel = window.Echo.private(`event.${event.id}.sessions`);
            channel.listen('.SessionAttendanceUpdated', (data) => {
                setSessions((prev) =>
                    prev.map((s) => {
                        if (s.id === data.session_id) {
                            const newPct = data.occupancy_percentage;
                            const isFull = data.is_room_full;
                            const newStatus = isFull ? 'at_capacity' : newPct >= 85 ? 'near_capacity' : 'available';

                            return {
                                ...s,
                                live_headcount: data.live_headcount,
                                occupancy_percentage: newPct,
                                is_at_capacity: isFull,
                                status: newStatus,
                            };
                        }
                        return s;
                    })
                );

                // Flash subtle notification
                if (data.attendee_name) {
                    const actionLabel = data.action === 'check_in' ? 'entered' : 'exited';
                    toast(`${data.attendee_name} ${actionLabel} ${data.title}`);
                }
            });

            return () => {
                clearInterval(interval);
                channel.stopListening('.SessionAttendanceUpdated');
            };
        }

        return () => clearInterval(interval);
    }, [event.id, fetchOccupancy, toast]);

    const loadRoster = async (session, filter = rosterFilter, search = rosterSearch) => {
        setRosterLoading(true);
        try {
            const url = `${route('tenant.events.sessions.attendees', {
                event: event.id,
                session: session.id,
            })}?filter=${filter}&q=${encodeURIComponent(search)}`;
            const res = await csrfFetch(url);
            const data = await res.json();
            setRoster(data.attendees || []);
        } catch (err) {
            console.error('Failed to load room roster:', err);
        } finally {
            setRosterLoading(false);
        }
    };

    const handleOpenRoster = (session) => {
        setActiveRosterSession(session);
        setRosterFilter('active');
        setRosterSearch('');
        loadRoster(session, 'active', '');
    };

    const formatTime = (iso) => {
        if (!iso) return '';
        try {
            const d = new Date(iso);
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch {
            return '';
        }
    };

    return (
        <div className="space-y-6">
            {/* Header & Command Bar */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-border pb-4">
                <div>
                    <div className="flex items-center gap-2">
                        <h2 className="text-base font-semibold text-ink">Live Room Headcount & Breakout Tracking</h2>
                        <span className="flex items-center gap-1.5 rounded-full bg-success-fg/10 px-2 py-0.5 text-[11px] font-medium text-success-fg">
                            <Radio className="h-3 w-3 animate-pulse text-success-fg" />
                            Live Reverb Sync
                        </span>
                    </div>
                    <p className="mt-0.5 text-xs text-ink-secondary">
                        Real-time room occupancy, multi-point room scanner integration, and CPD/CME accreditation logs.
                    </p>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="secondary"
                        size="sm"
                        icon={RefreshCw}
                        onClick={() => fetchOccupancy()}
                        disabled={loading}
                    >
                        Refresh
                    </Button>
                    <a
                        href={route('tenant.events.reports.session-attendance', { event: event.id })}
                        download
                        className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-sunken transition-colors"
                    >
                        <Download className="h-3.5 w-3.5 text-ink-secondary" />
                        Export CPD Report (CSV)
                    </a>
                </div>
            </div>

            {/* Metrics Overview Cards */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-lg border border-border bg-surface p-3.5 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-ink-secondary">Total In Rooms</span>
                        <Users className="h-4 w-4 text-accent" />
                    </div>
                    <div className="mt-2 text-2xl font-bold font-mono text-ink">
                        {summary.total_in_sessions || 0}
                    </div>
                    <div className="mt-1 text-[11px] text-ink-muted">Currently seated attendees</div>
                </div>

                <div className="rounded-lg border border-border bg-surface p-3.5 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-ink-secondary">Active Rooms</span>
                        <DoorOpen className="h-4 w-4 text-success-fg" />
                    </div>
                    <div className="mt-2 text-2xl font-bold font-mono text-ink">
                        {summary.active_rooms_count || 0}
                        <span className="text-sm font-normal text-ink-secondary"> / {summary.total_sessions || 0}</span>
                    </div>
                    <div className="mt-1 text-[11px] text-ink-muted">With live occupancy</div>
                </div>

                <div className="rounded-lg border border-border bg-surface p-3.5 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-ink-secondary">Rooms at Capacity</span>
                        <AlertTriangle className="h-4 w-4 text-danger-fg" />
                    </div>
                    <div className="mt-2 text-2xl font-bold font-mono text-danger-fg">
                        {summary.full_rooms_count || 0}
                    </div>
                    <div className="mt-1 text-[11px] text-ink-muted">Entry restricted / full</div>
                </div>

                <div className="rounded-lg border border-border bg-surface p-3.5 shadow-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-ink-secondary">Total Sessions</span>
                        <Activity className="h-4 w-4 text-ink-secondary" />
                    </div>
                    <div className="mt-2 text-2xl font-bold font-mono text-ink">
                        {summary.total_sessions || 0}
                    </div>
                    <div className="mt-1 text-[11px] text-ink-muted">Workshops & keynotes</div>
                </div>
            </div>

            {/* Room Occupancy Grid */}
            {loading && sessions.length === 0 ? (
                <div className="rounded-lg border border-border bg-surface p-12 text-center text-xs text-ink-secondary">
                    Loading live room occupancies...
                </div>
            ) : sessions.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border bg-surface p-10 text-center text-xs text-ink-secondary">
                    No sessions or breakout rooms scheduled for this event yet.
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {sessions.map((session) => {
                        const hasCapacity = session.capacity && session.capacity > 0;
                        const pct = session.occupancy_percentage || 0;

                        return (
                            <div
                                key={session.id}
                                className={`flex flex-col justify-between rounded-lg border bg-surface p-4 transition-all shadow-sm ${
                                    session.is_at_capacity
                                        ? 'border-danger-fg/60 bg-danger-bg/20'
                                        : session.status === 'near_capacity'
                                          ? 'border-warning-fg/60 bg-warning-bg/15'
                                          : 'border-border hover:border-accent/50'
                                }`}
                            >
                                <div>
                                    {/* Top badges */}
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="inline-flex items-center gap-1 rounded bg-surface-sunken px-2 py-0.5 text-[11px] font-medium text-ink-secondary border border-border">
                                                <MapPin className="h-3 w-3" />
                                                {session.location}
                                            </span>
                                            {session.is_live && (
                                                <span className="inline-flex items-center gap-1 rounded bg-success-fg/15 px-1.5 py-0.5 text-[10.5px] font-semibold text-success-fg">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-success-fg animate-ping" />
                                                    LIVE
                                                </span>
                                            )}
                                            {session.type && (
                                                <span className="rounded bg-accent/10 px-1.5 py-0.5 text-[10.5px] font-medium text-accent uppercase tracking-wider">
                                                    {session.type}
                                                </span>
                                            )}
                                        </div>

                                        {session.is_at_capacity ? (
                                            <span className="rounded bg-danger-fg px-2 py-0.5 text-[10.5px] font-bold text-white uppercase tracking-wider">
                                                FULL
                                            </span>
                                        ) : session.status === 'near_capacity' ? (
                                            <span className="rounded bg-warning-bg text-warning-fg border border-warning-fg/40 px-2 py-0.5 text-[10.5px] font-semibold">
                                                85%+
                                            </span>
                                        ) : null}
                                    </div>

                                    {/* Session Title */}
                                    <h3 className="mt-2.5 text-sm font-semibold text-ink line-clamp-1">
                                        {session.title}
                                    </h3>

                                    {/* Time Range */}
                                    <div className="mt-1 flex items-center gap-1.5 text-xs text-ink-muted">
                                        <Clock className="h-3 w-3" />
                                        <span>
                                            {formatTime(session.starts_at)} - {formatTime(session.ends_at)}
                                        </span>
                                        {session.track && (
                                            <>
                                                <span>·</span>
                                                <span className="truncate">{session.track}</span>
                                            </>
                                        )}
                                    </div>

                                    {/* Occupancy Gauge */}
                                    <div className="mt-4 rounded-md border border-border/70 bg-surface-sunken p-3">
                                        <div className="flex items-baseline justify-between">
                                            <div className="flex items-baseline gap-1.5">
                                                <span className="text-xl font-bold font-mono text-ink">
                                                    {session.live_headcount}
                                                </span>
                                                <span className="text-xs text-ink-secondary">
                                                    {hasCapacity ? `/ ${session.capacity} max` : 'attendees'}
                                                </span>
                                            </div>

                                            {hasCapacity && (
                                                <span
                                                    className={`font-mono text-xs font-semibold ${
                                                        session.is_at_capacity
                                                            ? 'text-danger-fg'
                                                            : pct >= 85
                                                              ? 'text-warning-fg'
                                                              : 'text-success-fg'
                                                    }`}
                                                >
                                                    {pct}% capacity
                                                </span>
                                            )}
                                        </div>

                                        {/* Progress Bar */}
                                        {hasCapacity && (
                                            <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-border">
                                                <div
                                                    className={`h-full transition-all duration-500 rounded-full ${
                                                        session.is_at_capacity
                                                            ? 'bg-danger-fg'
                                                            : pct >= 85
                                                              ? 'bg-warning-fg'
                                                              : 'bg-success-fg'
                                                    }`}
                                                    style={{ width: `${Math.min(100, pct)}%` }}
                                                />
                                            </div>
                                        )}

                                        <div className="mt-2 flex items-center justify-between text-[11px] text-ink-muted">
                                            <span>Pre-registered: {session.registered_count || 0}</span>
                                            <span>
                                                {hasCapacity
                                                    ? `${Math.max(0, session.capacity - session.live_headcount)} seats left`
                                                    : 'Unlimited'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Card Actions */}
                                <div className="mt-4 flex items-center gap-2 border-t border-border/60 pt-3">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        className="flex-1 justify-center text-xs"
                                        onClick={() => handleOpenRoster(session)}
                                    >
                                        View Roster ({session.live_headcount})
                                    </Button>

                                    {onOpenScannerForSession && (
                                        <Button
                                            variant="primary"
                                            size="sm"
                                            icon={QrCode}
                                            className="justify-center text-xs"
                                            onClick={() => onOpenScannerForSession(session)}
                                        >
                                            Scan
                                        </Button>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Room Roster Drawer / Modal */}
            {activeRosterSession && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                    <div className="flex h-[85vh] w-full max-w-2xl flex-col rounded-lg border border-border bg-surface shadow-2xl">
                        {/* Drawer Header */}
                        <div className="flex items-center justify-between border-b border-border p-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <h3 className="text-base font-semibold text-ink">
                                        {activeRosterSession.title}
                                    </h3>
                                    <span className="rounded bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent">
                                        {activeRosterSession.live_headcount} in room
                                    </span>
                                </div>
                                <p className="text-xs text-ink-secondary">
                                    {activeRosterSession.location} · {formatTime(activeRosterSession.starts_at)} - {formatTime(activeRosterSession.ends_at)}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setActiveRosterSession(null)}
                                className="rounded p-1 text-ink-muted hover:bg-surface-sunken hover:text-ink"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        {/* Search & Filters */}
                        <div className="border-b border-border p-3 bg-surface-alt/40 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-1">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setRosterFilter('active');
                                        loadRoster(activeRosterSession, 'active', rosterSearch);
                                    }}
                                    className={`rounded px-2.5 py-1 text-xs font-medium ${
                                        rosterFilter === 'active'
                                            ? 'bg-accent text-white'
                                            : 'bg-surface text-ink-secondary hover:text-ink border border-border'
                                    }`}
                                >
                                    Currently in room
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setRosterFilter('all');
                                        loadRoster(activeRosterSession, 'all', rosterSearch);
                                    }}
                                    className={`rounded px-2.5 py-1 text-xs font-medium ${
                                        rosterFilter === 'all'
                                            ? 'bg-accent text-white'
                                            : 'bg-surface text-ink-secondary hover:text-ink border border-border'
                                    }`}
                                >
                                    All attendance logs
                                </button>
                            </div>

                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-ink-muted" />
                                <input
                                    type="text"
                                    value={rosterSearch}
                                    onChange={(e) => {
                                        setRosterSearch(e.target.value);
                                        loadRoster(activeRosterSession, rosterFilter, e.target.value);
                                    }}
                                    placeholder="Search attendee..."
                                    className="w-full sm:w-48 rounded border border-border bg-surface pl-8 pr-2.5 py-1 text-xs text-ink focus:border-accent focus:outline-none"
                                />
                            </div>
                        </div>

                        {/* Attendee List */}
                        <div className="flex-1 overflow-y-auto p-4 divide-y divide-border">
                            {rosterLoading ? (
                                <div className="p-8 text-center text-xs text-ink-secondary">
                                    Loading attendance roster...
                                </div>
                            ) : roster.length === 0 ? (
                                <div className="p-8 text-center text-xs text-ink-secondary">
                                    No attendees currently checked into this room.
                                </div>
                            ) : (
                                roster.map((attendee) => (
                                    <div key={attendee.id} className="py-2.5 flex items-center justify-between gap-3 text-xs">
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold text-ink">
                                                    {attendee.title ? `${attendee.title} ` : ''}{attendee.attendee_name}
                                                </span>
                                                {attendee.is_in_room ? (
                                                    <span className="rounded bg-success-fg/15 px-1.5 py-0.2 text-[10px] font-semibold text-success-fg">
                                                        Seated
                                                    </span>
                                                ) : (
                                                    <span className="rounded bg-surface-sunken px-1.5 py-0.2 text-[10px] font-medium text-ink-muted">
                                                        Exited
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-[11px] text-ink-secondary">
                                                {attendee.attendee_email} · Code: {attendee.ticket_code}
                                            </div>
                                        </div>

                                        <div className="text-right">
                                            <div className="font-mono text-xs font-semibold text-ink">
                                                {attendee.duration_minutes} mins
                                                <span className="text-[10px] text-ink-muted ml-1">({attendee.contact_hours} hrs CPD)</span>
                                            </div>
                                            <div className="text-[10px] text-ink-muted">
                                                In: {formatTime(attendee.checked_in_at)}
                                                {attendee.checked_out_at && ` · Out: ${formatTime(attendee.checked_out_at)}`}
                                            </div>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>

                        {/* Modal Footer */}
                        <div className="border-t border-border p-3 flex justify-between items-center bg-surface-alt/40">
                            <span className="text-xs text-ink-muted">
                                Total entries: {roster.length}
                            </span>
                            <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => setActiveRosterSession(null)}
                            >
                                Close
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
