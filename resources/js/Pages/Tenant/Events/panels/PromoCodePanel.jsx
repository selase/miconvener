import { useState, useEffect } from 'react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Checkbox from '@/Components/Console/Checkbox';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import csrfFetch from '@/lib/csrfFetch';
import { 
    Plus, 
    Trash2, 
    Edit2, 
    Tag, 
    Percent, 
    DollarSign, 
    Gift, 
    Key, 
    Lock, 
    Unlock, 
    Clock, 
    Users, 
    CheckCircle2, 
    AlertCircle,
    Copy,
    RefreshCw
} from 'lucide-react';

export default function PromoCodePanel({ event, onChange }) {
    const [loading, setLoading] = useState(true);
    const [promoCodes, setPromoCodes] = useState([]);
    const [ticketTypes, setTicketTypes] = useState([]);
    
    // Modal states
    const [modalOpen, setModalOpen] = useState(false);
    const [editingPromo, setEditingPromo] = useState(null);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState({});
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [copiedCode, setCopiedCode] = useState(null);

    // Form state
    const [form, setForm] = useState({
        code: '',
        description: '',
        discount_type: 'percentage',
        discount_value: '10', // percentage or major currency units (e.g. 50 GHS)
        max_redemptions: '',
        max_per_attendee: '1',
        starts_at: '',
        expires_at: '',
        applicable_ticket_type_ids: [],
        is_active: true,
        is_global: false,
    });

    const loadData = async () => {
        setLoading(true);
        try {
            const res = await csrfFetch(route('tenant.events.promo-codes.index', { event: event.id }));
            if (res.ok) {
                const data = await res.json();
                setPromoCodes(data.promo_codes || []);
                setTicketTypes(data.ticket_types || []);
            }
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadData();
    }, [event.id]);

    const handleCopy = (code) => {
        navigator.clipboard.writeText(code);
        setCopiedCode(code);
        setTimeout(() => setCopiedCode(null), 2000);
    };

    const generateRandomCode = () => {
        const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let result = 'PROMO-';
        for (let i = 0; i < 6; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        setForm(prev => ({ ...prev, code: result }));
    };

    const openCreateModal = () => {
        setEditingPromo(null);
        setForm({
            code: '',
            description: '',
            discount_type: 'percentage',
            discount_value: '10',
            max_redemptions: '',
            max_per_attendee: '1',
            starts_at: '',
            expires_at: '',
            applicable_ticket_type_ids: [],
            is_active: true,
            is_global: false,
        });
        setErrors({});
        setModalOpen(true);
    };

    const openEditModal = (promo) => {
        setEditingPromo(promo);
        setForm({
            code: promo.code,
            description: promo.description || '',
            discount_type: promo.discount_type,
            discount_value: promo.discount_type === 'fixed_amount' 
                ? (promo.discount_value / 100).toString() 
                : promo.discount_value.toString(),
            max_redemptions: promo.max_redemptions !== null ? promo.max_redemptions.toString() : '',
            max_per_attendee: (promo.max_per_attendee || 1).toString(),
            starts_at: promo.starts_at ? promo.starts_at.slice(0, 16) : '',
            expires_at: promo.expires_at ? promo.expires_at.slice(0, 16) : '',
            applicable_ticket_type_ids: promo.applicable_ticket_type_ids || [],
            is_active: Boolean(promo.is_active),
            is_global: promo.event_id === null,
        });
        setErrors({});
        setModalOpen(true);
    };

    const handleSave = async (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const discountVal = form.discount_type === 'complimentary'
            ? 0
            : form.discount_type === 'fixed_amount'
                ? Math.round(parseFloat(form.discount_value || 0) * 100)
                : parseInt(form.discount_value || 0, 10);

        const payload = {
            code: form.code.trim().toUpperCase(),
            description: form.description.trim() || null,
            discount_type: form.discount_type,
            discount_value: discountVal,
            max_redemptions: form.max_redemptions ? parseInt(form.max_redemptions, 10) : null,
            max_per_attendee: parseInt(form.max_per_attendee || 1, 10),
            starts_at: form.starts_at || null,
            expires_at: form.expires_at || null,
            applicable_ticket_type_ids: form.applicable_ticket_type_ids.length > 0 
                ? form.applicable_ticket_type_ids 
                : null,
            is_active: form.is_active,
            is_global: form.is_global,
        };

        const url = editingPromo
            ? route('tenant.events.promo-codes.update', { event: event.id, promoCode: editingPromo.id })
            : route('tenant.events.promo-codes.store', { event: event.id });
        const method = editingPromo ? 'PUT' : 'POST';

        try {
            const res = await csrfFetch(url, {
                method,
                body: JSON.stringify(payload),
            });

            if (res.ok) {
                setModalOpen(false);
                loadData();
                if (onChange) onChange();
            } else if (res.status === 422) {
                const data = await res.json();
                setErrors(data.errors || {});
            }
        } finally {
            setSaving(false);
        }
    };

    const handleDelete = async () => {
        if (!deleteTarget) return;
        try {
            const res = await csrfFetch(route('tenant.events.promo-codes.destroy', { event: event.id, promoCode: deleteTarget.id }), {
                method: 'DELETE',
            });
            if (res.ok) {
                setDeleteTarget(null);
                loadData();
                if (onChange) onChange();
            }
        } catch (e) {
            console.error(e);
        }
    };

    const handleToggle = async (promo) => {
        try {
            const res = await csrfFetch(route('tenant.events.promo-codes.toggle', { event: event.id, promoCode: promo.id }), {
                method: 'PATCH',
            });
            if (res.ok) {
                loadData();
                if (onChange) onChange();
            }
        } catch (e) {
            console.error(e);
        }
    };

    const toggleTicketTypeSelection = (id) => {
        setForm(prev => {
            const exists = prev.applicable_ticket_type_ids.includes(id);
            return {
                ...prev,
                applicable_ticket_type_ids: exists
                    ? prev.applicable_ticket_type_ids.filter(item => item !== id)
                    : [...prev.applicable_ticket_type_ids, id]
            };
        });
    };

    const formatMoney = (amount, currency = event.currency || 'GHS') => {
        return `${currency} ${(amount / 100).toFixed(2)}`;
    };

    if (loading) {
        return (
            <div className="py-12 text-center text-sm text-ink-secondary">
                <RefreshCw className="mx-auto h-6 w-6 animate-spin text-accent mb-2" />
                Loading promo codes and discount settings...
            </div>
        );
    }

    return (
        <div className="space-y-8">
            {/* Header & Actions */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border pb-5">
                <div>
                    <h3 className="text-base font-semibold text-ink flex items-center gap-2">
                        <Tag className="h-4 w-4 text-accent" />
                        Promo Codes & Discounts
                    </h3>
                    <p className="text-xs text-ink-secondary mt-1">
                        Create percentage, fixed-amount, or 100% complimentary codes for marketing campaigns, VIPs, and partners.
                    </p>
                </div>
                <Button variant="primary" onClick={openCreateModal} className="flex items-center gap-1.5 shrink-0">
                    <Plus className="h-4 w-4" />
                    New Promo Code
                </Button>
            </div>

            {/* Promo Codes List */}
            {promoCodes.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border bg-surface-sunken/40 p-8 text-center">
                    <Tag className="mx-auto h-8 w-8 text-ink-tertiary mb-3 stroke-[1.2]" />
                    <p className="text-sm font-medium text-ink">No promo codes created yet</p>
                    <p className="text-xs text-ink-secondary mt-1 max-w-sm mx-auto">
                        Offer early-bird discounts, partner vouchers, or free VIP guest codes to boost event ticket sales.
                    </p>
                    <Button variant="outline" onClick={openCreateModal} className="mt-4 inline-flex items-center gap-1.5 text-xs">
                        <Plus className="h-3.5 w-3.5" />
                        Create your first promo code
                    </Button>
                </div>
            ) : (
                <div className="space-y-3">
                    {promoCodes.map((promo) => {
                        const isExpired = promo.expires_at && new Date(promo.expires_at) < new Date();
                        const isLimitReached = promo.max_redemptions && promo.redemptions_count >= promo.max_redemptions;

                        return (
                            <div 
                                key={promo.id}
                                className={`flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-md border p-4 transition-colors ${
                                    !promo.is_active || isExpired || isLimitReached
                                        ? 'border-border bg-surface-sunken/30 opacity-75'
                                        : 'border-border bg-surface hover:border-accent/40'
                                }`}
                            >
                                <div className="space-y-1.5 min-w-0">
                                    <div className="flex items-center gap-2.5 flex-wrap">
                                        <button
                                            onClick={() => handleCopy(promo.code)}
                                            className="group inline-flex items-center gap-1.5 rounded bg-surface-sunken px-2.5 py-1 font-mono text-xs font-bold text-ink hover:text-accent border border-border"
                                            title="Click to copy code"
                                        >
                                            <span>{promo.code}</span>
                                            {copiedCode === promo.code ? (
                                                <CheckCircle2 className="h-3 w-3 text-success-fg" />
                                            ) : (
                                                <Copy className="h-3 w-3 opacity-40 group-hover:opacity-100" />
                                            )}
                                        </button>

                                        {/* Discount Badge */}
                                        <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                            promo.discount_type === 'complimentary'
                                                ? 'bg-purple-100 text-purple-800 dark:bg-purple-950/50 dark:text-purple-300'
                                                : promo.discount_type === 'percentage'
                                                    ? 'bg-blue-100 text-blue-800 dark:bg-blue-950/50 dark:text-blue-300'
                                                    : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                                        }`}>
                                            {promo.discount_type === 'complimentary' && <Gift className="h-3 w-3" />}
                                            {promo.discount_type === 'percentage' && <Percent className="h-3 w-3" />}
                                            {promo.discount_type === 'fixed_amount' && <DollarSign className="h-3 w-3" />}
                                            {promo.discount_type === 'complimentary' 
                                                ? '100% Free Pass'
                                                : promo.discount_type === 'percentage'
                                                    ? `${promo.discount_value}% Off`
                                                    : `${formatMoney(promo.discount_value, promo.currency)} Off`
                                            }
                                        </span>

                                        {/* Status badges */}
                                        {!promo.is_active && (
                                            <span className="rounded bg-neutral-200 dark:bg-neutral-800 px-2 py-0.5 text-[11px] font-medium text-neutral-600 dark:text-neutral-400">
                                                Inactive
                                            </span>
                                        )}
                                        {isExpired && (
                                            <span className="rounded bg-rose-100 dark:bg-rose-950/50 px-2 py-0.5 text-[11px] font-medium text-rose-700 dark:text-rose-300">
                                                Expired
                                            </span>
                                        )}
                                        {isLimitReached && (
                                            <span className="rounded bg-amber-100 dark:bg-amber-950/50 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:text-amber-300">
                                                Cap Reached
                                            </span>
                                        )}
                                        {promo.event_id === null && (
                                            <span className="rounded bg-indigo-100 dark:bg-indigo-950/50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 dark:text-indigo-300">
                                                Global
                                            </span>
                                        )}
                                    </div>

                                    {promo.description && (
                                        <p className="text-xs text-ink-secondary">{promo.description}</p>
                                    )}

                                    <div className="flex items-center gap-4 text-[11.5px] text-ink-tertiary flex-wrap pt-0.5">
                                        <span className="flex items-center gap-1">
                                            <Users className="h-3 w-3" />
                                            Redeemed: <strong>{promo.redemptions_count}</strong>
                                            {promo.max_redemptions ? ` / ${promo.max_redemptions}` : ' (unlimited)'}
                                        </span>
                                        {promo.expires_at && (
                                            <span className="flex items-center gap-1">
                                                <Clock className="h-3 w-3" />
                                                Expires: {new Date(promo.expires_at).toLocaleDateString()}
                                            </span>
                                        )}
                                        {promo.applicable_ticket_type_ids && promo.applicable_ticket_type_ids.length > 0 && (
                                            <span className="flex items-center gap-1">
                                                <Key className="h-3 w-3" />
                                                Applies to: {promo.applicable_ticket_type_ids.length} ticket type(s)
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 self-end sm:self-center shrink-0">
                                    <button
                                        type="button"
                                        onClick={() => handleToggle(promo)}
                                        className={`px-2.5 py-1 text-xs font-medium rounded border ${
                                            promo.is_active
                                                ? 'border-border text-ink hover:bg-surface-sunken'
                                                : 'border-accent text-accent hover:bg-accent-soft'
                                        }`}
                                    >
                                        {promo.is_active ? 'Disable' : 'Enable'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => openEditModal(promo)}
                                        className="p-1.5 text-ink-secondary hover:text-ink rounded hover:bg-surface-sunken"
                                        title="Edit code"
                                    >
                                        <Edit2 className="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setDeleteTarget(promo)}
                                        className="p-1.5 text-ink-secondary hover:text-danger-fg rounded hover:bg-surface-sunken"
                                        title="Delete code"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Invite-Only / Hidden Ticket Types Section */}
            <div className="border-t border-border pt-6">
                <div className="flex items-center justify-between mb-4">
                    <div>
                        <h4 className="text-sm font-semibold text-ink flex items-center gap-2">
                            <Lock className="h-4 w-4 text-accent" />
                            Invite-Only & Hidden Tickets
                        </h4>
                        <p className="text-xs text-ink-secondary mt-0.5">
                            Ticket types locked with an access code are hidden from public view until the attendee provides the code.
                        </p>
                    </div>
                </div>

                <div className="space-y-2">
                    {ticketTypes.length === 0 ? (
                        <p className="text-xs text-ink-tertiary">No ticket types configured for this event.</p>
                    ) : (
                        ticketTypes.map((ticket) => (
                            <div 
                                key={ticket.id}
                                className="flex items-center justify-between gap-3 p-3 rounded border border-border bg-surface text-xs"
                            >
                                <div className="flex items-center gap-2.5">
                                    {ticket.access_code ? (
                                        <Lock className="h-4 w-4 text-accent shrink-0" />
                                    ) : (
                                        <Unlock className="h-4 w-4 text-ink-tertiary shrink-0" />
                                    )}
                                    <div>
                                        <strong className="text-ink">{ticket.name}</strong>
                                        <span className="text-ink-secondary ml-2">({formatMoney(ticket.price)})</span>
                                    </div>
                                </div>

                                <div>
                                    {ticket.access_code ? (
                                        <span className="inline-flex items-center gap-1 font-mono font-medium px-2 py-0.5 rounded bg-surface-sunken border border-border text-accent">
                                            Access Code: {ticket.access_code}
                                        </span>
                                    ) : (
                                        <span className="text-ink-tertiary text-[11px]">Publicly visible (No access code)</span>
                                    )}
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>

            {/* Create / Edit Modal */}
            {modalOpen && (
                <Modal 
                    open={modalOpen} 
                    onClose={() => setModalOpen(false)}
                    title={editingPromo ? `Edit Promo Code: ${editingPromo.code}` : 'Create New Promo Code'}
                    maxWidth="max-w-lg"
                >
                    <form onSubmit={handleSave} className="space-y-4">
                        <div className="space-y-1.5">
                            <div className="flex items-center justify-between">
                                <label className="text-xs font-medium text-ink">Promo Code *</label>
                                <button
                                    type="button"
                                    onClick={generateRandomCode}
                                    className="text-[11px] text-accent hover:underline flex items-center gap-1"
                                >
                                    <RefreshCw className="h-3 w-3" />
                                    Generate Random
                                </button>
                            </div>
                            <input
                                type="text"
                                value={form.code}
                                onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                                placeholder="e.g. EARLYBIRD, VIPGUEST"
                                className="w-full font-mono uppercase rounded border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none"
                                required
                            />
                            {errors.code && <p className="text-xs text-danger-fg">{errors.code[0]}</p>}
                        </div>

                        <div>
                            <Input
                                label="Description (Optional)"
                                value={form.description}
                                onChange={(e) => setForm({ ...form, description: e.target.value })}
                                placeholder="e.g. 20% discount for Medical Association members"
                                error={errors.description?.[0]}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-medium text-ink mb-1.5">Discount Type</label>
                                <select
                                    value={form.discount_type}
                                    onChange={(e) => setForm({ ...form, discount_type: e.target.value })}
                                    className="w-full rounded border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none"
                                >
                                    <option value="percentage">Percentage (%) Off</option>
                                    <option value="fixed_amount">Fixed Amount ({event.currency || 'GHS'}) Off</option>
                                    <option value="complimentary">100% Free (Complimentary)</option>
                                </select>
                            </div>

                            {form.discount_type !== 'complimentary' && (
                                <div>
                                    <Input
                                        label={form.discount_type === 'percentage' ? 'Percentage (1-100) *' : `Amount (${event.currency || 'GHS'}) *`}
                                        type="number"
                                        min="1"
                                        max={form.discount_type === 'percentage' ? '100' : undefined}
                                        step={form.discount_type === 'percentage' ? '1' : '0.01'}
                                        value={form.discount_value}
                                        onChange={(e) => setForm({ ...form, discount_value: e.target.value })}
                                        error={errors.discount_value?.[0]}
                                        required
                                    />
                                </div>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <Input
                                label="Max Total Redemptions"
                                type="number"
                                min="1"
                                placeholder="Unlimited if blank"
                                value={form.max_redemptions}
                                onChange={(e) => setForm({ ...form, max_redemptions: e.target.value })}
                                error={errors.max_redemptions?.[0]}
                            />
                            <Input
                                label="Limit per Attendee Email"
                                type="number"
                                min="1"
                                value={form.max_per_attendee}
                                onChange={(e) => setForm({ ...form, max_per_attendee: e.target.value })}
                                error={errors.max_per_attendee?.[0]}
                                required
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className="block text-xs font-medium text-ink mb-1.5">Starts At (Optional)</label>
                                <input
                                    type="datetime-local"
                                    value={form.starts_at}
                                    onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                                    className="w-full rounded border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-ink mb-1.5">Expires At (Optional)</label>
                                <input
                                    type="datetime-local"
                                    value={form.expires_at}
                                    onChange={(e) => setForm({ ...form, expires_at: e.target.value })}
                                    className="w-full rounded border border-border bg-surface px-2.5 py-1.5 text-xs text-ink focus:border-accent focus:outline-none"
                                />
                            </div>
                        </div>

                        {/* Applicable ticket types */}
                        {ticketTypes.length > 0 && (
                            <div>
                                <label className="block text-xs font-medium text-ink mb-1.5">
                                    Applicable Ticket Types (Leave all unchecked to apply to everything)
                                </label>
                                <div className="space-y-1.5 max-h-32 overflow-y-auto border border-border p-2 rounded bg-surface-sunken/40">
                                    {ticketTypes.map(t => (
                                        <label key={t.id} className="flex items-center gap-2 cursor-pointer text-xs text-ink">
                                            <input
                                                type="checkbox"
                                                checked={form.applicable_ticket_type_ids.includes(t.id)}
                                                onChange={() => toggleTicketTypeSelection(t.id)}
                                                className="rounded border-border text-accent"
                                            />
                                            <span>{t.name} ({formatMoney(t.price)})</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="pt-2 flex justify-end gap-2 border-t border-border">
                            <Button type="button" variant="outline" onClick={() => setModalOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" variant="primary" disabled={saving}>
                                {saving ? 'Saving...' : editingPromo ? 'Update Promo Code' : 'Create Promo Code'}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}

            {/* Delete Confirmation Modal */}
            {deleteTarget && (
                <ConfirmModal
                    open={Boolean(deleteTarget)}
                    onClose={() => setDeleteTarget(null)}
                    onConfirm={handleDelete}
                    title="Delete Promo Code"
                    message={`Are you sure you want to delete promo code "${deleteTarget.code}"? Past registrations will retain their applied discount.`}
                    confirmLabel="Delete Code"
                    variant="danger"
                />
            )}
        </div>
    );
}
