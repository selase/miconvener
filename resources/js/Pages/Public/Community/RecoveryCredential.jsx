import { useEffect, useState } from 'react';
import { flushSync } from 'react-dom';

export default function RecoveryCredential({ credential, onClear }) {
    const [key, setKey] = useState(credential);
    const [message, setMessage] = useState('');
    useEffect(() => {
        const clear = () =>
            flushSync(() => {
                setKey(null);
                onClear?.();
            });
        window.addEventListener('pagehide', clear);
        return () => window.removeEventListener('pagehide', clear);
    }, [onClear]);
    if (!key)
        return (
            <p className="text-sm text-ink-secondary">Your recovery key is no longer displayed.</p>
        );
    const text = `${key.selector}.${key.secret}`;
    async function copy() {
        try {
            await navigator.clipboard.writeText(text);
            setMessage('Copied. Keep this key private.');
        } catch {
            setMessage('Copy was unavailable. Select the key or download it instead.');
        }
    }
    function download() {
        const url = URL.createObjectURL(
            new Blob(
                [
                    `MiConvener private membership recovery key\n\n${text}\n\nKeep this key private. You will also need to verify your phone.\n`,
                ],
                { type: 'text/plain;charset=utf-8' }
            )
        );
        const link = document.createElement('a');
        link.href = url;
        link.download = 'miconvener-private-membership-key.txt';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    return (
        <main className="mx-auto max-w-2xl space-y-6 px-5 py-12 text-ink">
            <p className="text-xs font-medium uppercase tracking-widest text-accent">
                Private membership access
            </p>
            <h1 className="text-3xl font-semibold">Save your recovery key</h1>
            <p>
                We cannot show this key again. Keep it somewhere private. You will need it together
                with a fresh phone verification code to restore this membership after sign-out, on
                another device or when your 12-hour verification expires.
            </p>
            <code className="block break-all rounded-lg border border-border bg-surface-hover p-5 text-sm">
                {text}
            </code>
            <div className="flex flex-wrap gap-3 print:hidden">
                {[
                    ['Copy recovery key', copy],
                    ['Print recovery key', () => window.print()],
                    ['Download recovery key', download],
                ].map(([label, action]) => (
                    <button
                        key={label}
                        type="button"
                        onClick={action}
                        className="rounded-md border border-border bg-surface px-4 py-2 text-sm"
                    >
                        {label}
                    </button>
                ))}
            </div>
            {message && (
                <p role="status" className="text-sm">
                    {message}
                </p>
            )}
            <p className="text-sm text-ink-secondary">
                Anyone with this key and control of your phone may use this membership. If you lose
                it, ask the organisation for an independent access review.
            </p>
            <button
                className="rounded-md bg-accent px-5 py-2 text-sm font-medium text-white print:hidden"
                type="button"
                onClick={() => {
                    setKey(null);
                    onClear?.();
                }}
            >
                I have saved my key
            </button>
        </main>
    );
}
