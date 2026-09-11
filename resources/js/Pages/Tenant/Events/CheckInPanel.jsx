import { useEffect, useRef, useState, useMemo } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, Search as SearchIcon, DoorOpen, LogIn, LogOut, AlertTriangle } from 'lucide-react';
import SearchInput from '@/Components/Console/SearchInput';
import Button from '@/Components/Console/Button';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

const SCANNER_ELEMENT_ID = 'event-checkin-qr-reader';

function CheckInResultBanner({ result }) {
    if (!result) return null;

    return (
        <div
            className={`mb-4 rounded-md px-4 py-3 text-sm ${
                result.is_room_full
                    ? 'bg-danger-bg text-danger-fg border border-danger-fg/40'
                    : result.already_checked_in || result.not_checked_in
                      ? 'bg-warning-bg text-warning-fg border border-warning-fg/40'
                      : 'bg-success-bg text-success-fg border border-success-fg/40'
            }`}
        >
            <div className="font-medium">{result.message}</div>
            {result.registration?.seat_label && (
                <div className="mt-1 font-mono text-xs opacity-80">
                    Seat {result.registration.seat_label} · {result.registration.room_name}
                </div>
            )}
            {result.duration_minutes !== undefined && (
                <div className="mt-1 font-mono text-xs opacity-80">
                    Dwell duration: {result.duration_minutes} min ({result.hours_earned || 0} hrs CPD credit)
                </div>
            )}
        </div>
    );
}

function ScanMode({ scanUrl, payloadExtra = {}, onResult }) {
    const busyRef = useRef(false);

    useEffect(() => {
        const scanner = new Html5Qrcode(SCANNER_ELEMENT_ID);
        let started = false;
        let cancelled = false;

        scanner
            .start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: 240 },
                async (decodedText) => {
                    if (busyRef.current) return;
                    busyRef.current = true;

                    try {
                        const response = await csrfFetch(scanUrl, {
                            method: 'POST',
                            body: JSON.stringify({
                                token: decodedText,
                                ...payloadExtra,
                            }),
                        });
                        const json = await response.json();
                        onResult(json);
                    } catch (err) {
                        onResult({ message: 'Error communicating with server.', error: true });
                    } finally {
                        setTimeout(() => {
                            busyRef.current = false;
                        }, 1500);
                    }
                },
                () => {}
            )
            .then(() => {
                started = true;
                if (cancelled) {
                    scanner.stop().catch(() => {});
                }
            })
            .catch(() =>
                onResult({
                    message: 'Could not access the camera. Use manual search instead.',
                    error: true,
                })
            );

        return () => {
            cancelled = true;
            if (started) {
                scanner.stop().catch(() => {});
            }
        };
    }, [scanUrl, JSON.stringify(payloadExtra), onResult]);

    return (
        <div className="relative mx-auto aspect-square max-w-sm overflow-hidden border border-border bg-surface-sunken">
            <div id={SCANNER_ELEMENT_ID} className="h-full w-full" />
            <span className="pointer-events-none absolute left-3.5 top-3.5 h-5 w-5 border-l border-t border-accent" />
            <span className="pointer-events-none absolute right-3.5 top-3.5 h-5 w-5 border-r border-t border-accent" />
            <span className="pointer-events-none absolute bottom-3.5 left-3.5 h-5 w-5 border-b border-l border-accent" />
            <span className="pointer-events-none absolute bottom-3.5 right-3.5 h-5 w-5 border-b border-r border-accent" />
        </div>
    );
}

function ManualMode({ searchUrl, checkInUrlFor, onResult }) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState([]);

    useEffect(() => {
        if (query.length < 2) {
            setResults([]);
            return;
        }

        const timeout = setTimeout(async () => {
            const response = await csrfFetch(`${searchUrl}?q=${encodeURIComponent(query)}`);
            setResults(await response.json());
        }, 250);

        return () => clearTimeout(timeout);
    }, [query, searchUrl]);

    const checkIn = async (registrationId) => {
        const response = await csrfFetch(checkInUrlFor(registrationId), { method: 'POST' });
        onResult(await response.json());
        setQuery('');
        setResults([]);
    };

    return (
        <div>
            <SearchInput
                placeholder="Search by name, email, or ticket code"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
            />
            {results.length > 0 && (
                <ul className="mt-3 divide-y divide-border rounded-md border border-border">
                    {results.map((registration) => (
                        <li key={registration.id} className="flex items-center justify-between px-4 py-2.5">
                            <div>
                                <div className="text-sm font-medium text-ink">{registration.full_name}</div>
                                <div className="text-xs text-ink-secondary">
                                    {registration.email} · {registration.ticket_code}
                                </div>
                            </div>
                            <Button onClick={() => checkIn(registration.id)}>Check in</Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function CheckInPanel({
    event,
    scanUrl,
    searchUrl,
    checkInUrlFor,
    sessions = [],
    preselectedSessionId = null,
}) {
    const [targetMode, setTargetMode] = useState(preselectedSessionId ? 'room' : 'event');
    const [selectedSessionId, setSelectedSessionId] = useState(preselectedSessionId || (sessions[0]?.id || ''));
    const [scanAction, setScanAction] = useState('check_in'); // 'check_in' | 'check_out'
    const [overrideCapacity, setOverrideCapacity] = useState(false);
    const [mode, setMode] = useState('scan');
    const [result, setResult] = useState(null);
    const toast = useToast();

    const selectedSession = useMemo(() => {
        return sessions.find((s) => s.id === selectedSessionId) || null;
    }, [sessions, selectedSessionId]);

    const handleResult = (payload) => {
        setResult(payload);
        if (payload?.message) {
            toast(payload.message);
        }
    };

    // Calculate effective scan URL and payload
    const effectiveScanUrl = useMemo(() => {
        if (targetMode === 'room' && selectedSessionId && event?.id) {
            return route('tenant.events.sessions.scan', {
                event: event.id,
                session: selectedSessionId,
            });
        }
        return scanUrl;
    }, [targetMode, selectedSessionId, event?.id, scanUrl]);

    const effectiveCheckInUrlFor = (registrationId) => {
        if (targetMode === 'room' && selectedSessionId && event?.id) {
            return `${route('tenant.events.sessions.scan', {
                event: event.id,
                session: selectedSessionId,
            })}?registration_id=${registrationId}&action=${scanAction}&override_capacity=${overrideCapacity ? '1' : '0'}`;
        }
        return checkInUrlFor(registrationId);
    };

    const payloadExtra = useMemo(() => {
        if (targetMode === 'room') {
            return {
                action: scanAction,
                override_capacity: overrideCapacity,
            };
        }
        return {};
    }, [targetMode, scanAction, overrideCapacity]);

    return (
        <div className="max-w-lg space-y-4">
            {/* Target Selector: Main Gate vs Breakout Room */}
            {sessions.length > 0 && (
                <div className="rounded-lg border border-border bg-surface p-3">
                    <label className="block text-xs font-semibold text-ink-secondary mb-2 uppercase tracking-wider">
                        Scanning Station Mode
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            onClick={() => setTargetMode('event')}
                            className={`rounded-md px-3 py-2 text-xs font-medium transition-colors border ${
                                targetMode === 'event'
                                    ? 'bg-accent text-white border-accent'
                                    : 'bg-surface text-ink hover:bg-surface-sunken border-border'
                            }`}
                        >
                            Main Event Gate
                        </button>
                        <button
                            type="button"
                            onClick={() => setTargetMode('room')}
                            className={`flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium transition-colors border ${
                                targetMode === 'room'
                                    ? 'bg-accent text-white border-accent'
                                    : 'bg-surface text-ink hover:bg-surface-sunken border-border'
                            }`}
                        >
                            <DoorOpen className="h-3.5 w-3.5" />
                            Breakout Room
                        </button>
                    </div>

                    {targetMode === 'room' && (
                        <div className="mt-3 pt-3 border-t border-border/70 space-y-3">
                            <div>
                                <label className="block text-xs font-medium text-ink mb-1">
                                    Select Session / Room:
                                </label>
                                <select
                                    value={selectedSessionId}
                                    onChange={(e) => setSelectedSessionId(e.target.value)}
                                    className="w-full rounded border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
                                >
                                    {sessions.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.title} ({s.location || 'Hall'}{s.capacity ? ` · Cap: ${s.capacity}` : ''})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Scan Direction Toggle */}
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setScanAction('check_in')}
                                    className={`flex-1 flex items-center justify-center gap-1 rounded py-1.5 text-xs font-semibold ${
                                        scanAction === 'check_in'
                                            ? 'bg-success-fg text-white'
                                            : 'bg-surface-sunken text-ink-secondary border border-border'
                                    }`}
                                >
                                    <LogIn className="h-3.5 w-3.5" />
                                    Scan IN (Entry)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setScanAction('check_out')}
                                    className={`flex-1 flex items-center justify-center gap-1 rounded py-1.5 text-xs font-semibold ${
                                        scanAction === 'check_out'
                                            ? 'bg-accent text-white'
                                            : 'bg-surface-sunken text-ink-secondary border border-border'
                                    }`}
                                >
                                    <LogOut className="h-3.5 w-3.5" />
                                    Scan OUT (Exit)
                                </button>
                            </div>

                            {/* Room Headcount Notice */}
                            {selectedSession && (
                                <div className="rounded bg-surface-sunken border border-border/80 p-2.5 text-xs">
                                    <div className="flex items-center justify-between">
                                        <span className="font-semibold text-ink">{selectedSession.location || 'Room'} Headcount:</span>
                                        <span className="font-mono font-bold text-accent">
                                            {selectedSession.live_headcount || 0}{selectedSession.capacity ? ` / ${selectedSession.capacity}` : ''}
                                        </span>
                                    </div>
                                    {selectedSession.capacity && (selectedSession.live_headcount >= selectedSession.capacity) && (
                                        <div className="mt-2 flex items-center gap-1.5 text-danger-fg text-[11px] font-medium">
                                            <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                                            <span>Room is at maximum capacity.</span>
                                        </div>
                                    )}
                                </div>
                            )}

                            <label className="flex items-center gap-2 text-xs text-ink-secondary cursor-pointer">
                                <input
                                    type="checkbox"
                                    checked={overrideCapacity}
                                    onChange={(e) => setOverrideCapacity(e.target.checked)}
                                    className="rounded border-border text-accent"
                                />
                                <span>Allow entry override if room is full</span>
                            </label>
                        </div>
                    )}
                </div>
            )}

            {/* Camera / Manual mode switch */}
            <div className="flex gap-2">
                <Button
                    icon={Camera}
                    onClick={() => setMode('scan')}
                    variant={mode === 'scan' ? 'active' : 'default'}
                >
                    Scan QR
                </Button>
                <Button
                    icon={SearchIcon}
                    onClick={() => setMode('manual')}
                    variant={mode === 'manual' ? 'active' : 'default'}
                >
                    Manual search
                </Button>
            </div>

            <CheckInResultBanner result={result} />

            {mode === 'scan' ? (
                <ScanMode
                    scanUrl={effectiveScanUrl}
                    payloadExtra={payloadExtra}
                    onResult={handleResult}
                />
            ) : (
                <ManualMode
                    searchUrl={searchUrl}
                    checkInUrlFor={effectiveCheckInUrlFor}
                    onResult={handleResult}
                />
            )}
        </div>
    );
}
