import { useEffect, useRef, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

export default function PhoneVerify({ organiserSlug, onVerified, onUseEmail }) {
    const [organisation, setOrganisation] = useState(organiserSlug ?? '');
    const [phone, setPhone] = useState('');
    const [code, setCode] = useState('');
    const [sent, setSent] = useState(false);
    const [wait, setWait] = useState(0);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [challenge, setChallenge] = useState(null);
    const [token, setToken] = useState(null);
    const challengeElement = useRef(null);
    useEffect(() => {
        if (wait <= 0) return undefined;
        const timer = setTimeout(() => setWait((value) => Math.max(0, value - 1)), 1000);
        return () => clearTimeout(timer);
    }, [wait]);
    useEffect(() => {
        if (!challenge?.site_key) return undefined;
        let widget = null;
        let disposed = false;
        function renderChallenge() {
            if (disposed || !window.turnstile || !challengeElement.current) return;
            widget = window.turnstile.render(challengeElement.current, {
                sitekey: challenge.site_key,
                action: challenge.action,
                callback: setToken,
                'error-callback': () =>
                    setMessage(
                        'The verification challenge could not load. Try again or use email.'
                    ),
            });
        }
        let script;
        if (window.turnstile) renderChallenge();
        else {
            script = document.querySelector(
                'script[src^="https://challenges.cloudflare.com/turnstile/v0/api.js"]'
            );
            if (!script) {
                script = document.createElement('script');
                script.src =
                    'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                document.head.appendChild(script);
            }
            script.addEventListener('load', renderChallenge);
        }
        return () => {
            disposed = true;
            if (widget !== null && window.turnstile) window.turnstile.remove(widget);
            if (script) script.removeEventListener('load', renderChallenge);
        };
    }, [challenge]);
    const endpoint = (kind) =>
        window.route ? route(`attendee.my.phone.${kind}`) : `/my/verify/phone/${kind}`;
    async function send(event) {
        event?.preventDefault();
        setBusy(true);
        setMessage('');
        try {
            const response = await csrfFetch(endpoint('send'), {
                method: 'POST',
                body: JSON.stringify({ organiser: organisation, phone, turnstile_token: token }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 428) {
                setChallenge(data);
                setToken(null);
                setMessage('Complete the verification challenge below.');
                return;
            }
            if (response.status === 429) {
                setWait(60);
                setMessage('Too many attempts. Wait before trying again, or use email.');
                return;
            }
            if (!response.ok) {
                setMessage(
                    Object.values(data.errors ?? {}).flat()[0] ??
                        data.message ??
                        'Phone sign-in is unavailable. Try email or return later.'
                );
                return;
            }
            setSent(true);
            setCode('');
            setWait(60);
            setMessage(data.message ?? 'Your verification code is on its way.');
        } catch {
            setMessage('Could not reach the server. Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    }
    async function confirm(event) {
        event.preventDefault();
        setBusy(true);
        setMessage('');
        try {
            const response = await csrfFetch(endpoint('confirm'), {
                method: 'POST',
                body: JSON.stringify({ organiser: organisation, phone, code }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data.verified) {
                onVerified(null, 'phone', organisation);
                return;
            }
            setMessage(
                response.status === 429
                    ? 'Too many attempts. Try again later.'
                    : (data.message ??
                          'That code is invalid or expired. Check it or request another.')
            );
        } catch {
            setMessage('Could not reach the server. Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    }
    return (
        <section className="space-y-4">
            <p className="text-sm text-ink-secondary">
                Phone verification can be used for following or requesting membership access. It
                does not open your tickets or identify historical memberships.
            </p>
            {!sent ? (
                <form onSubmit={send} className="space-y-4">
                    {!organiserSlug && (
                        <label className="block text-sm font-medium text-ink">
                            Organisation short name
                            <input
                                required
                                value={organisation}
                                onChange={(event) => setOrganisation(event.target.value)}
                                placeholder="From the organisation’s link, for example purpledot"
                                maxLength={100}
                                className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                            />
                        </label>
                    )}
                    <label className="block text-sm font-medium text-ink">
                        Phone number
                        <input
                            autoFocus
                            required
                            type="tel"
                            autoComplete="tel"
                            value={phone}
                            onChange={(event) => setPhone(event.target.value)}
                            placeholder="0241234567 or +233241234567"
                            maxLength={32}
                            className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                        />
                    </label>
                    {challenge && <div ref={challengeElement} />}
                    <button
                        disabled={busy || wait > 0 || (challenge && !token)}
                        type="submit"
                        className="rounded-lg bg-accent px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {wait > 0
                            ? `Try again in ${wait}s`
                            : busy
                              ? 'Sending…'
                              : 'Send verification code'}
                    </button>
                </form>
            ) : (
                <form onSubmit={confirm} className="space-y-4">
                    <label className="block text-sm font-medium text-ink">
                        Verification code
                        <input
                            autoFocus
                            required
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            pattern="[0-9]{6}"
                            maxLength={6}
                            value={code}
                            onChange={(event) => setCode(event.target.value)}
                            className="mt-2 block w-full rounded-md border border-border bg-surface px-3 py-2 text-ink"
                        />
                    </label>
                    <div className="flex flex-wrap gap-3">
                        <button
                            type="submit"
                            disabled={busy || code.length !== 6}
                            className="rounded-lg bg-accent px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                        >
                            Verify code
                        </button>
                        <button
                            type="button"
                            disabled={busy || wait > 0}
                            onClick={send}
                            className="text-sm font-medium text-accent underline disabled:opacity-50"
                        >
                            {wait > 0 ? `Resend in ${wait}s` : 'Resend code'}
                        </button>
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            setSent(false);
                            setCode('');
                            setMessage('');
                            setChallenge(null);
                            setToken(null);
                        }}
                        className="text-sm text-accent underline"
                    >
                        Use a different phone number
                    </button>
                </form>
            )}
            {message && (
                <p role="status" className="text-sm text-ink-secondary">
                    {message}
                </p>
            )}
            <button
                type="button"
                onClick={onUseEmail}
                className="text-sm font-medium text-accent underline"
            >
                Use email instead
            </button>
        </section>
    );
}
