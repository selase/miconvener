import { useEffect, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

const RESEND_AFTER_SECONDS = 60;

const INPUT_CLASS =
    'w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none';
const PRIMARY_CLASS =
    'inline-flex items-center rounded-lg bg-accent px-5 py-2.5 text-[13.5px] font-medium text-white hover:opacity-90 disabled:opacity-60';

const OFFLINE_MESSAGE = "Couldn't reach the server. Check your connection and try again.";

/**
 * Proves an address with an emailed code. Given a registration, the code goes
 * to that registration's own address and nothing is typed; otherwise it goes
 * to the address entered here.
 */
export default function VerifyPrompt({ registrationId = null, sentTo = null, onVerified }) {
    const [stage, setStage] = useState(registrationId ? 'ready' : 'address');
    const [email, setEmail] = useState('');
    const [code, setCode] = useState('');
    const [message, setMessage] = useState(null);
    const [busy, setBusy] = useState(false);
    const [misses, setMisses] = useState(0);
    const [wait, setWait] = useState(0);

    // One interval for the whole countdown, updated functionally. Re-arming a
    // timeout on every tick would depend on React flushing between ticks.
    const counting = wait > 0;
    useEffect(() => {
        if (!counting) {
            return undefined;
        }
        const timer = setInterval(() => setWait((left) => Math.max(0, left - 1)), 1000);
        return () => clearInterval(timer);
    }, [counting]);

    const who = registrationId ? { registration: registrationId } : { email };

    const send = async (e) => {
        e?.preventDefault();
        setBusy(true);
        try {
            const response = await csrfFetch(route('public.my.verify.send'), {
                method: 'POST',
                body: JSON.stringify(who),
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok) {
                setMessage('Too many attempts just now. Try again in a minute.');
                return;
            }
            setMessage(json.message);
            setStage('code');
            setWait(RESEND_AFTER_SECONDS);
        } catch {
            setMessage(OFFLINE_MESSAGE);
        } finally {
            setBusy(false);
        }
    };

    const confirm = async (e) => {
        e.preventDefault();
        setBusy(true);
        try {
            const response = await csrfFetch(route('public.my.verify.confirm'), {
                method: 'POST',
                body: JSON.stringify({ ...who, code }),
            });
            const json = await response.json().catch(() => ({}));

            if (response.ok) {
                onVerified(json.email);
                return;
            }
            setMisses(misses + 1);
            setMessage(json.message ?? "That code didn't match.");
        } catch {
            setMessage(OFFLINE_MESSAGE);
        } finally {
            setBusy(false);
        }
    };

    if (stage === 'address') {
        return (
            <form onSubmit={send} className="space-y-3">
                <label htmlFor="verify-email" className="block text-[13px] text-ink-secondary">
                    The address you registered with
                </label>
                <input
                    id="verify-email"
                    type="email"
                    required
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className={INPUT_CLASS}
                />
                <button type="submit" disabled={busy} className={PRIMARY_CLASS}>
                    {busy ? 'Sending…' : 'Email me a code'}
                </button>
                {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            </form>
        );
    }

    if (stage === 'ready') {
        return (
            <div className="space-y-3">
                <p className="text-[13.5px] text-ink-secondary">
                    To see everything you have with this organiser, confirm it's you. We'll email a
                    code to {sentTo ?? 'the address on this registration'}.
                </p>
                <button type="button" onClick={send} disabled={busy} className={PRIMARY_CLASS}>
                    {busy ? 'Sending…' : 'Email me a code'}
                </button>
                {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            </div>
        );
    }

    return (
        <form onSubmit={confirm} className="space-y-3">
            {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            <label htmlFor="verify-code" className="block text-[13px] text-ink-secondary">
                Code
            </label>
            <input
                id="verify-code"
                inputMode="numeric"
                autoComplete="one-time-code"
                required
                value={code}
                onChange={(e) => setCode(e.target.value)}
                className={INPUT_CLASS}
            />
            <div className="flex items-center gap-4">
                <button type="submit" disabled={busy} className={PRIMARY_CLASS}>
                    Confirm
                </button>
                <button
                    type="button"
                    onClick={send}
                    disabled={wait > 0 || busy}
                    className="text-[12.5px] text-accent underline disabled:text-ink-secondary disabled:no-underline"
                >
                    {wait > 0 ? `Send another code in ${wait}s` : 'Send another code'}
                </button>
            </div>
            {misses >= 2 && (
                <p className="text-[12.5px] text-ink-secondary">
                    After five wrong tries a code stops working. Ask for a new one if you need to.
                </p>
            )}
            <p className="text-[12.5px] text-ink-secondary">
                Wrong address? The organiser can correct it for you.
            </p>
        </form>
    );
}
