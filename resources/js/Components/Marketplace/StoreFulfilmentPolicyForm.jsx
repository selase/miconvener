import { useState } from 'react';
import csrfFetch from '@/lib/csrfFetch';
export default function StoreFulfilmentPolicyForm({ initial = null }) {
    const [policy, setPolicy] = useState(initial ?? { methods: [], delivery_countries: ['GH'] });
    const [countryCodes, setCountryCodes] = useState(
        (initial?.delivery_countries ?? ['GH']).join(', ')
    );
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const update = (name, value) => setPolicy((old) => ({ ...old, [name]: value }));
    const toggle = (method) =>
        update(
            'methods',
            policy.methods.includes(method)
                ? policy.methods.filter((entry) => entry !== method)
                : [...policy.methods, method]
        );
    return (
        <details className="border border-border bg-surface p-5">
            <summary className="cursor-pointer font-semibold">
                Collection & delivery options
            </summary>
            <form
                className="mt-5 space-y-4"
                onSubmit={async (event) => {
                    event.preventDefault();
                    setBusy(true);
                    setMessage('');
                    try {
                        const response = await csrfFetch(
                            route('tenant.store.fulfilment-policies.store'),
                            {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    policy: {
                                        ...policy,
                                        delivery_countries: countryCodes
                                            .split(',')
                                            .map((country) => country.trim().toUpperCase())
                                            .filter(Boolean),
                                    },
                                    operation_key: crypto.randomUUID(),
                                }),
                            }
                        );
                        const data = await response.json();
                        if (!response.ok)
                            throw new Error(
                                Object.values(data.errors ?? {}).flat()[0] ??
                                    data.message ??
                                    'Could not save options.'
                            );
                        setMessage(
                            'Options saved for new orders. Accepted orders keep their original arrangement.'
                        );
                    } catch (error) {
                        setMessage(error.message);
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <p className="text-sm text-ink-secondary">
                    Offer office pickup, seller-arranged delivery, or both. Delivery must be
                    included in your displayed item price.
                </p>
                {['collection', 'delivery'].map((method) => (
                    <label className="flex gap-3" key={method}>
                        <input
                            type="checkbox"
                            checked={policy.methods.includes(method)}
                            onChange={() => toggle(method)}
                            disabled={busy}
                        />
                        {method === 'collection'
                            ? 'Collection from our location'
                            : 'Delivery included in displayed price'}
                    </label>
                ))}
                {[
                    ['collection_location', 'Collection location'],
                    ['collection_hours', 'Collection hours'],
                    ['collection_instructions', 'Collection instructions'],
                    ['delivery_instructions', 'Delivery instructions'],
                    ['delivery_timing', 'Expected delivery timing'],
                ]
                    .filter(([key]) =>
                        policy.methods.includes(
                            key.startsWith('collection') ? 'collection' : 'delivery'
                        )
                    )
                    .map(([key, label]) => (
                        <label className="block text-sm" key={key}>
                            {label}
                            <input
                                value={policy[key] ?? ''}
                                onChange={(event) => update(key, event.target.value)}
                                className="mt-2 block w-full border border-border bg-surface px-3 py-2 text-ink"
                                required={key === 'collection_location'}
                                maxLength={
                                    key === 'collection_location'
                                        ? 1000
                                        : key.endsWith('instructions')
                                          ? 2000
                                          : 500
                                }
                            />
                        </label>
                    ))}
                {policy.methods.includes('delivery') && (
                    <label className="block text-sm">
                        Delivery country codes (comma separated)
                        <input
                            value={countryCodes}
                            onChange={(event) => setCountryCodes(event.target.value)}
                            disabled={busy}
                            className="mt-2 block w-full border border-border bg-surface px-3 py-2 text-ink"
                            required
                        />
                    </label>
                )}
                <button
                    disabled={busy || policy.methods.length === 0}
                    className="min-h-11 border border-border bg-accent px-5 py-3 text-accent-ink disabled:opacity-50"
                >
                    {busy ? 'Saving…' : 'Save fulfilment options'}
                </button>
                {message && (
                    <p role="status" className="text-sm">
                        {message}
                    </p>
                )}
            </form>
        </details>
    );
}
