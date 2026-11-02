import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Camera, CameraOff } from 'lucide-react';

async function dispose(scanner) {
    if (!scanner) return;
    try {
        if (scanner.isScanning) await scanner.stop();
    } catch {
        /* A pending start is checked again once it resolves. */
    }
    try {
        scanner.clear();
    } catch {
        /* The camera container may already have unmounted. */
    }
}
export default function StoreCollectionScanner({ onCredential, onActiveChange, disabled = false }) {
    const id = `store-scan-${useId().replaceAll(':', '')}`;
    const scanner = useRef(null);
    const generation = useRef(0);
    const callback = useRef(onCredential);
    callback.current = onCredential;
    const activity = useRef(onActiveChange);
    activity.current = onActiveChange;
    const mounted = useRef(true);
    const disabledRef = useRef(disabled);
    disabledRef.current = disabled;
    const [active, setActive] = useState(false);
    const [stopping, setStopping] = useState(false);
    const [checking, setChecking] = useState(false);
    const [status, setStatus] = useState('');
    const [error, setError] = useState('');
    const stop = useCallback(async () => {
        generation.current++;
        const current = scanner.current;
        if (!current) return;
        current.cancelled = true;
        if (current.stopping) return current.stopping;
        if (mounted.current) {
            setActive(false);
            setStopping(true);
        }
        current.stopping = (async () => {
            // The library must finish starting before it can safely stop or clear its DOM.
            await current.started;
            await dispose(current.instance);
            if (scanner.current === current) {
                scanner.current = null;
                activity.current?.(false, id);
                if (mounted.current) setStopping(false);
            }
        })();
        return current.stopping;
    }, [id]);
    useEffect(() => {
        mounted.current = true;
        const visibility = () => {
            if (document.visibilityState === 'hidden') void stop();
        };
        document.addEventListener('visibilitychange', visibility);
        return () => {
            mounted.current = false;
            void stop();
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [stop]);
    useEffect(() => {
        if (disabled) void stop();
    }, [disabled, stop]);
    const start = async () => {
        if (disabled || scanner.current || checking) return;
        setError('');
        setStatus('Starting camera…');
        setActive(true);
        activity.current?.(true, id);
        const ticket = ++generation.current;
        let finishStart;
        const current = {
            instance: null,
            cancelled: false,
            stopping: null,
            started: new Promise((resolve) => {
                finishStart = resolve;
            }),
        };
        scanner.current = current;
        let failed = false;
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('Camera unavailable');
            const { Html5Qrcode } = await import('html5-qrcode');
            if (ticket !== generation.current || current.cancelled) return;
            current.instance = new Html5Qrcode(id);
            let accepted = false;
            await current.instance.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: 220 },
                async (text) => {
                    if (ticket !== generation.current || accepted) return;
                    let credential;
                    try {
                        if (text.length > 2048) throw new Error('Oversized');
                        credential = JSON.parse(text);
                        if (
                            ![
                                'miconvener.store.collection.v1',
                                'miconvener.store.delivery.v1',
                            ].includes(credential?.purpose) ||
                            typeof credential.pass_id !== 'string' ||
                            typeof credential.secret !== 'string'
                        )
                            throw new Error('Wrong code');
                    } catch {
                        setError(
                            'Use the buyer’s store handover QR code, or enter the manual code below.'
                        );
                        return;
                    }
                    accepted = true;
                    setChecking(true);
                    setStatus('QR read. Checking the handover code…');
                    await stop();
                    try {
                        if (
                            !mounted.current ||
                            document.visibilityState === 'hidden' ||
                            disabledRef.current
                        ) {
                            if (mounted.current)
                                setStatus(
                                    'Scan interrupted. Refresh the order status before scanning again.'
                                );
                            return;
                        }
                        const result = await callback.current(credential);
                        if (mounted.current && document.visibilityState !== 'hidden') {
                            if (result === false) {
                                setStatus('');
                                setError(
                                    'QR read, but the code could not be verified. Check the order message or enter the manual code.'
                                );
                            } else {
                                setStatus('QR read. Review the verification result below.');
                            }
                        }
                    } catch {
                        if (mounted.current) {
                            setStatus('');
                            setError(
                                'QR read, but verification could not start. Retry or enter the manual code.'
                            );
                        }
                    } finally {
                        if (mounted.current) setChecking(false);
                    }
                },
                () => {}
            );
            if (!current.cancelled && mounted.current)
                setStatus('Point the camera at the buyer’s store handover QR code.');
        } catch {
            failed = true;
            if (ticket === generation.current) {
                setError(
                    'Camera unavailable or permission denied. You can enter the buyer’s code instead.'
                );
                setActive(false);
                setStatus('');
            }
        } finally {
            finishStart();
            if (failed || current.cancelled) void stop();
        }
    };
    return (
        <div className="space-y-3">
            <div id={id} className="max-w-full overflow-hidden" />
            {active ? (
                <button
                    type="button"
                    onClick={stop}
                    className="inline-flex min-h-11 items-center gap-2 border border-border px-4 text-sm"
                >
                    <CameraOff className="h-4 w-4" aria-hidden="true" />
                    Stop camera
                </button>
            ) : (
                <button
                    type="button"
                    disabled={disabled || stopping || checking}
                    onClick={start}
                    className="inline-flex min-h-11 items-center gap-2 border border-border px-4 text-sm disabled:opacity-50"
                >
                    <Camera className="h-4 w-4" aria-hidden="true" />
                    Start camera
                </button>
            )}
            {(stopping || status) && (
                <p role="status" className="text-sm text-ink-secondary">
                    {stopping ? 'Stopping camera…' : status}
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
