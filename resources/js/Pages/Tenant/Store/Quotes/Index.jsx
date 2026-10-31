import { useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Modal from '@/Components/Console/Modal';
import SellerQuoteForm from '@/Components/Marketplace/SellerQuoteForm';
import { OrderSummary } from '@/Pages/Public/Marketplace/Orders/Index';
import { sendJson } from '@/lib/dues';

export default function SellerQuotes({
    quotes = { data: [], links: [] },
    variants = [],
    fulfilment_policy = null,
    can_issue = false,
    issuance_message = null,
}) {
    const [items, setItems] = useState(quotes.data);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [withdraw, setWithdraw] = useState(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const issued = useRef(null);
    const showIssued = (quote) => {
        setItems((current) => [quote, ...current.filter((item) => item.id !== quote.id)]);
        setMessage('Quote issued. Copy its private payment link and share it with the buyer.');
        setTimeout(() => issued.current?.focus(), 0);
    };
    const copy = async (quote) => {
        setError('');
        try {
            await navigator.clipboard.writeText(quote.payment_link);
            setMessage('Payment link copied. Share it with the buyer at the recorded email.');
        } catch {
            setError('Automatic copy is unavailable. Select and copy the payment link below.');
        }
    };
    const confirmWithdrawal = async (event) => {
        event.preventDefault();
        if (!withdraw || busy || !reason.trim()) return;
        setBusy(true);
        setError('');
        const result = await sendJson(
            'POST',
            route('tenant.store.quotes.withdraw', { quote: withdraw.id }),
            { reason }
        );
        setBusy(false);
        if (!result.ok) {
            setError(Object.values(result.errors).flat().join(' ') || result.message);
            return;
        }
        setItems((current) =>
            current.map((item) => (item.id === withdraw.id ? result.body.quote : item))
        );
        setWithdraw(null);
        setReason('');
        setMessage('Quote withdrawn. Its payment link can no longer start a new payment.');
    };
    return (
        <ConsoleLayout>
            <Head title="Store quotes" />
            <PageHeader title="Store quotes" />
            <div className="mx-auto w-full max-w-6xl space-y-7 p-5 text-ink sm:p-8">
                <nav
                    aria-label="Store sections"
                    className="flex flex-wrap gap-5 text-sm text-accent underline"
                >
                    <Link href={route('tenant.store.orders.index')}>Store orders</Link>
                </nav>
                <div className="border-l-2 border-accent bg-accent/5 p-5">
                    <h2 className="font-semibold">From an agreed specification to a paid order</h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-ink-secondary">
                        Share a private link after agreeing the goods, printing, delivery and
                        production time. Customers must verify the buyer email and accept the quoted
                        terms before paying. Payment receipts and collection or delivery follow the
                        existing order journey.
                    </p>
                </div>
                {issuance_message && (
                    <p role="status" className="border border-border bg-surface p-4 text-sm">
                        {issuance_message}
                    </p>
                )}
                {message && (
                    <p
                        ref={issued}
                        role="status"
                        tabIndex={-1}
                        className="border border-accent/30 bg-accent/5 p-4 text-sm"
                    >
                        {message}
                    </p>
                )}
                {error && !withdraw && (
                    <p
                        role="alert"
                        className="border-l-2 border-danger-fg bg-danger-bg p-4 text-sm text-danger-fg"
                    >
                        {error}
                    </p>
                )}
                <SellerQuoteForm
                    variants={variants}
                    policy={fulfilment_policy}
                    canIssue={can_issue}
                    onIssued={showIssued}
                />
                <section aria-labelledby="issued-quotes-heading" className="space-y-5">
                    <h2 id="issued-quotes-heading" className="text-xl font-semibold">
                        Issued quotes
                    </h2>
                    {items.length ? (
                        items.map((quote) => (
                            <article
                                key={quote.id}
                                className="min-w-0 space-y-5 border border-border bg-surface p-5 sm:p-7"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p className="text-sm font-medium">{quote.buyer_email}</p>
                                        <p className="mt-1 text-xs text-ink-secondary">
                                            Issued{' '}
                                            {quote.issued_at
                                                ? new Date(quote.issued_at).toLocaleString()
                                                : 'just now'}{' '}
                                            · Valid until{' '}
                                            {new Date(quote.order.expires_at).toLocaleString()}
                                        </p>
                                    </div>
                                    {quote.can_withdraw && (
                                        <Button
                                            type="button"
                                            className="rounded-none"
                                            onClick={() => {
                                                setWithdraw(quote);
                                                setReason('');
                                                setError('');
                                            }}
                                        >
                                            Withdraw quote
                                        </Button>
                                    )}
                                </div>
                                <OrderSummary order={quote.order} />
                                {quote.withdrawn_at && (
                                    <p className="border-l-2 border-border p-3 text-sm text-ink-secondary">
                                        Withdrawn · {quote.withdrawal_reason}
                                    </p>
                                )}
                                <div className="space-y-3 border-t border-border pt-5">
                                    <Input
                                        label={`Private payment link for ${quote.order.number}`}
                                        readOnly
                                        value={quote.payment_link}
                                        onFocus={(e) => e.target.select()}
                                        className="rounded-none"
                                    />
                                    <Button
                                        type="button"
                                        className="rounded-none"
                                        aria-label={`Copy payment link for ${quote.order.number}`}
                                        onClick={() => copy(quote)}
                                    >
                                        Copy payment link
                                    </Button>
                                </div>
                            </article>
                        ))
                    ) : (
                        <div className="border border-dashed border-border bg-surface p-7">
                            <p className="font-medium">No issued quotes yet</p>
                            <p className="mt-2 text-sm text-ink-secondary">
                                Create the first quote above after confirming the buyer’s
                                requirements.
                            </p>
                        </div>
                    )}
                    {quotes.links?.length > 3 && (
                        <nav aria-label="Quote pages" className="flex flex-wrap gap-2">
                            {quotes.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        aria-current={link.active ? 'page' : undefined}
                                        className="border border-border px-3 py-2 text-sm"
                                    >
                                        {link.label.replace(/&laquo;|&raquo;/g, '').trim()}
                                    </Link>
                                ) : null
                            )}
                        </nav>
                    )}
                </section>
            </div>
            <Modal
                open={Boolean(withdraw)}
                onClose={() => !busy && setWithdraw(null)}
                title="Withdraw this quote?"
            >
                <form onSubmit={confirmWithdrawal} className="space-y-5">
                    <p className="text-sm leading-6 text-ink-secondary">
                        This preserves the original quote and prevents a new payment. Quotes with
                        unresolved payments must be reconciled first.
                    </p>
                    <Input
                        label="Reason for withdrawal"
                        required
                        maxLength={500}
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        className="rounded-none"
                    />
                    {error && (
                        <p role="alert" className="text-sm text-danger-fg">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-wrap justify-end gap-3">
                        <Button type="button" disabled={busy} onClick={() => setWithdraw(null)}>
                            Keep quote
                        </Button>
                        <Button type="submit" disabled={busy || !reason.trim()}>
                            {busy ? 'Withdrawing…' : 'Confirm withdrawal'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </ConsoleLayout>
    );
}
