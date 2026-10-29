import { useEffect, useRef, useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';

const field =
    'mt-2 block w-full min-w-0 border border-border bg-surface px-3 py-2 text-ink focus:outline-accent';
export default function StoreFulfilmentChoice({
    options,
    value,
    onChange,
    disabled = false,
    quoted = false,
}) {
    if (!options?.methods?.length)
        return (
            <p role="status">
                The seller needs to offer a collection or delivery option before checkout.
            </p>
        );
    const select = (method) =>
        onChange({
            policy_id: options.policy_id,
            method,
            ...(method === 'delivery'
                ? { destination: { country: options.delivery_countries?.[0] ?? '' } }
                : {}),
        });
    const destination = (key, text) =>
        onChange({ ...value, destination: { ...value.destination, [key]: text } });
    return (
        <fieldset disabled={disabled} className="min-w-0 space-y-4 border border-border p-4">
            <legend className="px-1 font-semibold">
                {quoted
                    ? 'Agreed collection or delivery'
                    : 'How would you like to receive your order?'}
            </legend>
            {options.methods.includes('collection') && (
                <div>
                    <label className="flex items-center gap-3">
                        <input
                            type="radio"
                            name="fulfilment-method"
                            checked={value?.method === 'collection'}
                            onChange={() => select('collection')}
                        />
                        Collect from the seller
                    </label>
                    <p className="mt-2 text-sm text-ink-secondary">{options.collection_location}</p>
                    <p className="text-sm text-ink-secondary">{options.collection_hours}</p>
                    <p className="whitespace-pre-line text-sm">{options.collection_instructions}</p>
                </div>
            )}
            {options.methods.includes('delivery') && (
                <div>
                    <label className="flex items-center gap-3">
                        <input
                            type="radio"
                            name="fulfilment-method"
                            checked={value?.method === 'delivery'}
                            onChange={() => select('delivery')}
                        />
                        {quoted
                            ? 'Delivery — charge included in the quoted total'
                            : 'Delivery — included in the displayed price'}
                    </label>
                    <p className="mt-2 text-sm text-ink-secondary">{options.delivery_timing}</p>
                    <p className="whitespace-pre-line text-sm">{options.delivery_instructions}</p>
                </div>
            )}
            {value?.method === 'delivery' && (
                <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                    {[
                        ['recipient', 'Recipient name', 255],
                        ['address_line_1', 'Address', 500],
                        ['address_line_2', 'Address line 2 (optional)', 500],
                        ['locality', 'Town or city', 255],
                        ['landmark', 'Landmark (optional)', 500],
                        ['phone', 'Contact phone (optional)', 40],
                        ['instructions', 'Delivery instructions (optional)', 2000],
                    ].map(([key, label, max]) => (
                        <label key={key} className="min-w-0 text-sm">
                            {label}
                            <input
                                className={field}
                                value={value.destination?.[key] ?? ''}
                                onChange={(event) => destination(key, event.target.value)}
                                maxLength={max}
                                required={['recipient', 'address_line_1', 'locality'].includes(key)}
                            />
                        </label>
                    ))}
                    <label className="text-sm">
                        Country
                        <select
                            className={field}
                            value={value.destination?.country ?? ''}
                            onChange={(event) => destination('country', event.target.value)}
                            required
                        >
                            <option value="">Choose country</option>
                            {options.delivery_countries?.map((country) => (
                                <option key={country} value={country}>
                                    {country}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            )}
            <p className="text-sm text-ink-secondary">
                Your ready order remains available until collected or delivered. A handover code
                expires separately and does not cancel your order.
            </p>
        </fieldset>
    );
}
export function StoreFulfilmentSelector({ order, onAccepted, onPendingChange }) {
    const [choice, setChoice] = useState(null);
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const operation = useRef(crypto.randomUUID());
    const pending = useRef(null);
    useEffect(() => () => pending.current?.abort(), []);
    const alert = useRef(null);
    useEffect(() => {
        onPendingChange?.(editing || busy || !order.fulfilment_method);
    }, [editing, busy, order.fulfilment_method, onPendingChange]);
    if (order.fulfilment_method && !editing)
        return (
            <div className="space-y-3">
                <p className="text-sm">
                    {order.fulfilment_method === 'delivery'
                        ? 'Delivery included in the displayed price'
                        : 'Collection from the seller'}{' '}
                    · Option accepted
                </p>
                {order.state === 'quoted' && (
                    <button
                        type="button"
                        className="text-sm text-accent underline"
                        onClick={() => setEditing(true)}
                    >
                        Change collection or delivery
                    </button>
                )}
            </div>
        );
    const save = async (event) => {
        event.preventDefault();
        pending.current?.abort();
        const controller = new AbortController();
        pending.current = controller;
        setBusy(true);
        setError('');
        try {
            const response = await csrfFetch(`/my/store-orders/${order.id}/fulfilment`, {
                method: 'POST',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ choice, operation_key: operation.current }),
            });
            const data = await response.json();
            if (controller.signal.aborted) return;
            if (!response.ok)
                throw new Error(
                    Object.values(data.errors ?? {}).flat()[0] ??
                        data.message ??
                        'Could not accept this option.'
                );
            await onAccepted?.(data);
            if (!controller.signal.aborted) setEditing(false);
        } catch (failure) {
            if (controller.signal.aborted) return;
            setError(failure.message);
            queueMicrotask(() => alert.current?.focus());
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    };
    return (
        <form onSubmit={save} className="space-y-4">
            <StoreFulfilmentChoice
                options={order.fulfilment_options}
                value={choice}
                onChange={(next) => {
                    setChoice(next);
                    operation.current = crypto.randomUUID();
                }}
                disabled={busy}
            />
            {error && (
                <p role="alert" tabIndex={-1} ref={alert}>
                    {error}
                </p>
            )}
            {!!order.fulfilment_options?.methods?.length && (
                <button
                    disabled={!choice || busy}
                    className="min-h-11 border border-border bg-accent px-5 py-3 text-sm text-accent-ink disabled:opacity-50"
                >
                    {busy ? 'Saving…' : 'Accept fulfilment option'}
                </button>
            )}
        </form>
    );
}
