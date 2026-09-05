import { useState } from 'react';
import { Trash2, Plus } from 'lucide-react';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

const BADGE_TIER_LABEL = {
    general: 'General',
    speaker: 'Speaker',
    vip: 'VIP',
    staff: 'Staff',
};

function formatMoney(amount, currency) {
    return amount > 0 ? `${currency} ${(amount / 100).toFixed(2)}` : 'Free';
}

export default function TicketTypesPanel({ event, ticketTypes, onChange }) {
    const [form, setForm] = useState({ name: '', price: '', capacity: '', badge_tier: 'general' });
    const [saving, setSaving] = useState(false);
    const toast = useToast();

    const addTicketType = async (e) => {
        e.preventDefault();
        setSaving(true);

        const response = await csrfFetch(route('tenant.events.ticket-types.store', { event: event.id }), {
            method: 'POST',
            body: JSON.stringify({
                name: form.name,
                price: Math.round(Number(form.price || 0) * 100),
                capacity: form.capacity === '' ? null : Number(form.capacity),
                badge_tier: form.badge_tier,
                is_active: true,
            }),
        });

        setSaving(false);

        if (!response.ok) {
            const json = await response.json();
            toast?.(json.message ?? 'Could not add ticket type.');
            return;
        }

        setForm({ name: '', price: '', capacity: '', badge_tier: 'general' });
        onChange();
    };

    const removeTicketType = async (ticketType) => {
        const response = await csrfFetch(route('tenant.events.ticket-types.destroy', { event: event.id, ticketType: ticketType.id }), {
            method: 'DELETE',
        });

        if (!response.ok) {
            const json = await response.json();
            toast?.(json.message ?? 'Could not delete ticket type.');
            return;
        }

        onChange();
    };

    const setBadgeTier = async (ticketType, badge_tier) => {
        const response = await csrfFetch(route('tenant.events.ticket-types.badge-tier', { event: event.id, ticketType: ticketType.id }), {
            method: 'PATCH',
            body: JSON.stringify({ badge_tier }),
        });

        if (!response.ok) {
            const json = await response.json();
            toast?.(json.message ?? 'Could not update badge tier.');
            return;
        }

        onChange();
    };

    return (
        <div className="max-w-2xl">
            {ticketTypes.length > 0 ? (
                <ul className="mb-6 divide-y divide-border rounded-md border border-border">
                    {ticketTypes.map((ticketType) => (
                        <li key={ticketType.id} className="flex items-center justify-between gap-3 px-4 py-3">
                            <div className="min-w-0">
                                <div className="text-sm font-medium text-ink">{ticketType.name}</div>
                                <div className="text-xs text-ink-secondary">
                                    {formatMoney(ticketType.price, event.currency)}
                                    {ticketType.capacity ? ` · ${ticketType.confirmed_count}/${ticketType.capacity} registered` : ` · ${ticketType.confirmed_count} registered`}
                                    {ticketType.is_sold_out ? ' · Sold out' : ''}
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-3">
                                <Select
                                    value={ticketType.badge_tier ?? 'general'}
                                    onChange={(e) => setBadgeTier(ticketType, e.target.value)}
                                    className="w-32"
                                >
                                    {Object.entries(BADGE_TIER_LABEL).map(([value, label]) => (
                                        <option key={value} value={value}>{label} badge</option>
                                    ))}
                                </Select>
                                <button type="button" onClick={() => removeTicketType(ticketType)} className="text-ink-secondary hover:text-danger-fg">
                                    <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mb-6 text-sm text-ink-secondary">
                    No ticket types yet — the event's default price applies to every registration until you add one.
                </p>
            )}

            <form onSubmit={addTicketType} className="grid grid-cols-[1fr_120px_120px_140px_auto] items-end gap-3">
                <Input label="Name" placeholder="e.g. In-Person" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                <Input label="Price" type="number" min="0" step="0.01" value={form.price} onChange={(e) => setForm({ ...form, price: e.target.value })} required />
                <Input label="Capacity" type="number" min="1" placeholder="Unlimited" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: e.target.value })} />
                <Select label="Badge" value={form.badge_tier} onChange={(e) => setForm({ ...form, badge_tier: e.target.value })}>
                    {Object.entries(BADGE_TIER_LABEL).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                    ))}
                </Select>
                <Button type="submit" icon={Plus} disabled={saving}>Add</Button>
            </form>
        </div>
    );
}
