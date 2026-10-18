import { useState } from 'react';
import Input from '@/Components/Console/Input';
import Button from '@/Components/Console/Button';
import csrfFetch from '@/lib/csrfFetch';

export default function MemberBenefitsPanel({ event, enabled, canEdit, onChange }) {
    const [membersOnly, setMembersOnly] = useState(Boolean(event.members_only));
    const [price, setPrice] = useState(
        event.member_ticket_price == null ? '' : String(event.member_ticket_price / 100)
    );
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState(null);
    if (!enabled && !event.members_only && event.member_ticket_price == null) return null;
    const save = async (e) => {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        setMessage(null);
        try {
            const response = await csrfFetch(
                route('tenant.events.member-benefits', { event: event.id }),
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        members_only: membersOnly,
                        member_ticket_price: price === '' ? null : Math.round(Number(price) * 100),
                    }),
                }
            );
            const json = await response.json();
            if (!response.ok) {
                setMessage(
                    Object.values(json.errors ?? {}).flat()[0] ??
                        json.message ??
                        'Could not save member benefits.'
                );
                return;
            }
            setMessage('Member benefits saved.');
            onChange?.();
        } catch {
            setMessage('Could not reach the server. Please try again.');
        } finally {
            setBusy(false);
        }
    };
    return (
        <section className="border border-border bg-surface p-5" aria-label="Member benefits">
            <h3 className="text-base font-semibold text-ink">Member benefits</h3>
            <p className="mt-2 text-sm leading-6 text-ink-secondary">
                For active personal members of your community, including members without dues.
                Followers and representatives do not receive a personal member price.
            </p>
            {!enabled && (
                <p className="mt-3 text-sm text-ink-secondary">
                    Member benefits are paused for new registrations. Existing tickets and payment
                    recovery remain available.
                </p>
            )}
            {enabled && canEdit ? (
                <form onSubmit={save} className="mt-4 space-y-4">
                    <label className="flex items-center gap-3 text-sm text-ink">
                        <input
                            type="checkbox"
                            checked={membersOnly}
                            disabled={busy}
                            onChange={(e) => setMembersOnly(e.target.checked)}
                        />
                        Members-only registration
                    </label>
                    <Input
                        label={`Member ticket price (${event.currency})`}
                        type="number"
                        min="0"
                        max={event.ticket_price / 100}
                        step="0.01"
                        value={price}
                        disabled={busy}
                        onChange={(e) => setPrice(e.target.value)}
                        hint="Leave blank for standard pricing. Enter 0 for free member admission. Ticket types can have their own member prices."
                    />
                    <Button type="submit" disabled={busy}>
                        {busy ? 'Saving…' : 'Save member benefits'}
                    </Button>
                </form>
            ) : (
                <p className="mt-3 text-sm text-ink-secondary">
                    {event.members_only ? 'Members-only registration.' : 'Public registration.'}{' '}
                    {event.member_ticket_price == null
                        ? 'Standard base price for members.'
                        : `Member base: ${event.currency} ${(event.member_ticket_price / 100).toFixed(2)}.`}
                </p>
            )}
            {message && (
                <p role="status" className="mt-3 text-sm text-ink-secondary">
                    {message}
                </p>
            )}
        </section>
    );
}
