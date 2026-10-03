import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    CreditCard,
    Building2,
    Smartphone,
    Banknote,
    UploadCloud,
    CheckCircle2,
    Clock,
    AlertCircle,
    FileText,
    Copy,
    Check,
    ArrowLeft,
    ExternalLink,
    ShieldCheck,
    ChevronRight,
} from 'lucide-react';
import PublicLayout from '@/Layouts/PublicLayout';
import Button from '@/Components/Console/Button';

function formatMoney(amount, currency) {
    if (amount === 0 || amount === '0') return 'Free';
    return `${currency || 'GHS'} ${(Number(amount) / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

export default function Checkout({ event, registration, paystack_available, workspace_url }) {
    const isPendingVerification = registration.offline_payment_status === 'pending_verification';
    const isRejected = registration.offline_payment_status === 'rejected';

    const [selectedGateway, setSelectedGateway] = useState(() => {
        if (registration.payment_method && registration.payment_method !== 'paystack') {
            return 'offline';
        }
        return paystack_available ? 'paystack' : 'offline';
    });

    const [offlineMethod, setOfflineMethod] = useState(() => {
        if (registration.payment_method && registration.payment_method.startsWith('offline_')) {
            return registration.payment_method;
        }
        if (event.offline_payment_bank_name) return 'offline_bank';
        if (event.offline_payment_momo_number) return 'offline_momo';
        return 'offline_cash';
    });

    const [copiedField, setCopiedField] = useState(null);

    const copyToClipboard = (text, field) => {
        navigator.clipboard.writeText(text);
        setCopiedField(field);
        setTimeout(() => setCopiedField(null), 2000);
    };

    // Form for Paystack
    const { post: postPaystack, processing: paystackProcessing } = useForm();

    const handlePaystackCheckout = (e) => {
        e.preventDefault();
        postPaystack(route('public.events.checkout.paystack', {
            event: event.slug,
            registration: registration.id,
        }));
    };

    // Form for Offline Proof Upload
    const {
        data: proofData,
        setData: setProofData,
        post: postProof,
        processing: proofProcessing,
        errors: proofErrors,
    } = useForm({
        proof_file: null,
        payment_method: offlineMethod,
        offline_payment_reference: registration.offline_payment_reference || '',
        offline_payment_notes: '',
    });

    const handleFileChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setProofData('proof_file', file);
        }
    };

    const handleProofSubmit = (e) => {
        e.preventDefault();
        postProof(route('public.events.checkout.offline-proof', {
            event: event.slug,
            registration: registration.id,
        }), {
            forceFormData: true,
        });
    };

    return (
        <PublicLayout>
            <Head title={`Complete Checkout — ${event.name}`} />

            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 sm:py-12">
                {/* Back to event link */}
                <div className="mb-6">
                    <Link
                        href={route('public.events.show', { event: event.slug })}
                        className="inline-flex items-center gap-1.5 text-sm font-medium text-ink-muted hover:text-ink transition-colors"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to {event.name}
                    </Link>
                </div>

                <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
                    {/* Main Checkout Interaction Column */}
                    <div className="lg:col-span-7 space-y-6">
                        {/* Status Alert if Pending Verification */}
                        {isPendingVerification && (
                            <div className="rounded-xl border border-amber-300 bg-amber-50/70 p-6 dark:border-amber-900/60 dark:bg-amber-950/20">
                                <div className="flex items-start gap-4">
                                    <div className="rounded-full bg-amber-100 p-2.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">
                                        <Clock className="h-6 w-6 animate-pulse" />
                                    </div>
                                    <div className="space-y-2 flex-1">
                                        <h3 className="font-semibold text-amber-950 dark:text-amber-100">
                                            Payment Proof Under Review
                                        </h3>
                                        <p className="text-sm text-amber-800 dark:text-amber-300 leading-relaxed">
                                            Your proof of payment has been submitted. The event organizer has been notified and is verifying your transaction.
                                            Once verified, your confirmed ticket and pass will be emailed to <strong className="font-medium">{registration.email}</strong>.
                                        </p>
                                        {registration.offline_payment_reference && (
                                            <div className="mt-3 inline-flex items-center gap-2 rounded-md bg-amber-100/80 px-2.5 py-1 text-xs font-mono text-amber-900 dark:bg-amber-900/60 dark:text-amber-200">
                                                <span>Ref:</span>
                                                <span className="font-semibold">{registration.offline_payment_reference}</span>
                                            </div>
                                        )}
                                        {registration.has_proof && registration.proof_url && (
                                            <div className="pt-2">
                                                <a
                                                    href={registration.proof_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-900 underline hover:text-amber-950 dark:text-amber-200"
                                                >
                                                    <FileText className="h-3.5 w-3.5" />
                                                    View Uploaded Slip
                                                    <ExternalLink className="h-3 w-3" />
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Rejection Alert if Rejected */}
                        {isRejected && (
                            <div className="rounded-xl border border-rose-300 bg-rose-50/70 p-6 dark:border-rose-900/60 dark:bg-rose-950/20">
                                <div className="flex items-start gap-4">
                                    <div className="rounded-full bg-rose-100 p-2.5 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                        <AlertCircle className="h-6 w-6" />
                                    </div>
                                    <div className="space-y-2 flex-1">
                                        <h3 className="font-semibold text-rose-950 dark:text-rose-100">
                                            Payment Proof Not Verified
                                        </h3>
                                        <p className="text-sm text-rose-800 dark:text-rose-300 leading-relaxed">
                                            The organizer could not confirm your previous payment slip.
                                            {registration.offline_payment_notes && (
                                                <span className="block mt-2 font-medium italic border-l-2 border-rose-400 pl-2">
                                                    &ldquo;{registration.offline_payment_notes}&rdquo;
                                                </span>
                                            )}
                                        </p>
                                        <p className="text-xs text-rose-700 dark:text-rose-400 pt-1">
                                            You can re-upload a clear slip with transaction reference below, or switch to instant online payment.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* If NOT pending verification, show the payment options */}
                        {!isPendingVerification && (
                            <div className="rounded-xl border border-border bg-surface p-6 shadow-sm">
                                <h2 className="text-lg font-semibold text-ink">Choose Payment Method</h2>
                                <p className="mt-1 text-sm text-ink-muted">
                                    Select how you would like to complete your registration payment.
                                </p>

                                {/* Method Switcher Tabs */}
                                <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    {paystack_available && (
                                        <button
                                            type="button"
                                            onClick={() => setSelectedGateway('paystack')}
                                            className={`flex items-start gap-3 rounded-lg border p-4 text-left transition-all ${
                                                selectedGateway === 'paystack'
                                                    ? 'border-accent bg-accent-soft/40 ring-1 ring-accent'
                                                    : 'border-border bg-surface hover:bg-surface-hover'
                                            }`}
                                        >
                                            <div className="rounded-md bg-accent-soft p-2 text-accent">
                                                <CreditCard className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <div className="font-medium text-ink flex items-center gap-1.5">
                                                    Pay Online
                                                    <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                        Instant
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs text-ink-muted">
                                                    Card, Mobile Money & Bank via Paystack
                                                </p>
                                            </div>
                                        </button>
                                    )}

                                    {event.allow_offline_payments && (
                                        <button
                                            type="button"
                                            onClick={() => setSelectedGateway('offline')}
                                            className={`flex items-start gap-3 rounded-lg border p-4 text-left transition-all ${
                                                selectedGateway === 'offline'
                                                    ? 'border-accent bg-accent-soft/40 ring-1 ring-accent'
                                                    : 'border-border bg-surface hover:bg-surface-hover'
                                            }`}
                                        >
                                            <div className="rounded-md bg-accent-soft p-2 text-accent">
                                                <Building2 className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <div className="font-medium text-ink flex items-center gap-1.5">
                                                    Direct Transfer
                                                    <span className="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">
                                                        Manual
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs text-ink-muted">
                                                    Direct Bank / MoMo / Cash on site
                                                </p>
                                            </div>
                                        </button>
                                    )}
                                </div>

                                {/* Gateway 1: Paystack Online Flow */}
                                {selectedGateway === 'paystack' && paystack_available && (
                                    <div className="mt-6 rounded-lg border border-border bg-canvas/40 p-5">
                                        <div className="flex items-center gap-2 text-sm font-medium text-ink">
                                            <ShieldCheck className="h-4 w-4 text-emerald-600" />
                                            Secure automated processing
                                        </div>
                                        <p className="mt-2 text-xs text-ink-muted leading-relaxed">
                                            You will be redirected to the secure Paystack portal to pay {formatMoney(registration.charged_amount, registration.currency)} via Mastercard, Visa, or your preferred Mobile Money provider (MTN, Telecel, AT). Once authorized, your ticket code will activate instantly.
                                        </p>
                                        <div className="mt-5">
                                            <Button
                                                variant="primary"
                                                onClick={handlePaystackCheckout}
                                                disabled={paystackProcessing}
                                                className="w-full justify-center py-2.5 text-sm"
                                            >
                                                {paystackProcessing ? 'Redirecting to payment...' : `Pay ${formatMoney(registration.charged_amount, registration.currency)} Online`}
                                            </Button>
                                        </div>
                                    </div>
                                )}

                                {/* Gateway 2: Direct Offline Payment Flow */}
                                {selectedGateway === 'offline' && event.allow_offline_payments && (
                                    <div className="mt-6 space-y-6">
                                        {/* Sub-method radio toggles */}
                                        <div className="flex flex-wrap gap-2">
                                            {event.offline_payment_bank_name && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setOfflineMethod('offline_bank');
                                                        setProofData('payment_method', 'offline_bank');
                                                    }}
                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors ${
                                                        offlineMethod === 'offline_bank'
                                                            ? 'border-accent bg-accent text-accent-ink'
                                                            : 'border-border bg-surface text-ink hover:bg-surface-hover'
                                                    }`}
                                                >
                                                    <Building2 className="h-3.5 w-3.5" />
                                                    Bank Transfer
                                                </button>
                                            )}

                                            {event.offline_payment_momo_number && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setOfflineMethod('offline_momo');
                                                        setProofData('payment_method', 'offline_momo');
                                                    }}
                                                    className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors ${
                                                        offlineMethod === 'offline_momo'
                                                            ? 'border-accent bg-accent text-accent-ink'
                                                            : 'border-border bg-surface text-ink hover:bg-surface-hover'
                                                    }`}
                                                >
                                                    <Smartphone className="h-3.5 w-3.5" />
                                                    Mobile Money
                                                </button>
                                            )}

                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setOfflineMethod('offline_cash');
                                                    setProofData('payment_method', 'offline_cash');
                                                }}
                                                className={`inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs font-medium transition-colors ${
                                                    offlineMethod === 'offline_cash'
                                                        ? 'border-accent bg-accent text-accent-ink'
                                                        : 'border-border bg-surface text-ink hover:bg-surface-hover'
                                                }`}
                                            >
                                                <Banknote className="h-3.5 w-3.5" />
                                                Cash on Site / Other
                                            </button>
                                        </div>

                                        {/* Banking Details Box */}
                                        {offlineMethod === 'offline_bank' && event.offline_payment_bank_name && (
                                            <div className="rounded-lg border border-border bg-canvas/40 p-4 space-y-3">
                                                <div className="text-xs font-semibold uppercase tracking-wider text-ink-muted">
                                                    Organizer Bank Account
                                                </div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                                    <div>
                                                        <span className="block text-xs text-ink-muted">Bank Name</span>
                                                        <span className="font-medium text-ink">{event.offline_payment_bank_name}</span>
                                                    </div>
                                                    <div>
                                                        <span className="block text-xs text-ink-muted">Account Name</span>
                                                        <span className="font-medium text-ink">{event.offline_payment_account_name || 'N/A'}</span>
                                                    </div>
                                                    <div className="sm:col-span-2 flex items-center justify-between rounded-md border border-border bg-surface p-2.5">
                                                        <div>
                                                            <span className="block text-[11px] text-ink-muted">Account Number / IBAN</span>
                                                            <span className="font-mono font-semibold text-ink">{event.offline_payment_account_number}</span>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => copyToClipboard(event.offline_payment_account_number, 'bank')}
                                                            className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-xs text-ink hover:bg-surface-hover"
                                                        >
                                                            {copiedField === 'bank' ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
                                                            {copiedField === 'bank' ? 'Copied' : 'Copy'}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* MoMo Details Box */}
                                        {offlineMethod === 'offline_momo' && event.offline_payment_momo_number && (
                                            <div className="rounded-lg border border-border bg-canvas/40 p-4 space-y-3">
                                                <div className="text-xs font-semibold uppercase tracking-wider text-ink-muted">
                                                    Organizer Mobile Money Line
                                                </div>
                                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                                                    <div>
                                                        <span className="block text-xs text-ink-muted">Network</span>
                                                        <span className="font-medium text-ink">{event.offline_payment_momo_network || 'MoMo / Telecel'}</span>
                                                    </div>
                                                    <div>
                                                        <span className="block text-xs text-ink-muted">Recipient / Name</span>
                                                        <span className="font-medium text-ink">{event.offline_payment_account_name || event.name}</span>
                                                    </div>
                                                    <div className="sm:col-span-2 flex items-center justify-between rounded-md border border-border bg-surface p-2.5">
                                                        <div>
                                                            <span className="block text-[11px] text-ink-muted">MoMo Number / Merchant ID</span>
                                                            <span className="font-mono font-semibold text-ink">{event.offline_payment_momo_number}</span>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => copyToClipboard(event.offline_payment_momo_number, 'momo')}
                                                            className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-xs text-ink hover:bg-surface-hover"
                                                        >
                                                            {copiedField === 'momo' ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
                                                            {copiedField === 'momo' ? 'Copied' : 'Copy'}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {/* Custom Instructions */}
                                        {event.offline_payment_instructions && (
                                            <div className="rounded-lg border border-border/80 bg-canvas/30 p-3.5 text-xs text-ink leading-relaxed">
                                                <span className="font-semibold block mb-1">Instructions from Organizer:</span>
                                                <p className="whitespace-pre-line text-ink-muted">{event.offline_payment_instructions}</p>
                                            </div>
                                        )}

                                        {/* Proof Upload Form */}
                                        <form onSubmit={handleProofSubmit} className="space-y-4 pt-2">
                                            <div>
                                                <label className="block text-xs font-medium text-ink mb-1.5">
                                                    Upload Payment Slip / Screenshot / Receipt <span className="text-rose-500">*</span>
                                                </label>
                                                <div className="mt-1 flex justify-center rounded-lg border border-dashed border-border px-6 pt-5 pb-6 hover:border-border-strong transition-colors bg-canvas/20">
                                                    <div className="space-y-2 text-center">
                                                        <UploadCloud className="mx-auto h-8 w-8 text-ink-muted" />
                                                        <div className="flex text-xs text-ink-muted">
                                                            <label
                                                                htmlFor="proof-upload"
                                                                className="relative cursor-pointer rounded font-medium text-accent hover:underline focus-within:outline-none"
                                                            >
                                                                <span>Click to select file</span>
                                                                <input
                                                                    id="proof-upload"
                                                                    name="proof_file"
                                                                    type="file"
                                                                    accept="image/png,image/jpeg,image/webp,application/pdf"
                                                                    onChange={handleFileChange}
                                                                    className="sr-only"
                                                                />
                                                            </label>
                                                            <p className="pl-1">or drag and drop</p>
                                                        </div>
                                                        <p className="text-[11px] text-ink-muted">
                                                            PNG, JPG, WebP, or PDF up to 10MB
                                                        </p>
                                                        {proofData.proof_file && (
                                                            <div className="mt-2 inline-flex items-center gap-1.5 rounded bg-accent-soft px-2.5 py-1 text-xs font-medium text-accent">
                                                                <FileText className="h-3.5 w-3.5" />
                                                                {proofData.proof_file.name} ({(proofData.proof_file.size / 1024).toFixed(0)} KB)
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                                {proofErrors.proof_file && (
                                                    <p className="mt-1 text-xs text-rose-500">{proofErrors.proof_file}</p>
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-xs font-medium text-ink mb-1">
                                                    Transaction ID / Bank Reference / Memo (Optional)
                                                </label>
                                                <input
                                                    type="text"
                                                    value={proofData.offline_payment_reference}
                                                    onChange={(e) => setProofData('offline_payment_reference', e.target.value)}
                                                    placeholder="e.g. 1029384756 or MoMo Transaction ID"
                                                    className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-muted/50 focus:border-accent focus:outline-none"
                                                />
                                                {proofErrors.offline_payment_reference && (
                                                    <p className="mt-1 text-xs text-rose-500">{proofErrors.offline_payment_reference}</p>
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-xs font-medium text-ink mb-1">
                                                    Notes for Organizer (Optional)
                                                </label>
                                                <textarea
                                                    rows={2}
                                                    value={proofData.offline_payment_notes}
                                                    onChange={(e) => setProofData('offline_payment_notes', e.target.value)}
                                                    placeholder="Any additional details regarding your payment or institution name..."
                                                    className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-muted/50 focus:border-accent focus:outline-none"
                                                />
                                                {proofErrors.offline_payment_notes && (
                                                    <p className="mt-1 text-xs text-rose-500">{proofErrors.offline_payment_notes}</p>
                                                )}
                                            </div>

                                            <Button
                                                variant="primary"
                                                type="submit"
                                                disabled={proofProcessing || !proofData.proof_file}
                                                className="w-full justify-center py-2.5 text-sm"
                                            >
                                                {proofProcessing ? 'Uploading proof...' : 'Submit Payment Proof'}
                                            </Button>
                                        </form>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Order Summary & Registration Details Column */}
                    <div className="lg:col-span-5 space-y-6">
                        <div className="rounded-xl border border-border bg-surface p-6 shadow-sm">
                            <h3 className="text-sm font-semibold uppercase tracking-wider text-ink-muted">
                                Order Summary
                            </h3>

                            <div className="mt-4 border-b border-border pb-4">
                                <h4 className="font-semibold text-ink">{event.name}</h4>
                                <p className="text-xs text-ink-muted mt-1">
                                    {event.address || event.location_type}
                                </p>
                            </div>

                            <div className="mt-4 space-y-3 text-sm">
                                <div className="flex justify-between items-start">
                                    <div>
                                        <div className="font-medium text-ink">
                                            {registration.ticket_type ? registration.ticket_type.name : 'Registration Ticket'}
                                        </div>
                                        <div className="text-xs text-ink-muted">1 × Attendee Pass</div>
                                    </div>
                                    <div className="font-medium text-ink">
                                        {formatMoney(registration.amount, registration.currency)}
                                    </div>
                                </div>

                                {registration.ticket_type?.description && (
                                    <p className="text-xs text-ink-muted/80 italic">
                                        {registration.ticket_type.description}
                                    </p>
                                )}
                            </div>

                            <div className="mt-6 border-t border-border pt-4 space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span className="text-ink-muted">Subtotal</span>
                                    <span className="text-ink font-medium">
                                        {formatMoney(registration.amount, registration.currency)}
                                    </span>
                                </div>
                                <div className="flex justify-between text-base font-semibold pt-2 border-t border-border/60">
                                    <span className="text-ink">Total Due</span>
                                    <span className="text-accent font-bold">
                                        {formatMoney(registration.charged_amount, registration.currency)}
                                    </span>
                                </div>
                            </div>

                            <div className="mt-6 rounded-lg bg-canvas/50 p-3.5 text-xs space-y-1.5">
                                <div className="text-ink-muted font-medium">Attendee Details:</div>
                                <div className="font-medium text-ink">{registration.full_name}</div>
                                <div className="text-ink-muted">{registration.email}</div>
                                {registration.phone && <div className="text-ink-muted">{registration.phone}</div>}
                            </div>
                        </div>

                        {/* Safety Note */}
                        <div className="flex items-center gap-2 text-xs text-ink-muted px-2">
                            <ShieldCheck className="h-4 w-4 text-emerald-600 shrink-0" />
                            <span>All transactions are encrypted and audited through MiConvener ledger protocol.</span>
                        </div>
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
