import { useRef, useState } from 'react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { sendJson } from '@/lib/dues';

export default function CatalogStock({ item, onChange }) {
    const [variantId, setVariantId] = useState(item.variants[0]?.id ?? '');
    const [delta, setDelta] = useState('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const key = useRef(null);
    const fingerprint = useRef(null);
    const record = async (event) => {
        event.preventDefault();
        const value = Number(delta);
        if (!Number.isSafeInteger(value) || value === 0 || !reason.trim()) {
            setError('Enter a non-zero whole quantity and a reason.');
            return;
        }
        const payloadFingerprint = JSON.stringify([variantId, value, reason.trim()]);
        if (fingerprint.current !== payloadFingerprint) {
            key.current = crypto.randomUUID();
            fingerprint.current = payloadFingerprint;
        }
        setBusy(true);
        setError('');
        setMessage('');
        const result = await sendJson(
            'POST',
            route('tenant.store.catalog.adjust', { listing: item.id, variant: variantId }),
            { delta: value, reason: reason.trim(), idempotency_key: key.current }
        );
        setBusy(false);
        if (!result.ok) {
            setError(Object.values(result.errors)[0] || result.message);
            return;
        }
        onChange(result.body.listing);
        setDelta('');
        setReason('');
        setMessage('Stock correction recorded.');
        key.current = null;
        fingerprint.current = null;
    };
    return (
        <section className="min-w-0 space-y-5 border border-border bg-surface p-5 sm:p-6">
            <div>
                <h2 className="text-xl font-semibold text-ink">Inventory</h2>
                <p className="mt-2 text-sm leading-6 text-ink-secondary">
                    Every change has a reason and an audit record. Use a negative quantity for
                    damage or other stock reductions.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {item.variants.map((variant) => (
                    <div
                        key={variant.id}
                        className="flex min-w-0 justify-between gap-3 border border-border px-4 py-3"
                    >
                        <div className="min-w-0">
                            <p className="break-all text-sm font-medium text-ink">{variant.sku}</p>
                            <p className="text-xs text-ink-secondary">
                                {variant.is_active ? 'Active' : 'Hidden'}
                                {variant.reserved != null &&
                                    ` · ${variant.reserved} held · ${variant.available} available`}
                            </p>
                        </div>
                        <p className="shrink-0 text-xl font-semibold tabular-nums text-ink">
                            {variant.on_hand}
                            <span className="ml-1 text-xs font-normal text-ink-secondary">
                                on hand
                            </span>
                        </p>
                    </div>
                ))}
            </div>
            <form onSubmit={record} className="space-y-4">
                <fieldset disabled={busy} className="grid min-w-0 gap-4 md:grid-cols-2">
                    <Select
                        label="Variant to adjust"
                        value={variantId}
                        onChange={(event) => setVariantId(event.target.value)}
                        className="rounded-none bg-canvas"
                    >
                        {item.variants.map((variant) => (
                            <option value={variant.id} key={variant.id}>
                                {variant.sku}
                            </option>
                        ))}
                    </Select>
                    <Input
                        label="Quantity change"
                        type="number"
                        step="1"
                        required
                        value={delta}
                        onChange={(event) => setDelta(event.target.value)}
                        className="rounded-none bg-canvas"
                    />
                    <div className="md:col-span-2">
                        <Input
                            label="Reason for correction"
                            required
                            maxLength={500}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            className="rounded-none bg-canvas"
                        />
                    </div>
                </fieldset>
                {error && (
                    <p
                        role="alert"
                        className="border-l-2 border-danger-fg bg-danger-bg p-3 text-sm text-danger-fg"
                    >
                        {error}
                    </p>
                )}
                {message && (
                    <p role="status" className="text-sm text-success-fg">
                        {message}
                    </p>
                )}
                <Button type="submit" disabled={busy || !variantId} className="rounded-none">
                    {busy ? 'Recording…' : 'Record correction'}
                </Button>
            </form>
            <div className="border-t border-border pt-5">
                <h3 className="mb-4 text-sm font-semibold text-ink">Recent stock history</h3>
                {item.movements.length ? (
                    <ol className="divide-y divide-border">
                        {item.movements.map((movement) => (
                            <li key={movement.id} className="flex min-w-0 items-start gap-4 py-3">
                                <span className="w-12 shrink-0 text-right font-mono text-sm font-medium text-ink">
                                    {movement.delta > 0 ? '+' : ''}
                                    {movement.delta}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="break-words text-sm text-ink">
                                        {movement.reason}
                                    </p>
                                    <p className="mt-1 break-words text-xs text-ink-secondary">
                                        {
                                            item.variants.find(
                                                (variant) => variant.id === movement.variant_id
                                            )?.sku
                                        }{' '}
                                        · {movement.actor} ·{' '}
                                        {new Date(movement.at).toLocaleString()}
                                    </p>
                                </div>
                                <span className="shrink-0 text-xs text-ink-secondary">
                                    Balance {movement.balance_after}
                                </span>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="text-sm text-ink-secondary">No stock corrections yet.</p>
                )}
            </div>
        </section>
    );
}
