import { useEffect, useRef, useState } from 'react';
import VerifyPrompt from './AttendeePortal/VerifyPrompt';
import csrfFetch from '@/lib/csrfFetch';

export default function MemberBenefitVerification({
    event,
    email,
    ticketTypeId = null,
    accessCode = '',
    registration = null,
    recoverPayment = false,
    onEligibility,
    onRefreshed,
}) {
    const [verify, setVerify] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    const [eligible, setEligible] = useState(false);
    const [revision, setRevision] = useState(0);
    const callbacks = useRef({ onEligibility, onRefreshed });
    callbacks.current = { onEligibility, onRefreshed };
    const enabled = event.member_benefits?.enabled ?? Boolean(registration);
    const normalEmail = (email ?? '').trim().toLowerCase();
    useEffect(() => {
        if (registration) return undefined;
        callbacks.current.onEligibility?.(null);
        setEligible(false);
        setBusy(false);
        if (!enabled || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalEmail)) return undefined;
        let current = true;
        let expiry;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setBusy(true);
            try {
                const response = await csrfFetch(
                    route('public.events.member-benefits', { event: event.slug }),
                    {
                        method: 'POST',
                        body: JSON.stringify({
                            email: normalEmail,
                            ticket_type_id: ticketTypeId || null,
                            access_code: accessCode,
                        }),
                        signal: controller.signal,
                    }
                );
                const json = await response.json();
                if (!current) return;
                if (!response.ok) throw new Error('Could not check membership. Please try again.');
                setEligible(Boolean(json.eligible));
                callbacks.current.onEligibility?.({ ...json, email: normalEmail });
                setMessage(
                    json.eligible
                        ? 'Your personal member access is verified.'
                        : 'Verify the email recorded on your membership to check your access and price.'
                );
                if (json.verified_until) {
                    expiry = setTimeout(
                        () => {
                            setEligible(false);
                            callbacks.current.onEligibility?.(null);
                            setMessage(
                                'Your membership verification expired. Verify again before continuing.'
                            );
                        },
                        Math.max(0, new Date(json.verified_until).getTime() - Date.now())
                    );
                }
            } catch (error) {
                if (current && error.name !== 'AbortError')
                    setMessage('Could not check membership. Check your connection and try again.');
            } finally {
                if (current) setBusy(false);
            }
        }, 300);
        return () => {
            current = false;
            controller.abort();
            clearTimeout(timer);
            clearTimeout(expiry);
        };
    }, [enabled, normalEmail, event.slug, ticketTypeId, accessCode, revision, registration]);
    if (!enabled && !event.member_benefits?.members_only) return null;
    const verified = async () => {
        setVerify(false);
        setRevision((value) => value + 1);
        if (!registration) return;
        setBusy(true);
        try {
            const response = await csrfFetch(
                route(
                    recoverPayment
                        ? 'public.events.member-benefits.reassess'
                        : 'public.events.member-benefits.reverify',
                    { event: event.slug, registration: registration.id }
                ),
                { method: 'POST' }
            );
            const json = await response.json();
            setMessage(
                json.message ??
                    Object.values(json.errors ?? {}).flat()[0] ??
                    'Membership could not be reverified.'
            );
            if (response.ok) callbacks.current.onRefreshed?.(json);
        } catch {
            setMessage('Could not reach the server. Please try again.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <section
            className="my-4 border border-border bg-surface-alt p-4 text-sm text-ink"
            aria-label="Verify member access"
        >
            <h3 className="font-semibold">
                {event.member_benefits?.members_only
                    ? 'For members of this community'
                    : 'Member access and pricing'}
            </h3>
            <p className="mt-2 leading-6 text-ink-secondary">
                Use your own membership, including memberships without dues. Following an
                organisation does not create a membership.
            </p>
            {!enabled ? (
                <p className="mt-3" role="status">
                    Member registration is temporarily unavailable. Existing tickets and paid
                    recovery remain available.
                </p>
            ) : (
                <>
                    <p className="mt-2 text-ink-secondary" role="status">
                        {busy ? 'Checking member access…' : message}
                    </p>
                    {!eligible && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={() => setVerify((value) => !value)}
                            className="mt-3 font-medium text-accent underline disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-accent"
                        >
                            {verify ? 'Hide email verification' : 'Verify my membership'}
                        </button>
                    )}
                    {verify && (
                        <div className="mt-4">
                            <VerifyPrompt
                                allowPhone={false}
                                autoFocus={false}
                                sendUrl={route('public.events.member-verify.send', {
                                    event: event.slug,
                                })}
                                confirmUrl={route('public.events.member-verify.confirm', {
                                    event: event.slug,
                                })}
                                onVerified={verified}
                            />
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
