import { useState } from 'react';
import { router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import Button from '@/Components/Console/Button';
import PageHeader from '@/Components/Console/PageHeader';
import StatusPill from '@/Components/Console/StatusPill';
import { ArrowLeft, Mail, Phone, Plus, Send, Trash2 } from 'lucide-react';

const STATUS_MAP = {
    pending_quote: 'pending',
    quoted: 'neutral',
    accepted: 'success',
    rejected: 'failed',
    expired: 'neutral',
};

const STATUS_LABELS = {
    pending_quote: 'Quote needed',
    quoted: 'Proposal sent',
    accepted: 'Accepted',
    rejected: 'Declined',
    expired: 'Expired',
};

const inputClass =
    'w-full rounded-lg border border-border bg-surface px-3 py-2 text-xs text-ink focus:border-accent focus:outline-none';

const toPesewas = (ghs) => Math.round((parseFloat(ghs) || 0) * 100);
const toGhs = (pesewas) => (pesewas ? pesewas / 100 : '');
const money = (pesewas) =>
    `GHS ${((pesewas || 0) / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

function Detail({ label, children }) {
    return (
        <div>
            <dt className="text-[11px] font-medium text-ink-secondary">{label}</dt>
            <dd className="mt-0.5 text-sm text-ink">{children || '—'}</dd>
        </div>
    );
}

export default function QuoteShow({ quote }) {
    const editable = ['pending_quote', 'quoted'].includes(quote.status);

    const [items, setItems] = useState(
        quote.items?.length
            ? quote.items.map((item) => ({
                  description: item.description,
                  quantity: item.quantity,
                  unit_price_ghs: toGhs(item.unit_price_pesewas),
              }))
            : [{ description: '', quantity: 1, unit_price_ghs: '' }]
    );
    const [deliveryGhs, setDeliveryGhs] = useState(toGhs(quote.delivery_fee_pesewas));
    const [taxGhs, setTaxGhs] = useState(toGhs(quote.tax_pesewas));
    const [depositGhs, setDepositGhs] = useState(toGhs(quote.deposit_required_pesewas));
    const [validUntil, setValidUntil] = useState('');
    const [notes, setNotes] = useState(quote.vendor_notes || '');
    const [declineReason, setDeclineReason] = useState('');
    const [declining, setDeclining] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    const subtotal = items.reduce(
        (sum, item) => sum + (parseInt(item.quantity, 10) || 0) * toPesewas(item.unit_price_ghs),
        0
    );
    const total = subtotal + toPesewas(deliveryGhs) + toPesewas(taxGhs);

    const updateItem = (index, field, value) =>
        setItems(items.map((item, i) => (i === index ? { ...item, [field]: value } : item)));

    const sendProposal = (e) => {
        e.preventDefault();
        setSubmitting(true);
        router.post(
            route('tenant.venue.quotes.proposal', { quote: quote.id }),
            {
                items: items.map((item) => ({
                    description: item.description,
                    quantity: parseInt(item.quantity, 10) || 1,
                    unit_price_pesewas: toPesewas(item.unit_price_ghs),
                })),
                delivery_fee_pesewas: toPesewas(deliveryGhs),
                tax_pesewas: toPesewas(taxGhs),
                // Blank means the whole total is due to confirm.
                deposit_required_pesewas: depositGhs === '' ? total : toPesewas(depositGhs),
                valid_until: validUntil || null,
                vendor_notes: notes || null,
            },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => setErrors({}),
                onFinish: () => setSubmitting(false),
            }
        );
    };

    const decline = () =>
        router.post(
            route('tenant.venue.quotes.reject', { quote: quote.id }),
            { reason: declineReason || null },
            { onFinish: () => setDeclining(false) }
        );

    return (
        <ConsoleLayout>
            <div className="space-y-6">
                <PageHeader
                    title={`Quote ${quote.quote_reference}`}
                    actions={
                        <Button
                            href={route('tenant.venue.quotes.index')}
                            variant="default"
                            icon={ArrowLeft}
                        >
                            All quotes
                        </Button>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="space-y-4 rounded-xl border border-border bg-surface p-5 lg:col-span-1">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-ink">Request</h2>
                            <StatusPill status={STATUS_MAP[quote.status] || 'neutral'}>
                                {STATUS_LABELS[quote.status] || quote.status}
                            </StatusPill>
                        </div>
                        <dl className="space-y-3">
                            <Detail label="Listing">{quote.listing?.title}</Detail>
                            <Detail label="Requested by">{quote.planner_name}</Detail>
                            <Detail label="Event">{quote.event_title}</Detail>
                            <Detail label="Event date">{quote.event_date}</Detail>
                            <Detail label="Guests">{quote.guest_count}</Detail>
                            <Detail label="Location">{quote.location_address}</Detail>
                        </dl>
                        <div>
                            <div className="text-[11px] font-medium text-ink-secondary">
                                What they need
                            </div>
                            <p className="mt-1 whitespace-pre-line text-sm text-ink">
                                {quote.requirements_description}
                            </p>
                        </div>
                        <div className="flex flex-col gap-1.5 border-t border-border pt-4 text-xs">
                            <a
                                href={`mailto:${quote.planner_email}`}
                                className="inline-flex items-center gap-2 text-accent hover:underline"
                            >
                                <Mail className="h-3.5 w-3.5" /> {quote.planner_email}
                            </a>
                            {quote.planner_phone && (
                                <a
                                    href={`tel:${quote.planner_phone}`}
                                    className="inline-flex items-center gap-2 text-accent hover:underline"
                                >
                                    <Phone className="h-3.5 w-3.5" /> {quote.planner_phone}
                                </a>
                            )}
                        </div>
                    </section>

                    <section className="space-y-4 rounded-xl border border-border bg-surface p-5 lg:col-span-2">
                        {editable ? (
                            <form onSubmit={sendProposal} className="space-y-4 text-xs">
                                <h2 className="text-sm font-semibold text-ink">
                                    {quote.status === 'quoted'
                                        ? 'Revise your proposal'
                                        : 'Build your proposal'}
                                </h2>

                                <div className="space-y-2">
                                    <div className="hidden grid-cols-12 gap-2 text-[11px] font-medium text-ink-secondary sm:grid">
                                        <span className="col-span-7">Item</span>
                                        <span className="col-span-2">Qty</span>
                                        <span className="col-span-3">Unit price (GHS)</span>
                                    </div>
                                    {items.map((item, index) => (
                                        <div key={index} className="grid grid-cols-12 gap-2">
                                            <input
                                                className={`${inputClass} col-span-12 sm:col-span-7`}
                                                placeholder="e.g. Line array PA system, 1 day"
                                                value={item.description}
                                                onChange={(e) =>
                                                    updateItem(index, 'description', e.target.value)
                                                }
                                                required
                                            />
                                            <input
                                                className={`${inputClass} col-span-4 sm:col-span-2`}
                                                type="number"
                                                min="1"
                                                value={item.quantity}
                                                onChange={(e) =>
                                                    updateItem(index, 'quantity', e.target.value)
                                                }
                                                required
                                            />
                                            <div className="col-span-8 flex gap-2 sm:col-span-3">
                                                <input
                                                    className={inputClass}
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    value={item.unit_price_ghs}
                                                    onChange={(e) =>
                                                        updateItem(
                                                            index,
                                                            'unit_price_ghs',
                                                            e.target.value
                                                        )
                                                    }
                                                    required
                                                />
                                                {items.length > 1 && (
                                                    <button
                                                        type="button"
                                                        aria-label="Remove item"
                                                        onClick={() =>
                                                            setItems(
                                                                items.filter((_, i) => i !== index)
                                                            )
                                                        }
                                                        className="text-ink-tertiary hover:text-danger-fg"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                    <Button
                                        type="button"
                                        variant="default"
                                        icon={Plus}
                                        onClick={() =>
                                            setItems([
                                                ...items,
                                                {
                                                    description: '',
                                                    quantity: 1,
                                                    unit_price_ghs: '',
                                                },
                                            ])
                                        }
                                    >
                                        Add item
                                    </Button>
                                    {errors.items && (
                                        <p className="text-danger-fg">{errors.items}</p>
                                    )}
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="space-y-1">
                                        <span className="block font-medium text-ink-secondary">
                                            Delivery / rigging fee (GHS)
                                        </span>
                                        <input
                                            className={inputClass}
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={deliveryGhs}
                                            onChange={(e) => setDeliveryGhs(e.target.value)}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="block font-medium text-ink-secondary">
                                            Tax (GHS)
                                        </span>
                                        <input
                                            className={inputClass}
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={taxGhs}
                                            onChange={(e) => setTaxGhs(e.target.value)}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="block font-medium text-ink-secondary">
                                            Deposit to confirm (GHS)
                                        </span>
                                        <input
                                            className={inputClass}
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="Blank = full total"
                                            value={depositGhs}
                                            onChange={(e) => setDepositGhs(e.target.value)}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="block font-medium text-ink-secondary">
                                            Valid until
                                        </span>
                                        <input
                                            className={inputClass}
                                            type="datetime-local"
                                            value={validUntil}
                                            onChange={(e) => setValidUntil(e.target.value)}
                                        />
                                        <span className="block text-[10px] text-ink-tertiary">
                                            {quote.formatted_valid_until
                                                ? `Currently ${quote.formatted_valid_until}. `
                                                : ''}
                                            Blank = 7 days from sending.
                                        </span>
                                        {errors.valid_until && (
                                            <span className="block text-danger-fg">
                                                {errors.valid_until}
                                            </span>
                                        )}
                                    </label>
                                </div>

                                <label className="block space-y-1">
                                    <span className="block font-medium text-ink-secondary">
                                        Notes for the client (optional)
                                    </span>
                                    <textarea
                                        rows="3"
                                        className={inputClass}
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                    />
                                </label>

                                <div className="flex flex-col gap-3 border-t border-border pt-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="text-ink-secondary">
                                        Subtotal {money(subtotal)} ·{' '}
                                        <span className="font-semibold text-ink">
                                            Total {money(total)}
                                        </span>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="button"
                                            variant="default"
                                            onClick={() => setDeclining(true)}
                                        >
                                            Decline
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="primary"
                                            icon={Send}
                                            disabled={submitting}
                                        >
                                            {submitting ? 'Sending...' : 'Send to client'}
                                        </Button>
                                    </div>
                                </div>
                            </form>
                        ) : (
                            <div className="space-y-4 text-sm">
                                <h2 className="text-sm font-semibold text-ink">Proposal</h2>
                                {quote.items?.length > 0 ? (
                                    <ul className="divide-y divide-border">
                                        {quote.items.map((item, index) => (
                                            <li
                                                key={index}
                                                className="flex justify-between gap-4 py-2"
                                            >
                                                <span className="text-ink">
                                                    {item.quantity} × {item.description}
                                                </span>
                                                <span className="text-ink-secondary">
                                                    {money(item.quantity * item.unit_price_pesewas)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-ink-secondary">No proposal was sent.</p>
                                )}
                                <dl className="grid gap-3 sm:grid-cols-3">
                                    <Detail label="Total">{quote.formatted_total}</Detail>
                                    <Detail label="Deposit">{quote.formatted_deposit}</Detail>
                                    <Detail label="Paid">{quote.formatted_amount_paid}</Detail>
                                </dl>
                                {quote.vendor_notes && (
                                    <Detail label="Notes">{quote.vendor_notes}</Detail>
                                )}
                            </div>
                        )}
                    </section>
                </div>

                {declining && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                        <div className="w-full max-w-md space-y-4 rounded-2xl border border-border bg-surface p-6 text-xs shadow-2xl">
                            <h3 className="text-sm font-bold text-ink">
                                Decline {quote.quote_reference}?
                            </h3>
                            <textarea
                                rows="3"
                                placeholder="Optional reason, e.g. fully booked on that date"
                                value={declineReason}
                                onChange={(e) => setDeclineReason(e.target.value)}
                                className={inputClass}
                            />
                            <div className="flex justify-end gap-2">
                                <Button variant="default" onClick={() => setDeclining(false)}>
                                    Cancel
                                </Button>
                                <Button
                                    variant="default"
                                    className="border-danger-fg/40 text-danger-fg hover:bg-danger-bg"
                                    onClick={decline}
                                >
                                    Confirm decline
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ConsoleLayout>
    );
}
