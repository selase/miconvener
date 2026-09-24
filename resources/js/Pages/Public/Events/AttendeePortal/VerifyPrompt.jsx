import { useEffect, useRef, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

const RESEND_AFTER_SECONDS = 60;

const INPUT_CLASS =
    'w-full border border-border bg-surface px-3 py-2 text-[13px] text-ink focus:border-accent focus:outline-none';
const PRIMARY_CLASS =
    'inline-flex items-center rounded-lg bg-accent px-5 py-2.5 text-[13.5px] font-medium text-white hover:opacity-90 disabled:opacity-60 cursor-pointer disabled:cursor-not-allowed';

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
    // Turnstile challenge state
    const [challengeRequired, setChallengeRequired] = useState(false);
    const [siteKey, setSiteKey] = useState(null);
    const [challengeAction, setChallengeAction] = useState(null);
    const [turnstileToken, setTurnstileToken] = useState(null);
    const turnstileWidgetId = useRef(null);

    // Cooldown interval
    const counting = wait > 0;
    useEffect(() => {
        if (!counting) {
            return undefined;
        }
        const timer = setInterval(() => setWait((left) => Math.max(0, left - 1)), 1000);
        return () => clearInterval(timer);
    }, [counting]);

    const who = registrationId ? { registration: registrationId } : { email };
    // Turnstile script and widget management
    useEffect(() => {
        if (!challengeRequired || !siteKey) {
            return undefined;
        }

        const renderWidget = () => {
            if (window.turnstile && document.getElementById('turnstile-container')) {
                if (turnstileWidgetId.current !== null) {
                    window.turnstile.reset(turnstileWidgetId.current);
                } else {
                    turnstileWidgetId.current = window.turnstile.render('#turnstile-container', {
                        sitekey: siteKey,
                        // Named by the server so the two cannot drift: the same
                        // string is what it checks the token back against.
                        action: challengeAction ?? undefined,
                        callback: (token) => {
                            setTurnstileToken(token);
                            setMessage(null);
                        },
                        'error-callback': () => {
                            setMessage('Challenge verification error. Please retry.');
                        },
                    });
                }
            }
        };

        if (window.turnstile) {
            renderWidget();
        } else {
            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.onload = renderWidget;
            document.head.appendChild(script);
        }
    }, [challengeRequired, siteKey, challengeAction]);

    const sendRoute = window.route ? route('attendee.my.verify.send') : '/my/verify/send';
    const confirmRoute = window.route ? route('attendee.my.verify.confirm') : '/my/verify/confirm';

    const send = async (e) => {
        e?.preventDefault();
        setBusy(true);
        setMessage(null);

        const payload = registrationId
            ? { registration: registrationId }
            : { email, turnstile_token: turnstileToken };

        try {
            const response = await csrfFetch(sendRoute, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const json = await response.json().catch(() => ({}));

            if (response.status === 428) {
                setChallengeRequired(true);
                setSiteKey(json.site_key);
                setChallengeAction(json.action ?? null);
                setMessage('Please complete the verification challenge below.');
                return;
            }

            if (response.status === 429) {
                setMessage('Too many attempts just now. Try again later.');
                return;
            }

            if (!response.ok) {
                const errorMsg = json.errors?.email?.[0] ?? json.message ?? "Couldn't send the code. Please try again.";
                setMessage(errorMsg);
                return;
            }

            setMessage(json.message ?? `A sign-in code was sent to ${email || 'your email'}.`);
            setStage('code');
            setWait(RESEND_AFTER_SECONDS);
            setChallengeRequired(false);
            setTurnstileToken(null);
        } catch {
            setMessage(OFFLINE_MESSAGE);
        } finally {
            setBusy(false);
        }
    };

    const confirm = async (e) => {
        e.preventDefault();
        setBusy(true);
        setMessage(null);

        const payload = registrationId
            ? { registration: registrationId, code }
            : { email, code };

        try {
            const response = await csrfFetch(confirmRoute, {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const json = await response.json().catch(() => ({}));

            if (response.ok) {
                onVerified(json.email);
                return;
            }

            if (response.status === 429) {
                setMessage('Too many verification attempts. Please try again later.');
                return;
            }

            setMisses((m) => m + 1);
            setMessage(json.message ?? "That code didn't match. Check it, or request a new one.");
        } catch {
            setMessage(OFFLINE_MESSAGE);
        } finally {
            setBusy(false);
        }
    };

    const switchAddress = () => {
        setStage('address');
        setCode('');
        setMessage(null);
        setMisses(0);
        setChallengeRequired(false);
        setTurnstileToken(null);
    };

    if (stage === 'address') {
        return (
            <form onSubmit={send} className="space-y-4">
                <div>
                    <label htmlFor="verify-email" className="block text-[13px] font-medium text-ink-secondary">
                        Your email address
                    </label>
                    <input
                        id="verify-email"
                        type="email"
                        required
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="you@example.com"
                        className={`mt-1.5 ${INPUT_CLASS}`}
                    />
                </div>

                {challengeRequired && (
                    <div className="py-2">
                        <div id="turnstile-container" className="flex justify-center" />
                    </div>
                )}

                <button
                    type="submit"
                    disabled={busy || (challengeRequired && !turnstileToken)}
                    className={PRIMARY_CLASS}
                >
                    {busy ? 'Sending…' : 'Email me a code'}
                </button>

                <div aria-live="polite">
                    {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
                </div>
            </form>
        );
    }

    if (stage === 'ready') {
        return (
            <div className="space-y-4">
                <p className="text-[13.5px] text-ink-secondary">
                    To see everything you have with this organiser, confirm it's you. We'll email a
                    code to {sentTo ?? 'the address on this registration'}.
                </p>
                <button type="button" onClick={send} disabled={busy} className={PRIMARY_CLASS}>
                    {busy ? 'Sending…' : 'Email me a code'}
                </button>
                <div aria-live="polite">
                    {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={confirm} className="space-y-4">
            <div aria-live="polite">
                {message && <p className="text-[13px] text-ink-secondary">{message}</p>}
            </div>

            <div>
                <label htmlFor="verify-code" className="block text-[13px] font-medium text-ink-secondary">
                    Verification code
                </label>
                <input
                    id="verify-code"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    required
                    value={code}
                    onChange={(e) => setCode(e.target.value)}
                    placeholder="123456"
                    className={`mt-1.5 ${INPUT_CLASS}`}
                />
            </div>

            <div className="flex flex-wrap items-center gap-4">
                <button type="submit" disabled={busy} className={PRIMARY_CLASS}>
                    Confirm
                </button>
                <button
                    type="button"
                    onClick={send}
                    disabled={wait > 0 || busy}
                    className="text-[12.5px] text-accent underline hover:opacity-80 disabled:text-ink-secondary disabled:no-underline cursor-pointer disabled:cursor-not-allowed"
                >
                    {wait > 0 ? `Send another code in ${wait}s` : 'Send another code'}
                </button>
                <button
                    type="button"
                    onClick={switchAddress}
                    className="text-[12.5px] text-ink-secondary hover:text-ink underline cursor-pointer"
                >
                    Use a different email address
                </button>
            </div>

            {misses >= 2 && (
                <p className="text-[12.5px] text-ink-secondary">
                    After five wrong tries a code stops working. Ask for a new one if you need to.
                </p>
            )}
        </form>
    );
}
