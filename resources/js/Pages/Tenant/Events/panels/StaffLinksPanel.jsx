import { useEffect, useState } from 'react';
import { Copy, QrCode, Share2, UserPlus } from 'lucide-react';
import Button from '@/Components/Console/Button';
import Checkbox from '@/Components/Console/Checkbox';
import Input from '@/Components/Console/Input';
import StatusPill from '@/Components/Console/StatusPill';
import { useToast } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

const EMPTY = { name: '', pin: '', can_check_in: true, can_handle_requests: true };

function LinkRow({ event, link, onChange }) {
    const showToast = useToast();
    const [showQr, setShowQr] = useState(false);

    const patch = async (body) => {
        const response = await csrfFetch(
            route('tenant.events.staff-links.update', { event: event.id, staffLink: link.id }),
            { method: 'PATCH', body: JSON.stringify(body) }
        );
        if (!response.ok) {
            showToast?.((await response.json()).message ?? 'That change was not saved.');
        }
        onChange();
    };

    const revoke = async () => {
        await csrfFetch(
            route('tenant.events.staff-links.destroy', { event: event.id, staffLink: link.id }),
            { method: 'DELETE' }
        );
        onChange();
    };

    const message = `${link.name}: your staff link for ${event.name}. Open it on your phone: ${link.url}`;

    return (
        <li className="space-y-3 border border-border px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <b className="text-sm text-ink">{link.name}</b>
                <StatusPill status={link.is_active ? 'success' : 'neutral'}>
                    {link.is_active ? 'Active' : 'Off'}
                </StatusPill>
                {link.has_pin && <span className="text-xs text-ink-secondary">PIN set</span>}
                <span className="ml-auto text-xs text-ink-tertiary">
                    {link.last_used_at
                        ? `Last used ${new Date(link.last_used_at).toLocaleString()}`
                        : 'Not opened yet'}
                </span>
            </div>

            {link.is_active && (
                <>
                    <div className="flex flex-wrap gap-4">
                        <Checkbox
                            label="Scan tickets"
                            checked={link.can_check_in}
                            onChange={(e) => patch({ can_check_in: e.target.checked })}
                        />
                        <Checkbox
                            label="Answer attendee requests"
                            checked={link.can_handle_requests}
                            onChange={(e) => patch({ can_handle_requests: e.target.checked })}
                        />
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            icon={Copy}
                            onClick={async () => {
                                await navigator.clipboard.writeText(link.url);
                                showToast?.('Link copied');
                            }}
                        >
                            Copy link
                        </Button>
                        <a
                            href={`https://wa.me/?text=${encodeURIComponent(message)}`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-control items-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink hover:border-border-strong hover:bg-surface-hover"
                        >
                            <Share2 className="h-4 w-4" strokeWidth={1.75} />
                            WhatsApp
                        </a>
                        <Button icon={QrCode} onClick={() => setShowQr(!showQr)}>
                            {showQr ? 'Hide QR' : 'Show QR'}
                        </Button>
                        <button
                            type="button"
                            onClick={revoke}
                            className="ml-auto text-xs text-ink-secondary hover:text-danger-fg"
                        >
                            Switch off
                        </button>
                    </div>
                    {showQr && link.qr && (
                        <img
                            src={link.qr}
                            alt={`QR code for ${link.name}`}
                            className="h-48 w-48 border border-border bg-white p-2"
                        />
                    )}
                </>
            )}
        </li>
    );
}

/**
 * Staff links for ushers and floor crew: each opens a phone page that scans
 * tickets, answers attendee requests, or both, with no account and no seat.
 */
export default function StaffLinksPanel({ event }) {
    const [links, setLinks] = useState([]);
    const [allowance, setAllowance] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const load = async () => {
        const response = await csrfFetch(
            route('tenant.events.staff-links.index', { event: event.id })
        );
        if (response.ok) {
            const data = await response.json();
            setLinks(data.links);
            setAllowance(data.allowance);
        }
    };

    useEffect(() => {
        load();
        // Reload only when the event changes.
    }, [event.id]);

    const create = async (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});
        const response = await csrfFetch(
            route('tenant.events.staff-links.store', { event: event.id }),
            {
                method: 'POST',
                body: JSON.stringify({ ...form, pin: form.pin || null }),
            }
        );
        const data = await response.json();
        setSaving(false);

        if (!response.ok) {
            setErrors(
                data.errors
                    ? Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, v[0]]))
                    : { form: data.message }
            );
            return;
        }

        setForm(EMPTY);
        load();
    };

    const active = links.filter((l) => l.is_active);
    const atLimit = allowance?.limit != null && allowance.active >= allowance.limit;

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h3 className="text-sm font-semibold text-ink">Staff links</h3>
                    <p className="text-xs text-ink-secondary">
                        Give each usher their own link. It opens the scanner and attendee requests
                        on their phone: no account, no team seat, and it stops working after the
                        event.
                    </p>
                </div>
                {allowance && (
                    <span className="text-xs text-ink-secondary">
                        {allowance.active} of {allowance.limit ?? 'unlimited'} active across your
                        events
                    </span>
                )}
            </div>

            <form onSubmit={create} className="grid gap-3 border border-border p-4 sm:grid-cols-2">
                <Input
                    label="Who will hold it"
                    placeholder="Gate A – Kofi"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    error={errors.name}
                    required
                />
                <Input
                    label="PIN (optional)"
                    hint="4–8 digits, for links shared in group chats."
                    inputMode="numeric"
                    value={form.pin}
                    onChange={(e) => setForm({ ...form, pin: e.target.value })}
                    error={errors.pin}
                />
                <div className="flex flex-wrap gap-4 sm:col-span-2">
                    <Checkbox
                        label="Scan tickets"
                        checked={form.can_check_in}
                        onChange={(e) => setForm({ ...form, can_check_in: e.target.checked })}
                    />
                    <Checkbox
                        label="Answer attendee requests"
                        checked={form.can_handle_requests}
                        onChange={(e) =>
                            setForm({ ...form, can_handle_requests: e.target.checked })
                        }
                    />
                </div>
                {(errors.can_handle_requests || errors.form) && (
                    <p className="text-sm text-danger-fg sm:col-span-2">
                        {errors.can_handle_requests || errors.form}
                    </p>
                )}
                <div className="flex items-center gap-3 sm:col-span-2">
                    <Button
                        type="submit"
                        variant="primary"
                        icon={UserPlus}
                        disabled={saving || atLimit}
                    >
                        Create staff link
                    </Button>
                    {atLimit && (
                        <span className="text-xs text-ink-secondary">
                            All your staff links are in use. Switch one off or{' '}
                            <a
                                href={route('billing.addons.index')}
                                className="text-accent underline"
                            >
                                add an usher pack
                            </a>
                            .
                        </span>
                    )}
                </div>
            </form>

            {links.length > 0 && (
                <ul className="space-y-2">
                    {[...active, ...links.filter((l) => !l.is_active)].map((link) => (
                        <LinkRow key={link.id} event={event} link={link} onChange={load} />
                    ))}
                </ul>
            )}
        </section>
    );
}
