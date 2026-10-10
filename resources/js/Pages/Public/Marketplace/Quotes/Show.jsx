import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { AlertCircle, ArrowLeft, CheckCircle2, Clock, Mail, Phone, Star } from 'lucide-react';

const money = (pesewas) =>
    `GHS ${((pesewas || 0) / 100).toLocaleString('en-GH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;

const STATUS = {
    pending_quote: {
        label: 'Waiting for the vendor’s quote',
        icon: Clock,
        tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    },
    quoted: {
        label: 'Quote ready',
        icon: AlertCircle,
        tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    },
    accepted: {
        label: 'Accepted',
        icon: CheckCircle2,
        tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    rejected: {
        label: 'Declined by the vendor',
        icon: AlertCircle,
        tone: 'bg-red-500/10 text-red-600 dark:text-red-400',
    },
    expired: {
        label: 'Expired',
        icon: Clock,
        tone: 'bg-slate-500/10 text-ink-secondary',
    },
};

function Flash() {
    const { flash } = usePage().props;
    if (!flash?.success && !flash?.error) {
        return null;
    }

    return (
        <div
            className={`mb-6 rounded-xl border px-4 py-3 text-sm ${
                flash.error
                    ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300'
                    : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300'
            }`}
        >
            {flash.error || flash.success}
        </div>
    );
}

function ReviewForm({ reference }) {
    const [rating, setRating] = useState(5);
    const [title, setTitle] = useState('');
    const [comment, setComment] = useState('');
    const [errors, setErrors] = useState({});
    const [sending, setSending] = useState(false);

    const submit = (e) => {
        e.preventDefault();
        setSending(true);
        router.post(
            route('marketplace.quotes.review', { reference }),
            { rating, title: title || null, comment },
            { preserveScroll: true, onError: setErrors, onFinish: () => setSending(false) }
        );
    };

    return (
        <form onSubmit={submit} className="space-y-3 text-sm">
            <h2 className="font-semibold text-ink">Review this vendor</h2>
            <div className="flex gap-1" role="radiogroup" aria-label="Rating">
                {[1, 2, 3, 4, 5].map((value) => (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={rating === value}
                        aria-label={`${value} star${value > 1 ? 's' : ''}`}
                        onClick={() => setRating(value)}
                    >
                        <Star
                            className={`h-6 w-6 ${value <= rating ? 'fill-amber-400 text-amber-400' : 'text-ink-tertiary'}`}
                        />
                    </button>
                ))}
            </div>
            <input
                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink focus:border-accent focus:outline-none"
                placeholder="Headline (optional)"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
            />
            <textarea
                rows="3"
                required
                className="w-full rounded-lg border border-border bg-surface px-3 py-2 text-ink focus:border-accent focus:outline-none"
                placeholder="How was working with them?"
                value={comment}
                onChange={(e) => setComment(e.target.value)}
            />
            {errors.comment && <p className="text-xs text-red-600">{errors.comment}</p>}
            <button
                type="submit"
                disabled={sending}
                className="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-accent-ink disabled:opacity-50"
            >
                {sending ? 'Publishing…' : 'Publish review'}
            </button>
        </form>
    );
}

export default function QuoteShow({ quote, existingReview }) {
    const [paying, setPaying] = useState(false);
    const status = STATUS[quote.status] ?? STATUS.pending_quote;
    const StatusIcon = status.icon;
    const canAccept =
        quote.status === 'quoted' && !quote.is_expired && !quote.venue_payments_paused;

    const accept = () => {
        setPaying(true);
        router.post(
            route('marketplace.quotes.checkout', { reference: quote.quote_reference }),
            {},
            { onFinish: () => setPaying(false) }
        );
    };

    return (
        <MarketplaceLayout>
            <Head title={`Quote ${quote.quote_reference} | MiConvener Marketplace`} />

            <div className="mx-auto max-w-3xl px-4 py-10 sm:px-6 lg:px-8">
                {quote.shop?.slug && (
                    <div className="mb-6">
                        <Link
                            href={route('marketplace.storefront.show', {
                                merchant_slug: quote.shop.slug,
                            })}
                            className="inline-flex items-center gap-1.5 text-xs font-medium text-ink-secondary transition-colors hover:text-ink"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            <span>Back to {quote.shop.name}</span>
                        </Link>
                    </div>
                )}

                <Flash />

                <div className="space-y-6 rounded-2xl border border-border bg-surface p-6 shadow-sm sm:p-8">
                    <div className="space-y-2 border-b border-border pb-6">
                        <div className="flex flex-wrap items-center gap-2">
                            <span
                                className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold ${status.tone}`}
                            >
                                <StatusIcon className="h-3.5 w-3.5" />
                                {quote.status === 'quoted' && quote.is_expired
                                    ? 'Quote expired'
                                    : status.label}
                            </span>
                            <span className="font-mono text-xs font-bold text-ink">
                                {quote.quote_reference}
                            </span>
                        </div>
                        <h1 className="text-2xl font-bold tracking-tight text-ink">
                            {quote.listing?.title}
                        </h1>
                        <p className="text-sm text-ink-secondary">
                            From {quote.shop?.name} for {quote.planner_name}
                            {quote.event_title ? ` · ${quote.event_title}` : ''}
                            {quote.event_date ? ` · ${quote.event_date}` : ''}
                        </p>
                    </div>

                    {quote.status === 'pending_quote' ? (
                        <div className="space-y-2 text-sm">
                            <p className="text-ink">
                                {quote.shop?.name} is preparing an itemized quote. We will email{' '}
                                <strong>{quote.planner_email}</strong> when it is ready.
                            </p>
                            <p className="whitespace-pre-line rounded-lg bg-surface-hover p-3 text-ink-secondary">
                                {quote.requirements_description}
                            </p>
                        </div>
                    ) : quote.items?.length > 0 ? (
                        <div className="space-y-4 text-sm">
                            <ul className="divide-y divide-border">
                                {quote.items.map((item, index) => (
                                    <li key={index} className="flex justify-between gap-4 py-2">
                                        <span className="text-ink">
                                            {item.quantity} × {item.description}
                                        </span>
                                        <span className="text-ink-secondary">
                                            {money(item.quantity * item.unit_price_pesewas)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <dl className="space-y-1 border-t border-border pt-3">
                                {quote.delivery_fee_pesewas > 0 && (
                                    <div className="flex justify-between text-ink-secondary">
                                        <dt>Delivery / rigging</dt>
                                        <dd>{quote.formatted_delivery_fee}</dd>
                                    </div>
                                )}
                                {quote.tax_pesewas > 0 && (
                                    <div className="flex justify-between text-ink-secondary">
                                        <dt>Tax</dt>
                                        <dd>{quote.formatted_tax}</dd>
                                    </div>
                                )}
                                <div className="flex justify-between font-semibold text-ink">
                                    <dt>Total</dt>
                                    <dd>{quote.formatted_total}</dd>
                                </div>
                                <div className="flex justify-between text-ink-secondary">
                                    <dt>Deposit to confirm</dt>
                                    <dd>{quote.formatted_deposit}</dd>
                                </div>
                                {quote.service_fee_pesewas > 0 && (
                                    <div className="flex justify-between text-ink-secondary">
                                        <dt>
                                            MiConvener service fee ({quote.service_fee_percent}%)
                                            <span className="block text-xs text-ink-tertiary">
                                                None for organisers on Growth or Enterprise
                                            </span>
                                        </dt>
                                        <dd>{quote.formatted_service_fee}</dd>
                                    </div>
                                )}
                                {quote.amount_paid_pesewas > 0 && (
                                    <div className="flex justify-between text-emerald-600 dark:text-emerald-400">
                                        <dt>Paid</dt>
                                        <dd>{quote.formatted_amount_paid}</dd>
                                    </div>
                                )}
                            </dl>
                            {quote.vendor_notes && (
                                <p className="whitespace-pre-line rounded-lg bg-surface-hover p-3 text-ink-secondary">
                                    {quote.vendor_notes}
                                </p>
                            )}
                            {quote.formatted_valid_until && quote.status === 'quoted' && (
                                <p className="text-xs text-ink-tertiary">
                                    Valid until {quote.formatted_valid_until}
                                </p>
                            )}
                        </div>
                    ) : (
                        quote.vendor_notes && (
                            <p className="text-sm text-ink-secondary">{quote.vendor_notes}</p>
                        )
                    )}

                    {quote.venue_payments_paused && !quote.is_deposit_paid && (
                        <p
                            role="status"
                            className="rounded-xl border border-border bg-canvas p-4 text-sm text-ink-secondary"
                        >
                            Online payments are paused for this venue. You can still discuss your
                            enquiry with the host.
                        </p>
                    )}

                    {canAccept && (
                        <button
                            type="button"
                            onClick={accept}
                            disabled={paying}
                            className="w-full rounded-xl bg-accent px-5 py-3 text-sm font-semibold text-accent-ink disabled:opacity-50"
                        >
                            {paying
                                ? 'Opening payment…'
                                : `Accept and pay ${quote.formatted_due_now}`}
                        </button>
                    )}

                    <div className="flex flex-wrap gap-4 border-t border-border pt-4 text-xs">
                        {quote.shop?.email && (
                            <a
                                href={`mailto:${quote.shop.email}`}
                                className="inline-flex items-center gap-1.5 text-accent hover:underline"
                            >
                                <Mail className="h-3.5 w-3.5" /> {quote.shop.email}
                            </a>
                        )}
                        {quote.shop?.phone && (
                            <a
                                href={`tel:${quote.shop.phone}`}
                                className="inline-flex items-center gap-1.5 text-accent hover:underline"
                            >
                                <Phone className="h-3.5 w-3.5" /> {quote.shop.phone}
                            </a>
                        )}
                    </div>
                </div>

                {quote.status === 'accepted' && (
                    <div className="mt-6 rounded-2xl border border-border bg-surface p-6 shadow-sm">
                        {existingReview ? (
                            <div className="space-y-1 text-sm">
                                <div className="flex gap-0.5">
                                    {[1, 2, 3, 4, 5].map((value) => (
                                        <Star
                                            key={value}
                                            className={`h-4 w-4 ${value <= existingReview.rating ? 'fill-amber-400 text-amber-400' : 'text-ink-tertiary'}`}
                                        />
                                    ))}
                                </div>
                                {existingReview.title && (
                                    <p className="font-semibold text-ink">{existingReview.title}</p>
                                )}
                                <p className="text-ink-secondary">{existingReview.comment}</p>
                            </div>
                        ) : (
                            <ReviewForm reference={quote.quote_reference} />
                        )}
                    </div>
                )}
            </div>
        </MarketplaceLayout>
    );
}
