import { useEffect, useRef, useState } from 'react';
import { Clock3, QrCode, RefreshCw } from 'lucide-react';
import csrfFetch from '@/lib/csrfFetch';
import { STORE_BUTTON } from '@/Components/Marketplace/StoreCheckout';

export default function StoreHandoverPass({ order, verifiedUntil, onRefresh }) {
    const [pass, setPass] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [now, setNow] = useState(Date.now());
    const pending = useRef(null);
    const key = useRef(crypto.randomUUID());
    const expiry = useRef(null);
    const alert = useRef(null);
    const refresh = useRef(onRefresh);
    refresh.current = onRefresh;
    const verification = useRef(verifiedUntil);
    verification.current = verifiedUntil;
    const currentOrder = useRef(order.id);
    currentOrder.current = order.id;
    const delivery = order.fulfilment_method === 'delivery';
    useEffect(() => {
        if (error) alert.current?.focus();
    }, [error]);
    useEffect(() => {
        const clear = () => {
            pending.current?.abort();
            setPass(null);
            setBusy(false);
        };
        const visibility = () => {
            if (document.visibilityState === 'hidden') clear();
            else refresh.current?.();
        };
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('pagehide', clear);
        return () => {
            clear();
            document.removeEventListener('visibilitychange', visibility);
            window.removeEventListener('pagehide', clear);
        };
    }, [order.id]);
    useEffect(() => {
        pending.current?.abort();
        setPass(null);
        setBusy(false);
        key.current = crypto.randomUUID();
    }, [order.id, order.can_issue_pass, order.fulfilment_revision]);
    useEffect(() => {
        if (!pass) return;
        const tick = () => {
            const time = Date.now();
            setNow(time);
            if (
                time >= Date.parse(pass.expires_at) ||
                (verifiedUntil && time >= Number(verifiedUntil) * 1000)
            ) {
                setPass(null);
                key.current = crypto.randomUUID();
            }
        };
        tick();
        const timer = setInterval(tick, 1000);
        return () => clearInterval(timer);
    }, [pass, verifiedUntil]);
    const issue = async (replace = false) => {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        const orderId = order.id;
        if (replace || (expiry.current && expiry.current <= Date.now()))
            key.current = crypto.randomUUID();
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(`/my/store-orders/${orderId}/collection-pass`, {
                method: 'POST',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ operation_key: key.current }),
            });
            const data = await response.json().catch(() => ({}));
            if (
                controller.signal.aborted ||
                currentOrder.current !== orderId ||
                document.visibilityState === 'hidden' ||
                (verification.current && Date.now() >= Number(verification.current) * 1000)
            )
                return;
            if (!response.ok) {
                if ([401, 403, 404].includes(response.status)) refresh.current?.();
                if (response.status === 422) key.current = crypto.randomUUID();
                throw new Error(
                    Object.values(data.errors ?? {}).flat()[0] ??
                        data.message ??
                        'Pass unavailable. Refresh the order before trying again.'
                );
            }
            if (!data.qr_svg || !data.manual_code || !Number.isFinite(Date.parse(data.expires_at)))
                throw new Error('The pass could not be displayed. Please retry.');
            expiry.current = Date.parse(data.expires_at);
            setNow(Date.now());
            setPass({ ...data, order_id: orderId });
        } catch (failure) {
            if (!controller.signal.aborted) {
                setPass(null);
                setError(failure.message);
            }
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    };
    const visible = pass?.order_id === order.id && Date.parse(pass.expires_at) > now;
    const seconds = visible
        ? Math.max(0, Math.ceil((Date.parse(pass.expires_at) - now) / 1000))
        : 0;
    return (
        <section className="space-y-4 border border-border bg-surface p-5 sm:p-6">
            <div className="flex items-start gap-3">
                <QrCode aria-hidden="true" className="mt-1 h-5 w-5 shrink-0 text-accent" />
                <div>
                    <h3 className="font-semibold">
                        {delivery ? 'Delivery verification' : 'Collect your order'}
                    </h3>
                    <p className="mt-2 text-sm leading-6 text-ink-secondary">
                        Your order remains available until collected or delivered. Request a code
                        when you are ready for handover.
                    </p>
                </div>
            </div>
            {order.can_issue_pass && !visible && (
                <button
                    type="button"
                    className={STORE_BUTTON}
                    disabled={busy}
                    onClick={() => issue()}
                >
                    {busy
                        ? 'Preparing pass…'
                        : delivery
                          ? 'Show delivery code'
                          : 'Show collection pass'}
                </button>
            )}
            {!order.can_issue_pass && (
                <p role="status" className="text-sm text-ink-secondary">
                    {order.fulfilment_paused
                        ? 'Fulfilment is paused. Contact the seller for help.'
                        : ['collected', 'delivered'].includes(order.fulfilment_state)
                          ? 'Handover is complete. Your receipt remains available.'
                          : delivery && order.fulfilment_state === 'ready'
                            ? 'Your order is ready and awaiting dispatch. A delivery code is available once dispatched.'
                            : 'A handover code will be available when this order is ready and eligible.'}
                </p>
            )}
            {visible && (
                <div className="space-y-4 border-t border-border pt-5">
                    <div className="mx-auto max-w-64 border border-border bg-white p-3">
                        <img
                            className="aspect-square w-full"
                            src={pass.qr_svg}
                            alt={
                                delivery
                                    ? 'Delivery verification QR code'
                                    : 'Collection pass QR code'
                            }
                        />
                    </div>
                    <div className="text-center">
                        <p className="text-xs uppercase tracking-wider text-ink-secondary">
                            Or give this code to the seller
                        </p>
                        <p className="mt-2 select-all break-words font-mono text-2xl font-semibold tracking-wide text-ink">
                            {pass.manual_code}
                        </p>
                    </div>
                    <p
                        className="flex items-center justify-center gap-2 text-sm text-ink-secondary"
                        role="timer"
                        aria-live="off"
                    >
                        <Clock3 className="h-4 w-4" aria-hidden="true" />
                        Valid for {Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}
                        . Expiry does not cancel your order.
                    </p>
                    <p className="border-l-2 border-accent bg-accent/5 p-4 text-sm leading-6">
                        Anyone you share this pass with can{' '}
                        {delivery ? 'authorise receipt of' : 'collect'} your entire order.
                    </p>
                    <button
                        type="button"
                        className="inline-flex min-h-11 items-center gap-2 text-sm text-accent underline"
                        disabled={busy}
                        onClick={() => issue(true)}
                    >
                        <RefreshCw className="h-4 w-4" aria-hidden="true" />
                        Replace this pass
                    </button>
                    <p className="text-xs text-ink-secondary">
                        Replacing the pass stops the previous code from working.
                    </p>
                </div>
            )}
            {error && (
                <p role="alert" ref={alert} tabIndex={-1} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </section>
    );
}
