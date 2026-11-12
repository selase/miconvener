import { useEffect, useRef, useState } from 'react';
import Button from '@/Components/Console/Button';
import VerifyPrompt from '@/Pages/Public/Events/AttendeePortal/VerifyPrompt';
import ArtifactErrors from '../Certificates/ArtifactErrors';
import csrfFetch from '@/lib/csrfFetch';
import { artifactJson } from '@/lib/artifactResponse';

export default function BadgeTemplatePurchase({
    event,
    versionId,
    draft,
    canPurchase,
    checkoutReady,
    onPurchased,
}) {
    const [payer, setPayer] = useState(null);
    const [purchase, setPurchase] = useState(null);
    const [accepted, setAccepted] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const pending = useRef(false);
    const quoteKey = useRef(crypto.randomUUID());
    const paymentKey = useRef(crypto.randomUUID());
    const call = async (action, body, extra = {}) =>
        artifactJson(
            await csrfFetch(
                route(`tenant.events.badges.purchases.${action}`, { event: event.id, ...extra }),
                body ? { method: 'POST', body: JSON.stringify(body) } : undefined
            ),
            'The purchase could not be updated. Please try again.'
        );
    const run = async (work) => {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        setError(null);
        try {
            await work();
        } catch (reason) {
            setError(reason);
        } finally {
            pending.current = false;
            setBusy(false);
        }
    };
    const refresh = async (id = purchase?.purchase_id) => {
        if (!id) return;
        const result = await call('show', null, { purchase: id });
        setPurchase(result);
        if (result.state === 'paid' && result.licence_state === 'active') onPurchased?.(result);
    };
    useEffect(() => {
        if (!canPurchase) return undefined;
        let current = true;
        const recover = async () => {
            try {
                const proof = await call('payer');
                const saved = await call('draft');
                if (!current) return;
                setPayer(proof.verified_email);
                if (
                    saved.draft?.version_id === (draft.version_id || versionId) &&
                    saved.draft?.purchase_id
                ) {
                    const result = await call('show', null, { purchase: saved.draft.purchase_id });
                    if (current) setPurchase(result);
                }
            } catch (reason) {
                if (current) setError(reason);
            }
        };
        recover();
        return () => {
            current = false;
        };
    }, [event.id, versionId, canPurchase]);
    useEffect(() => {
        const focus = () => {
            if (purchase?.purchase_id) run(() => refresh());
        };
        window.addEventListener('focus', focus);
        return () => window.removeEventListener('focus', focus);
    }, [purchase?.purchase_id]);
    const review = () =>
        run(async () => {
            const result = await call('store', {
                version_id: versionId,
                operation_key: quoteKey.current,
            });
            await call('save-draft', {
                ...draft,
                version_id: draft.version_id || versionId,
                purchase_id: result.purchase_id,
            });
            setPurchase(result);
            setAccepted(false);
        });
    const pay = () =>
        run(async () => {
            if (!accepted || !purchase) return;
            const result = await call(
                'checkout',
                {
                    version_id: purchase.version_id,
                    operation_key: paymentKey.current,
                    accepted_terms_hash: purchase.terms_hash,
                },
                { purchase: purchase.purchase_id }
            );
            if (result.status === 'failed') paymentKey.current = crypto.randomUUID();
            if (result.checkout_url) window.location.assign(result.checkout_url);
            else await refresh();
        });
    const quoteExpired =
        Boolean(purchase?.expires_at) && Date.parse(purchase.expires_at) <= Date.now();
    const unresolved =
        ['unknown', 'initializing', 'initialising', 'submitted', 'review'].includes(
            purchase?.attempt_state
        ) ||
        ['review', 'captured_review', 'refund_pending'].includes(purchase?.payment_state) ||
        purchase?.licence_state === 'suspended' ||
        (purchase?.attempt_state === 'ready' && quoteExpired);
    return (
        <section
            className="space-y-4 border border-border bg-surface p-4"
            aria-label="Paid template purchase"
        >
            <ArtifactErrors error={error} />
            <h4 className="font-semibold">GH₵50 for this event</h4>
            <p className="text-sm text-ink-secondary">
                Unlimited attendees, edits and reprints for {event.name}. Applying a design keeps
                the current version in saved history.
            </p>
            {!canPurchase ? (
                <p className="text-sm">
                    Ask an authorised purchaser for this event to buy the licence.
                </p>
            ) : purchase?.state === 'paid' && purchase?.licence_state === 'active' ? (
                <>
                    <p role="status">
                        Purchased for this event. Choose Apply to replace the current design.
                    </p>
                    <Button onClick={() => onPurchased?.(purchase)}>Continue to Apply</Button>
                </>
            ) : unresolved ? (
                <p role="status">
                    Your payment is being checked. Refresh its status before making another payment.
                </p>
            ) : !checkoutReady ? (
                <p className="text-sm">
                    Paid checkout is currently unavailable. You can keep previewing or choose a free
                    design.
                </p>
            ) : !payer ? (
                <div>
                    <p className="mb-3 text-sm">Verify the email that will own the receipt.</p>
                    <VerifyPrompt
                        allowPhone={false}
                        sendUrl={route('tenant.events.badges.purchases.verify-payer', {
                            event: event.id,
                        })}
                        confirmUrl={route('tenant.events.badges.purchases.verify-payer', {
                            event: event.id,
                        })}
                        onVerified={setPayer}
                    />
                </div>
            ) : purchase &&
              (['refunded', 'cancelled'].includes(purchase.state) ||
                  (purchase.expires_at && Date.parse(purchase.expires_at) <= Date.now())) ? (
                <div className="space-y-3">
                    <p className="text-sm">
                        The previous licence purchase is{' '}
                        {purchase.state === 'pending' ? 'expired' : purchase.state}. Review a new
                        quote before paying.
                    </p>
                    <Button
                        disabled={busy}
                        onClick={() => {
                            quoteKey.current = crypto.randomUUID();
                            paymentKey.current = crypto.randomUUID();
                            review();
                        }}
                    >
                        Review a new GH₵50 licence
                    </Button>
                </div>
            ) : !purchase ? (
                <Button disabled={busy} onClick={review}>
                    Review GH₵50 purchase
                </Button>
            ) : (
                <>
                    <p className="text-sm">{purchase.terms.event_name} · GH₵50 total</p>
                    <dl className="space-y-2 border border-border p-3 text-sm">
                        <div>
                            <dt className="font-medium">Seller</dt>
                            <dd>{purchase.terms.seller_name}</dd>
                        </div>
                        <div>
                            <dt className="font-medium">Support</dt>
                            <dd>{purchase.terms.support_contact}</dd>
                        </div>
                        <div>
                            <dt className="font-medium">Receipt terms</dt>
                            <dd>{purchase.terms.receipt_terms}</dd>
                        </div>
                    </dl>
                    <p className="text-sm">{purchase.terms.licence}</p>
                    <p className="text-sm text-ink-secondary">{purchase.terms.refund_terms}</p>
                    <label className="flex gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={accepted}
                            onChange={(e) => setAccepted(e.target.checked)}
                        />
                        I accept these event licence and refund terms.
                    </label>
                    <Button disabled={busy || !accepted} onClick={pay}>
                        Pay GH₵50
                    </Button>
                </>
            )}
            {purchase?.attempt_state === 'failed' && (
                <p role="status" className="text-sm">
                    Payment could not start. Review the payer email and try again.
                </p>
            )}
            {purchase && (
                <Button
                    className="block"
                    variant="default"
                    disabled={busy}
                    onClick={() => run(() => refresh())}
                >
                    Refresh payment status
                </Button>
            )}
            {payer && canPurchase && (
                <Button variant="default" disabled={busy} onClick={() => setPayer(null)}>
                    Verify receipt email again
                </Button>
            )}
            {purchase?.receipt && (
                <div className="space-y-2 border-t border-border pt-3">
                    <h5 className="font-semibold">Licence receipt</h5>
                    <p className="text-sm">{purchase.receipt.number} · GH₵50</p>
                    <p className="text-sm">{purchase.receipt.refund_terms}</p>
                    <p className="text-sm">Support · {purchase.receipt.support_contact}</p>
                </div>
            )}
            {['suspended', 'refunded'].includes(purchase?.licence_state) && (
                <p role="status" className="text-sm">
                    Licence {purchase.licence_state}. Clean output requires an active licence.
                    Choose a free design or contact support.
                </p>
            )}
        </section>
    );
}
