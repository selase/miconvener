import { useEffect, useRef, useState } from 'react';
import { Html5Qrcode } from 'html5-qrcode';
import { Camera, Search as SearchIcon } from 'lucide-react';
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
                result.already_checked_in ? 'bg-warning-bg text-warning-fg' : 'bg-success-bg text-success-fg'
            }`}
        >
            <div>{result.message}</div>
            {result.registration?.seat_label && (
                <div className="mt-1 font-mono text-xs opacity-80">Seat {result.registration.seat_label} · {result.registration.room_name}</div>
            )}
        </div>
    );
}

function ScanMode({ scanUrl, onResult }) {
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
                            body: JSON.stringify({ token: decodedText }),
                        });
                        const json = await response.json();
                        onResult(json);
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
                // The component may have unmounted while the camera permission
                // prompt was pending — stop immediately rather than leaving it running.
                if (cancelled) {
                    scanner.stop().catch(() => {});
                }
            })
            .catch(() => onResult({ message: 'Could not access the camera. Use manual search instead.', error: true }));

        return () => {
            cancelled = true;
            // Only a scanner that actually finished starting can be stopped —
            // calling stop() before start() resolves (or after it failed, e.g.
            // no camera/permission denied) throws and crashes the component.
            if (started) {
                scanner.stop().catch(() => {});
            }
        };
    }, [scanUrl, onResult]);

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
                                <div className="text-xs text-ink-secondary">{registration.email} · {registration.ticket_code}</div>
                            </div>
                            {registration.status === 'checked_in' ? (
                                <span className="text-xs text-ink-secondary">Checked in</span>
                            ) : (
                                <Button onClick={() => checkIn(registration.id)}>Check in</Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function CheckInPanel({ scanUrl, searchUrl, checkInUrlFor }) {
    const [mode, setMode] = useState('scan');
    const [result, setResult] = useState(null);
    const toast = useToast();

    const handleResult = (payload) => {
        setResult(payload);
        if (payload?.message) {
            toast(payload.message);
        }
    };

    return (
        <div className="max-w-lg">
            <div className="mb-4 flex gap-2">
                <Button icon={Camera} onClick={() => setMode('scan')} variant={mode === 'scan' ? 'active' : 'default'}>
                    Scan QR
                </Button>
                <Button icon={SearchIcon} onClick={() => setMode('manual')} variant={mode === 'manual' ? 'active' : 'default'}>
                    Manual search
                </Button>
            </div>

            <CheckInResultBanner result={result} />

            {mode === 'scan' ? (
                <ScanMode scanUrl={scanUrl} onResult={handleResult} />
            ) : (
                <ManualMode searchUrl={searchUrl} checkInUrlFor={checkInUrlFor} onResult={handleResult} />
            )}
        </div>
    );
}
