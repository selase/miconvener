import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { CheckCircle2, Package, RefreshCw, Truck } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Modal from '@/Components/Console/Modal';
import StoreCollectionScanner from '@/Components/Marketplace/StoreCollectionScanner';
import csrfFetch from '@/lib/csrfFetch';

const button =
    'inline-flex min-h-11 items-center justify-center gap-2 border border-border px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-accent';
const field =
    'block w-full min-w-0 border border-border bg-surface px-3 py-3 text-ink focus:outline-accent';
export default function StoreCollections({
    orders = { data: [], current_page: 1, last_page: 1 },
    enabled: initialEnabled = false,
    orderId = null,
}) {
    const [online, setOnline] = useState(navigator.onLine !== false);
    useEffect(() => {
        const update = () => setOnline(navigator.onLine !== false);
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, []);
    const [list, setList] = useState(orders);
    const [enabled, setEnabled] = useState(initialEnabled);
    const [selected, setSelected] = useState(null);
    const [detail, setDetail] = useState(null);
    const [method, setMethod] = useState('all');
    const methodRef = useRef('all');
    const [filter, setFilter] = useState('all');
    const [manual, setManual] = useState('');
    const [credential, setCredential] = useState(null);
    const [matched, setMatched] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [dialog, setDialog] = useState(null);
    const [reason, setReason] = useState('');
    const [acknowledged, setAcknowledged] = useState(false);
    const pending = useRef(null);
    const operation = useRef(crypto.randomUUID());
    const current = useRef(null);
    const filterRef = useRef('all');
    const alert = useRef(null);
    const heading = useRef(null);
    const focusResult = useRef(false);
    const validating = useRef(false);
    const recording = useRef(false);
    const cameraActive = useRef(false);
    const matchedResult = useRef(null);
    useEffect(() => {
        if (matched) matchedResult.current?.focus();
    }, [matched]);
    useEffect(() => {
        if (!dialog && focusResult.current) {
            focusResult.current = false;
            heading.current?.focus();
        }
    }, [detail, dialog]);
    useEffect(() => {
        if (error) alert.current?.focus();
    }, [error]);
    const clearCode = () => {
        setCredential(null);
        setManual('');
        setMatched(false);
        setAcknowledged(false);
        setDialog(null);
    };
    const request = async (url, body) => {
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        const response = await csrfFetch(url, {
            method: body ? 'POST' : 'GET',
            signal: controller.signal,
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            ...(body ? { body: JSON.stringify(body) } : {}),
        });
        const data = await response.json().catch(() => ({}));
        if (controller.signal.aborted) return null;
        if (!response.ok) {
            if ([401, 403].includes(response.status)) {
                setList({ data: [] });
                setDetail(null);
                clearCode();
            }
            throw new Error(
                Object.values(data.errors ?? {}).flat()[0] ??
                    data.message ??
                    'Could not complete this action. Refresh the order status before retrying.'
            );
        }
        return data;
    };
    const load = async (id) => {
        setBusy(true);
        try {
            const data = await request(`/store/collections/${id}`);
            if (data && current.current === id) {
                setDetail(data.order);
                setEnabled(data.enabled);
            }
        } catch (failure) {
            if (failure.name !== 'AbortError') setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    const refresh = async (page = 1, state = filterRef.current) => {
        setBusy(true);
        try {
            const data = await request(
                `/store/collections?page=${page}&state=${encodeURIComponent(state)}&method=${encodeURIComponent(methodRef.current)}`
            );
            if (data) {
                setList(data.orders);
                setEnabled(data.enabled);
            }
            if (current.current) await load(current.current);
        } catch (failure) {
            if (failure.name !== 'AbortError') setError(failure.message);
        } finally {
            setBusy(false);
        }
    };
    useEffect(() => {
        const visibility = () => {
            if (document.visibilityState === 'hidden') {
                pending.current?.abort();
                clearCode();
                setDetail(null);
                setBusy(false);
            } else void refresh();
        };
        const focus = () => {
            if (
                document.visibilityState !== 'hidden' &&
                !validating.current &&
                !recording.current &&
                !cameraActive.current
            )
                void refresh();
        };
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('focus', focus);
        return () => {
            pending.current?.abort();
            document.removeEventListener('visibilitychange', visibility);
            window.removeEventListener('focus', focus);
        };
    }, []);
    const choose = (id) => {
        pending.current?.abort();
        current.current = id;
        setSelected(id);
        setDetail(null);
        clearCode();
        setError('');
        operation.current = crypto.randomUUID();
        void load(id);
    };
    useEffect(() => {
        if (orderId) choose(orderId);
    }, [orderId]);
    const match = async (proof) => {
        const id = current.current;
        if (!id || !enabled || !online || validating.current || recording.current) return false;
        validating.current = true;
        setBusy(true);
        setError('');
        setMatched(false);
        setCredential(proof);
        operation.current = crypto.randomUUID();
        try {
            const data = await request(`/store/collections/${id}/validate`, { credential: proof });
            if (data && current.current === id) {
                setDetail(data.order);
                setMatched(true);
                return true;
            }
        } catch (failure) {
            if (failure.name !== 'AbortError') setError(failure.message);
        } finally {
            validating.current = false;
            setBusy(false);
        }
        return false;
    };
    const open = (action) => {
        setDialog(action);
        setReason('');
        setAcknowledged(false);
        setError('');
        if (action !== 'confirm') operation.current = crypto.randomUUID();
    };
    const perform = async (event) => {
        event.preventDefault();
        if (busy || !online || !current.current) return;
        if (
            (['prepare', 'delivery-failed', 'returned'].includes(dialog) && !reason.trim()) ||
            (['confirm', 'returned'].includes(dialog) && !acknowledged)
        )
            return;
        const id = current.current;
        recording.current = true;
        setBusy(true);
        setError('');
        const body = {
            operation_key: operation.current,
            ...(['prepare', 'delivery-failed', 'returned'].includes(dialog) ? { reason } : {}),
            ...(dialog === 'confirm' ? { credential, all_items_handed_over: acknowledged } : {}),
            ...(dialog === 'returned' ? { all_items_returned_to_seller: acknowledged } : {}),
        };
        try {
            const data = await request(`/store/collections/${id}/${dialog}`, body);
            if (data && current.current === id) {
                focusResult.current = true;
                setDetail(data.order);
                setList((previous) => ({
                    ...previous,
                    data: previous.data.map((row) =>
                        row.id === id
                            ? {
                                  ...row,
                                  fulfilment_state: data.order.fulfilment_state,
                                  label: data.order.label,
                              }
                            : row
                    ),
                }));
                clearCode();
                setDialog(null);
            }
        } catch (failure) {
            if (failure.name !== 'AbortError') {
                setError(failure.message);
                setMatched(false);
                await load(id);
            }
        } finally {
            recording.current = false;
            setBusy(false);
        }
    };
    const complete = detail && ['collected', 'delivered'].includes(detail.fulfilment_state);
    const title = {
        ready: 'Mark the whole order ready',
        prepare: 'Return to preparation',
        dispatch: 'Mark the whole order dispatched',
        'delivery-failed': 'Record an unsuccessful delivery attempt',
        returned: 'Confirm goods returned to the seller',
        confirm: 'Confirm full handover',
    };
    return (
        <ConsoleLayout>
            <Head title="Store fulfilment" />
            <PageHeader
                title="Store fulfilment"
                actions={
                    <button className={button} disabled={busy} onClick={() => refresh()}>
                        <RefreshCw className="h-4 w-4" aria-hidden="true" />
                        Refresh
                    </button>
                }
            />
            <div className="mx-auto max-w-7xl space-y-6 p-5 sm:p-8">
                <div className="border-l-2 border-accent bg-accent/5 p-5">
                    <h2 className="font-semibold">From preparation to handover</h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-ink-secondary">
                        Prepare every item, follow the buyer’s chosen collection or delivery option,
                        and record the whole order once it is handed over.
                    </p>
                </div>
                {!online && (
                    <p role="status" className="border-l-2 border-warning p-4 text-sm">
                        You are offline. Reconnect and refresh the order before recording handover.
                    </p>
                )}
                {!enabled && (
                    <p role="status" className="border border-border bg-surface-muted p-4 text-sm">
                        Fulfilment is paused. Order history remains available.
                    </p>
                )}
                {error && !dialog && (
                    <p
                        role="alert"
                        ref={alert}
                        tabIndex={-1}
                        className="border-l-2 border-danger p-4 text-sm text-danger"
                    >
                        {error}
                    </p>
                )}
                <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(260px,340px)_minmax(0,1fr)]">
                    <section className="min-w-0 space-y-4">
                        <label className="block text-xs font-medium uppercase tracking-wider text-ink-secondary">
                            Orders
                            <select
                                className={`${field} mt-2 text-sm normal-case tracking-normal`}
                                value={filter}
                                onChange={(event) => {
                                    filterRef.current = event.target.value;
                                    setFilter(event.target.value);
                                    void refresh(1, event.target.value);
                                }}
                            >
                                <option value="all">All orders</option>
                                <option value="preparing">In preparation</option>
                                <option value="ready">Ready</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="completed">Completed</option>
                            </select>
                        </label>
                        <label className="block text-sm">
                            Handover method
                            <select
                                className={`${field} mt-2`}
                                value={method}
                                onChange={(event) => {
                                    methodRef.current = event.target.value;
                                    setMethod(event.target.value);
                                    void refresh();
                                }}
                            >
                                <option value="all">Collection and delivery</option>
                                <option value="collection">Collection</option>
                                <option value="delivery">Delivery</option>
                            </select>
                        </label>
                        <div className="divide-y divide-border border border-border bg-surface">
                            {list.data?.length ? (
                                list.data.map((row) => (
                                    <button
                                        key={row.id}
                                        type="button"
                                        onClick={() => choose(row.id)}
                                        className={`block w-full space-y-2 p-4 text-left focus-visible:outline-2 focus-visible:outline-accent ${selected === row.id ? 'border-l-2 border-accent bg-accent/5' : 'hover:bg-surface-hover'}`}
                                    >
                                        <span className="block break-words font-semibold">
                                            Order {row.number}
                                        </span>
                                        <span className="block text-sm text-ink-secondary">
                                            {row.buyer}
                                        </span>
                                        <span className="block text-xs text-ink-secondary">
                                            {row.label} · {row.item_count} item
                                            {row.item_count === 1 ? '' : 's'}
                                        </span>
                                    </button>
                                ))
                            ) : (
                                <p className="p-6 text-sm leading-6 text-ink-secondary">
                                    No orders to prepare yet. Confirmed purchases will appear here.
                                </p>
                            )}
                        </div>
                        {(list.last_page ?? 1) > 1 && (
                            <div className="flex items-center justify-between gap-3 text-sm">
                                <button
                                    disabled={busy || list.current_page <= 1}
                                    onClick={() => refresh(list.current_page - 1)}
                                    className="min-h-11 text-accent disabled:opacity-40"
                                >
                                    Previous
                                </button>
                                <span>
                                    {list.current_page} / {list.last_page}
                                </span>
                                <button
                                    disabled={busy || list.current_page >= list.last_page}
                                    onClick={() => refresh(list.current_page + 1)}
                                    className="min-h-11 text-accent disabled:opacity-40"
                                >
                                    Next
                                </button>
                            </div>
                        )}
                    </section>
                    <section
                        className="min-w-0 border border-border bg-surface p-5 sm:p-6"
                        aria-busy={busy}
                    >
                        {!detail ? (
                            <div className="py-12 text-center text-ink-secondary">
                                <Package className="mx-auto mb-4 h-8 w-8" aria-hidden="true" />
                                <p>
                                    {busy
                                        ? 'Loading order…'
                                        : 'Choose an order to prepare or hand over.'}
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-6">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2
                                            ref={heading}
                                            tabIndex={-1}
                                            className="break-words text-xl font-semibold"
                                        >
                                            Order {detail.number}
                                        </h2>
                                        <p className="mt-2 text-sm text-ink-secondary">
                                            {detail.buyer}
                                        </p>
                                    </div>
                                    <span className="border border-border bg-surface-muted px-3 py-2 text-xs font-medium">
                                        {detail.label}
                                    </span>
                                </div>
                                <div className="divide-y divide-border border-y border-border">
                                    {detail.lines?.map((line) => (
                                        <div
                                            key={line.id}
                                            className="flex min-w-0 items-start justify-between gap-4 py-4"
                                        >
                                            <div className="min-w-0">
                                                <h3 className="break-words font-medium">
                                                    {line.title}
                                                </h3>
                                                <p className="mt-1 text-sm text-ink-secondary">
                                                    {Object.entries(line.options ?? {})
                                                        .map(([key, value]) => `${key}: ${value}`)
                                                        .join(' · ')}
                                                </p>
                                            </div>
                                            <span className="shrink-0 text-sm font-semibold">
                                                × {line.quantity}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                <section>
                                    <h3 className="flex items-center gap-2 font-semibold">
                                        {detail.method === 'delivery' && (
                                            <Truck className="h-4 w-4" aria-hidden="true" />
                                        )}
                                        {detail.method === 'delivery'
                                            ? 'Seller-arranged delivery'
                                            : 'Collection from the seller'}
                                    </h3>
                                    <p className="mt-2 whitespace-pre-line break-words text-sm leading-6 text-ink-secondary">
                                        {detail.instructions}
                                    </p>
                                    {detail.destination && (
                                        <address className="mt-4 space-y-1 border border-border bg-surface-muted p-4 text-sm not-italic">
                                            {[
                                                'recipient',
                                                'address_line_1',
                                                'address_line_2',
                                                'locality',
                                                'country',
                                                'postal_code',
                                                'landmark',
                                                'phone',
                                                'instructions',
                                            ].map(
                                                (key) =>
                                                    detail.destination[key] && (
                                                        <p className="break-words" key={key}>
                                                            {detail.destination[key]}
                                                        </p>
                                                    )
                                            )}
                                        </address>
                                    )}
                                </section>
                                {complete && (
                                    <p
                                        role="status"
                                        className="flex items-start gap-3 border-l-2 border-accent bg-accent/5 p-4 text-sm"
                                    >
                                        <CheckCircle2
                                            className="h-5 w-5 shrink-0 text-accent"
                                            aria-hidden="true"
                                        />
                                        {detail.method === 'delivery' ? 'Delivered' : 'Collected'} ·{' '}
                                        {detail.collection?.handed_over_at
                                            ? new Date(
                                                  detail.collection.handed_over_at
                                              ).toLocaleString()
                                            : 'Handover recorded'}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-3">
                                    {enabled && online && detail.can_ready && (
                                        <button
                                            className={`${button} bg-accent text-accent-ink`}
                                            disabled={busy}
                                            onClick={() => open('ready')}
                                        >
                                            Mark ready
                                        </button>
                                    )}
                                    {enabled && online && detail.can_prepare && (
                                        <button
                                            className={button}
                                            disabled={busy}
                                            onClick={() => open('prepare')}
                                        >
                                            Return to preparation
                                        </button>
                                    )}
                                    {enabled && online && detail.can_dispatch && (
                                        <button
                                            className={`${button} bg-accent text-accent-ink`}
                                            disabled={busy}
                                            onClick={() => open('dispatch')}
                                        >
                                            Mark dispatched
                                        </button>
                                    )}
                                    {enabled && online && detail.can_return && (
                                        <>
                                            <button
                                                className={button}
                                                disabled={busy}
                                                onClick={() => open('delivery-failed')}
                                            >
                                                Delivery attempt failed
                                            </button>
                                            <button
                                                className={button}
                                                disabled={busy}
                                                onClick={() => open('returned')}
                                            >
                                                Goods returned to seller
                                            </button>
                                        </>
                                    )}
                                </div>
                                {enabled && online && detail.can_confirm && !complete && (
                                    <section className="space-y-4 border-t border-border pt-5">
                                        <h3 className="font-semibold">
                                            Verify the buyer’s handover code
                                        </h3>
                                        <p className="text-sm leading-6 text-ink-secondary">
                                            Scan or enter the code, check all items, then confirm
                                            before releasing the order.
                                        </p>
                                        <StoreCollectionScanner
                                            disabled={busy}
                                            onCredential={match}
                                            onActiveChange={(value, owner) => {
                                                if (value) cameraActive.current = owner;
                                                else if (cameraActive.current === owner)
                                                    cameraActive.current = false;
                                            }}
                                        />
                                        <form
                                            onSubmit={(event) => {
                                                event.preventDefault();
                                                void match({ manual_code: manual });
                                            }}
                                            className="space-y-3"
                                        >
                                            <label className="block text-sm">
                                                Collection or delivery code
                                                <input
                                                    className={`${field} mt-2 font-mono`}
                                                    value={manual}
                                                    onChange={(event) => {
                                                        setManual(event.target.value);
                                                        setMatched(false);
                                                    }}
                                                    maxLength={32}
                                                    autoComplete="off"
                                                    spellCheck={false}
                                                />
                                            </label>
                                            <button
                                                className={button}
                                                disabled={busy || !manual.trim()}
                                            >
                                                Check code
                                            </button>
                                        </form>
                                        {matched && (
                                            <div className="space-y-3 border-l-2 border-accent bg-accent/5 p-4">
                                                <p
                                                    role="status"
                                                    tabIndex={-1}
                                                    ref={matchedResult}
                                                    className="text-sm"
                                                >
                                                    Code matched. Check every item before
                                                    confirming.
                                                </p>
                                                <button
                                                    type="button"
                                                    className={`${button} bg-accent text-accent-ink`}
                                                    disabled={busy}
                                                    onClick={() => open('confirm')}
                                                >
                                                    Confirm full handover
                                                </button>
                                            </div>
                                        )}
                                    </section>
                                )}
                                {detail.blocked_message && !complete && (
                                    <p
                                        role="status"
                                        className="border-l-2 border-border p-4 text-sm text-ink-secondary"
                                    >
                                        {detail.blocked_message}
                                    </p>
                                )}
                                <section className="space-y-3 border-t border-border pt-5">
                                    <h3 className="font-semibold">Handover history</h3>
                                    <p className="text-xs text-ink-secondary">
                                        Ready notice:{' '}
                                        {{
                                            sent: 'Sent',
                                            queued: 'Queued',
                                            sending: 'Sending',
                                            retryable: 'Delayed — retry scheduled',
                                            exhausted: 'Delivery needs support',
                                            superseded: 'Replaced by a newer order status',
                                        }[detail.ready_email_state] ?? 'Not queued'}
                                        . Email delivery does not change readiness or handover.
                                    </p>
                                    {detail.events?.length ? (
                                        detail.events.map((event) => (
                                            <div
                                                key={event.id}
                                                className="border-l border-border pl-4 text-sm"
                                            >
                                                <p className="font-medium">{event.label}</p>
                                                <p className="mt-1 text-xs text-ink-secondary">
                                                    {event.actor} ·{' '}
                                                    {new Date(event.at).toLocaleString()}
                                                </p>
                                                {event.reason && (
                                                    <p className="mt-2 break-words text-ink-secondary">
                                                        {event.reason}
                                                    </p>
                                                )}
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-sm text-ink-secondary">
                                            Preparation actions and delivery attempts will be
                                            recorded here.
                                        </p>
                                    )}
                                </section>
                            </div>
                        )}
                    </section>
                </div>
            </div>
            <Modal
                open={!!dialog}
                onClose={() => {
                    if (!busy) setDialog(null);
                }}
                title={title[dialog]}
                className="max-w-lg rounded-none!"
            >
                <form className="space-y-5" onSubmit={perform} aria-busy={busy}>
                    {error && (
                        <p
                            role="alert"
                            ref={alert}
                            tabIndex={-1}
                            className="border-l-2 border-danger p-4 text-sm text-danger"
                        >
                            {error}
                        </p>
                    )}
                    <p className="mt-4 text-sm leading-6 text-ink-secondary">
                        {dialog === 'confirm'
                            ? `Check every item before confirming. This records the whole order as ${detail?.method === 'delivery' ? 'delivered' : 'collected'}.`
                            : dialog === 'returned'
                              ? 'Confirm that all goods are physically back with the seller. This does not add them back to sale stock.'
                              : dialog === 'dispatch'
                                ? 'This records that the whole order has left the seller. Recipient verification is still required for delivery.'
                                : 'This updates preparation history. The order and its payment are retained.'}
                    </p>
                    {['prepare', 'delivery-failed', 'returned'].includes(dialog) && (
                        <label className="block text-sm">
                            Reason
                            <textarea
                                className={`${field} mt-2`}
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                maxLength={500}
                                required
                                rows={3}
                            />
                        </label>
                    )}
                    {['confirm', 'returned'].includes(dialog) && (
                        <label className="flex items-start gap-3 text-sm">
                            <input
                                className="mt-1"
                                type="checkbox"
                                checked={acknowledged}
                                onChange={(event) => setAcknowledged(event.target.checked)}
                            />
                            {dialog === 'confirm'
                                ? 'All items are being handed over'
                                : 'All items are back with the seller'}
                        </label>
                    )}
                    <div className="flex flex-wrap gap-3">
                        <button
                            type="button"
                            className={button}
                            disabled={busy}
                            onClick={() => setDialog(null)}
                        >
                            Cancel
                        </button>
                        <button
                            className={`${button} bg-accent text-accent-ink`}
                            disabled={
                                busy ||
                                (['prepare', 'delivery-failed', 'returned'].includes(dialog) &&
                                    !reason.trim()) ||
                                (['confirm', 'returned'].includes(dialog) && !acknowledged)
                            }
                        >
                            {busy
                                ? 'Recording…'
                                : dialog === 'confirm'
                                  ? detail?.method === 'delivery'
                                      ? 'Record delivery'
                                      : 'Record collection'
                                  : 'Confirm'}
                        </button>
                    </div>
                </form>
            </Modal>
        </ConsoleLayout>
    );
}
