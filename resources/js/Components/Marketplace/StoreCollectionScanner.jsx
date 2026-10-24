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
export default function StoreCollectionScanner({ onCredential, disabled = false }) {
    const id = `store-scan-${useId().replaceAll(':', '')}`;
    const scanner = useRef(null);
    const generation = useRef(0);
    const callback = useRef(onCredential);
    callback.current = onCredential;
    const [active, setActive] = useState(false);
    const [error, setError] = useState('');
    const stop = useCallback(async () => {
        generation.current++;
        const current = scanner.current;
        scanner.current = null;
        setActive(false);
        await dispose(current);
    }, []);
    useEffect(() => {
        const visibility = () => {
            if (document.visibilityState === 'hidden') void stop();
        };
        document.addEventListener('visibilitychange', visibility);
        return () => {
            void stop();
            document.removeEventListener('visibilitychange', visibility);
        };
    }, [stop]);
    useEffect(() => {
        if (disabled) void stop();
    }, [disabled, stop]);
    const start = async () => {
        if (disabled) return;
        setError('');
        setActive(true);
        const ticket = ++generation.current;
        let instance;
        try {
            if (!navigator.mediaDevices?.getUserMedia) throw new Error('Camera unavailable');
            const { Html5Qrcode } = await import('html5-qrcode');
            if (ticket !== generation.current) return;
            instance = new Html5Qrcode(id);
            scanner.current = instance;
            let accepted = false;
            await instance.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: 220 },
                (text) => {
                    if (ticket !== generation.current || accepted) return;
                    try {
                        if (text.length > 2048) throw new Error('Oversized');
                        const credential = JSON.parse(text);
                        if (
                            ![
                                'miconvener.store.collection.v1',
                                'miconvener.store.delivery.v1',
                            ].includes(credential?.purpose) ||
                            typeof credential.pass_id !== 'string' ||
                            typeof credential.secret !== 'string'
                        )
                            throw new Error('Wrong code');
                        accepted = true;
                        callback.current(credential);
                        void stop();
                    } catch {
                        setError(
                            'Use the buyer’s store handover QR code, or enter the manual code below.'
                        );
                    }
                },
                () => {}
            );
            if (ticket !== generation.current) await dispose(instance);
        } catch {
            if (ticket === generation.current) {
                setError(
                    'Camera unavailable or permission denied. You can enter the buyer’s code instead.'
                );
                setActive(false);
            }
            if (instance) await dispose(instance);
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
                    disabled={disabled}
                    onClick={start}
                    className="inline-flex min-h-11 items-center gap-2 border border-border px-4 text-sm disabled:opacity-50"
                >
                    <Camera className="h-4 w-4" aria-hidden="true" />
                    Start camera
                </button>
            )}
            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
