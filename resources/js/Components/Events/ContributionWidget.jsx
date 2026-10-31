import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Heart, Sparkles, Shield, MessageCircle, Send } from 'lucide-react';

const inputClass =
    'w-full rounded-lg border border-border bg-surface px-3.5 py-2.5 text-sm text-ink placeholder:text-ink-tertiary focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20';

/**
 * A free message for the wall. Posts to the goodwill route, so no payment is
 * involved and the message waits for the organiser's approval.
 */
function GoodwillMessageForm({ event }) {
    const [sent, setSent] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        contributor_name: '',
        tribute_message: '',
        is_anonymous: false,
        website: '',
    });

    if (!event.allow_goodwill_messages) {
        return null;
    }

    const submit = (e) => {
        e.preventDefault();
        post(route('public.events.messages.store', { event: event.slug }), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setSent(true);
            },
        });
    };

    return (
        <div className="rounded-3xl border border-border bg-surface p-6 shadow-sm sm:p-8">
            <div className="mx-auto max-w-2xl">
                <div className="inline-flex items-center gap-2 rounded-full bg-accent/10 px-3.5 py-1.5 text-xs font-semibold text-accent">
                    <Heart className="h-3.5 w-3.5 fill-current" />
                    <span>{event.lexicon?.contributions_tab_label || 'Messages'}</span>
                </div>
                <h2 className="mt-4 text-2xl font-bold tracking-tight text-ink sm:text-3xl">
                    Leave a message
                </h2>
                <p className="mt-2 text-sm leading-relaxed text-ink-secondary">
                    {event.lexicon?.wall_subtitle}
                </p>

                {sent ? (
                    <div className="mt-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-5 text-sm text-ink">
                        <p className="font-semibold">Thank you.</p>
                        <p className="mt-1 text-ink-secondary">
                            Your message will appear on the wall once it has been approved.
                        </p>
                        <button
                            type="button"
                            onClick={() => setSent(false)}
                            className="mt-3 text-xs font-medium text-accent hover:underline"
                        >
                            Write another message
                        </button>
                    </div>
                ) : (
                    <form onSubmit={submit} className="mt-6 space-y-4">
                        <div>
                            <label
                                className="mb-1.5 block text-xs font-medium text-ink"
                                htmlFor="goodwill-name"
                            >
                                Your name
                            </label>
                            <input
                                id="goodwill-name"
                                type="text"
                                value={data.contributor_name}
                                onChange={(e) => setData('contributor_name', e.target.value)}
                                maxLength={255}
                                className={inputClass}
                            />
                            {errors.contributor_name && (
                                <p className="mt-1 text-xs text-red-600">
                                    {errors.contributor_name}
                                </p>
                            )}
                        </div>
                        <div>
                            <label
                                className="mb-1.5 block text-xs font-medium text-ink"
                                htmlFor="goodwill-message"
                            >
                                {event.lexicon?.message_field_label || 'Your message'}
                            </label>
                            <textarea
                                id="goodwill-message"
                                rows={5}
                                value={data.tribute_message}
                                onChange={(e) => setData('tribute_message', e.target.value)}
                                maxLength={1000}
                                placeholder={event.lexicon?.message_placeholder}
                                className={inputClass}
                            />
                            <div className="mt-1 flex justify-between text-xs">
                                <span className="text-red-600">{errors.tribute_message}</span>
                                <span className="text-ink-tertiary">
                                    {data.tribute_message.length}/1000
                                </span>
                            </div>
                        </div>
                        <input
                            type="text"
                            name="website"
                            value={data.website}
                            onChange={(e) => setData('website', e.target.value)}
                            tabIndex={-1}
                            autoComplete="off"
                            aria-hidden="true"
                            className="absolute -left-[9999px] h-0 w-0 opacity-0"
                        />
                        <label className="flex items-center gap-2 text-xs text-ink-secondary">
                            <input
                                type="checkbox"
                                checked={data.is_anonymous}
                                onChange={(e) => setData('is_anonymous', e.target.checked)}
                            />
                            Show my message as Anonymous
                        </label>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 rounded-lg bg-accent px-5 py-2.5 text-sm font-semibold text-white hover:bg-accent/90 disabled:opacity-60"
                        >
                            <Send className="h-4 w-4" />
                            {processing ? 'Sending…' : 'Send message'}
                        </button>
                    </form>
                )}
            </div>
        </div>
    );
}

function TributeWall({ event }) {
    const tributes = event.tributes || [];

    if (!event.show_tribute_wall) {
        return null;
    }

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between border-b border-border pb-4">
                <div className="flex items-center gap-2.5">
                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-accent/10 text-accent">
                        <MessageCircle className="h-4 w-4" />
                    </div>
                    <div>
                        <h3 className="text-lg font-bold text-ink">
                            {event.lexicon?.wall_title || 'Community Message Wall'}
                        </h3>
                        <p className="text-xs text-ink-secondary">
                            {event.lexicon?.wall_subtitle ||
                                'Words of encouragement and solidarity from attendees and well-wishers'}
                        </p>
                    </div>
                </div>
                <span className="text-xs font-semibold text-ink-secondary bg-surface-alt px-3 py-1 rounded-full border border-border">
                    {tributes.length} {tributes.length === 1 ? 'Message' : 'Messages'}
                </span>
            </div>

            {tributes.length > 0 ? (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {tributes.map((item) => (
                        <div
                            key={item.id}
                            className="flex flex-col justify-between rounded-2xl border border-border bg-surface p-5 shadow-2xs hover:border-border-strong transition-all"
                        >
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2.5 min-w-0">
                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-xs font-bold text-ink">
                                            {item.contributor_name.charAt(0).toUpperCase()}
                                        </div>
                                        <span className="truncate text-xs font-semibold text-ink">
                                            {item.contributor_name}
                                        </span>
                                    </div>
                                    {item.amount && (
                                        <span className="shrink-0 text-[11px] font-mono font-semibold text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded-md">
                                            {event.currency || 'GHS'}{' '}
                                            {(item.amount / 100).toFixed(2)}
                                        </span>
                                    )}
                                </div>

                                <p className="text-xs leading-relaxed text-ink/90 italic whitespace-pre-line">
                                    "{item.tribute_message}"
                                </p>
                            </div>

                            <div className="mt-4 pt-3 border-t border-border/50 text-[10px] text-ink-secondary">
                                {item.created_at}
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-border bg-surface-alt/30 p-10 text-center">
                    <Sparkles className="mx-auto h-8 w-8 text-ink-secondary/40 mb-2" />
                    <p className="text-xs font-semibold text-ink">No messages shared yet</p>
                    <p className="text-xs text-ink-secondary mt-1 max-w-sm mx-auto">
                        Be the first to leave a message.
                    </p>
                </div>
            )}
        </div>
    );
}

function GiftWidget({ event }) {
    const presets =
        event.contribution_presets && event.contribution_presets.length > 0
            ? event.contribution_presets
            : [5000, 10000, 20000, 50000]; // 50, 100, 200, 500 GHS in pesewas

    const minAmountPesewas = event.contribution_min_amount_pesewas || 100;
    const minAmountGhs = minAmountPesewas / 100;
    const defaultAmountGhs = presets[0] ? presets[0] / 100 : 50;

    const [selectedPreset, setSelectedPreset] = useState(defaultAmountGhs);
    const [customAmount, setCustomAmount] = useState(defaultAmountGhs.toString());
    const [contributorName, setContributorName] = useState('');
    const [contributorPhone, setContributorPhone] = useState('');
    const [contributorEmail, setContributorEmail] = useState('');
    const [tributeMessage, setTributeMessage] = useState('');
    const [isAnonymous, setIsAnonymous] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errorMessage, setErrorMessage] = useState('');
    const providers = event.contribution_providers || [];
    const [provider, setProvider] = useState(providers[0] || 'paystack');

    const goalPesewas = event.contribution_goal_amount_pesewas;
    const totalPesewas = event.contributions_total_pesewas || 0;
    const contributorsCount = event.contributions_count || 0;
    const goalPercentage =
        goalPesewas && goalPesewas > 0
            ? Math.min(100, Math.round((totalPesewas / goalPesewas) * 100))
            : null;

    const handlePresetClick = (amountGhs) => {
        setSelectedPreset(amountGhs);
        setCustomAmount(amountGhs.toString());
        setErrorMessage('');
    };

    const handleCustomAmountChange = (e) => {
        const val = e.target.value;
        setCustomAmount(val);
        const numeric = parseFloat(val);
        if (numeric && presets.includes(numeric * 100)) {
            setSelectedPreset(numeric);
        } else {
            setSelectedPreset(null);
        }
        setErrorMessage('');
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        const numericAmount = parseFloat(customAmount);

        if (!numericAmount || numericAmount < minAmountGhs) {
            setErrorMessage(
                `Please enter an amount of at least ${event.currency || 'GHS'} ${minAmountGhs.toFixed(2)}.`
            );
            return;
        }

        if (!contributorName.trim()) {
            setErrorMessage('Please enter your name.');
            return;
        }

        setIsSubmitting(true);
        setErrorMessage('');

        try {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = route('public.events.contribute', { event: event.slug });

            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');
            if (csrfToken) {
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;
                form.appendChild(csrfInput);
            }

            const appendField = (name, val) => {
                if (val !== undefined && val !== null) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = val;
                    form.appendChild(input);
                }
            };

            appendField('amount', numericAmount);
            appendField('contributor_name', contributorName.trim());
            appendField('contributor_phone', contributorPhone.trim());
            appendField('contributor_email', contributorEmail.trim());
            appendField('tribute_message', tributeMessage.trim());
            appendField('is_anonymous', isAnonymous ? '1' : '0');
            appendField('provider', provider);

            document.body.appendChild(form);
            form.submit();
        } catch (err) {
            setIsSubmitting(false);
            setErrorMessage('An unexpected error occurred. Please try again.');
        }
    };

    return (
        <div className="space-y-12">
            {/* Top Campaign Card */}
            <div className="overflow-hidden rounded-3xl border border-border bg-surface p-6 sm:p-8 shadow-sm">
                <div className="grid gap-8 lg:grid-cols-[1.2fr_1fr]">
                    {/* Left: Campaign Story & Progress */}
                    <div className="space-y-6">
                        <div className="inline-flex items-center gap-2 rounded-full bg-accent/10 px-3.5 py-1.5 text-xs font-semibold text-accent">
                            <Heart className="h-3.5 w-3.5 fill-current" />
                            <span>
                                {event.contribution_title || 'Voluntary Contributions & Tributes'}
                            </span>
                        </div>

                        <div>
                            <h2 className="text-2xl font-bold tracking-tight text-ink sm:text-3xl">
                                {event.contribution_title || 'Voluntary Contributions'}
                            </h2>
                            {event.contribution_description ? (
                                <p className="mt-3 text-sm leading-relaxed text-ink-secondary whitespace-pre-line">
                                    {event.contribution_description}
                                </p>
                            ) : (
                                <p className="mt-3 text-sm leading-relaxed text-ink-secondary">
                                    Your voluntary contribution and thoughtful message support this
                                    gathering. Mobile Money (MTN, Telecel, AT) and bank cards are
                                    welcomed.
                                </p>
                            )}
                        </div>

                        {/* Progress or Stats */}
                        <div className="rounded-2xl border border-border/80 bg-surface-alt/40 p-5 space-y-4">
                            <div className="flex items-baseline justify-between">
                                <div>
                                    <span className="text-xs font-medium uppercase tracking-wider text-ink-secondary">
                                        Total Received
                                    </span>
                                    <div className="mt-1 text-2xl font-extrabold text-ink sm:text-3xl">
                                        {event.currency || 'GHS'}{' '}
                                        {(totalPesewas / 100).toLocaleString(undefined, {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2,
                                        })}
                                    </div>
                                </div>
                                <div className="text-right">
                                    <span className="text-xs font-medium uppercase tracking-wider text-ink-secondary">
                                        Contributors
                                    </span>
                                    <div className="mt-1 text-2xl font-extrabold text-ink sm:text-3xl">
                                        {contributorsCount}
                                    </div>
                                </div>
                            </div>

                            {goalPercentage !== null && (
                                <div className="space-y-1.5 pt-1">
                                    <div className="flex justify-between text-xs text-ink-secondary">
                                        <span>Progress toward goal</span>
                                        <span className="font-semibold text-ink">
                                            {goalPercentage}% of {event.currency || 'GHS'}{' '}
                                            {((goalPesewas || 0) / 100).toLocaleString()}
                                        </span>
                                    </div>
                                    <div className="h-2.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                        <div
                                            className="h-full rounded-full bg-accent transition-all duration-500 ease-out"
                                            style={{ width: `${goalPercentage}%` }}
                                        />
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="flex items-center gap-3 text-xs text-ink-secondary pt-1">
                            <Shield className="h-4 w-4 text-emerald-600 shrink-0" />
                            <span>
                                Secured via Paystack · Instant MoMo & Card receipts issued directly
                            </span>
                        </div>
                    </div>

                    {/* Right: Contribution Form */}
                    <div className="rounded-2xl border border-border bg-surface-sunken/40 p-6 sm:p-7 shadow-xs">
                        <form onSubmit={handleSubmit} className="space-y-5">
                            <div>
                                <label className="block text-xs font-semibold text-ink uppercase tracking-wider mb-2">
                                    Select Amount ({event.currency || 'GHS'})
                                </label>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                                    {presets.map((presetPesewas) => {
                                        const amountGhs = presetPesewas / 100;
                                        const isSelected = selectedPreset === amountGhs;
                                        return (
                                            <button
                                                key={presetPesewas}
                                                type="button"
                                                onClick={() => handlePresetClick(amountGhs)}
                                                className={`py-2.5 px-3 rounded-xl text-xs font-bold transition-all cursor-pointer border ${
                                                    isSelected
                                                        ? 'bg-accent text-white border-accent shadow-xs'
                                                        : 'bg-surface text-ink border-border hover:border-accent/40 hover:bg-surface-alt'
                                                }`}
                                            >
                                                {event.currency || 'GHS'} {amountGhs}
                                            </button>
                                        );
                                    })}
                                </div>

                                <div className="relative">
                                    <span className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-xs font-semibold text-ink-secondary">
                                        {event.currency || 'GHS'}
                                    </span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min={minAmountGhs}
                                        value={customAmount}
                                        onChange={handleCustomAmountChange}
                                        placeholder={`Other amount (min ${minAmountGhs.toFixed(2)})`}
                                        className="w-full rounded-xl border border-border bg-surface pl-14 pr-4 py-2.5 text-sm font-semibold text-ink placeholder:text-ink-secondary/50 focus:border-accent focus:outline-none"
                                        required
                                    />
                                </div>
                            </div>

                            <div className="space-y-3 pt-2">
                                <div>
                                    <label className="block text-xs font-medium text-ink mb-1">
                                        Your Full Name <span className="text-rose-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        value={contributorName}
                                        onChange={(e) => setContributorName(e.target.value)}
                                        placeholder="e.g. Kwame Mensah"
                                        className="w-full rounded-xl border border-border bg-surface px-3.5 py-2 text-xs text-ink placeholder:text-ink-secondary/50 focus:border-accent focus:outline-none"
                                        required
                                    />
                                </div>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-medium text-ink mb-1">
                                            Phone / MoMo Number
                                        </label>
                                        <input
                                            type="tel"
                                            value={contributorPhone}
                                            onChange={(e) => setContributorPhone(e.target.value)}
                                            placeholder="024 123 4567"
                                            className="w-full rounded-xl border border-border bg-surface px-3.5 py-2 text-xs text-ink placeholder:text-ink-secondary/50 focus:border-accent focus:outline-none"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-xs font-medium text-ink mb-1">
                                            Email Address
                                        </label>
                                        <input
                                            type="email"
                                            value={contributorEmail}
                                            onChange={(e) => setContributorEmail(e.target.value)}
                                            placeholder="you@example.com"
                                            className="w-full rounded-xl border border-border bg-surface px-3.5 py-2 text-xs text-ink placeholder:text-ink-secondary/50 focus:border-accent focus:outline-none"
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-medium text-ink mb-1">
                                        {event.lexicon?.message_field_label ||
                                            'Message / Note (Optional)'}
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={tributeMessage}
                                        onChange={(e) => setTributeMessage(e.target.value)}
                                        placeholder={
                                            event.lexicon?.message_placeholder ||
                                            'Share a thought, note, or word of encouragement...'
                                        }
                                        className="w-full rounded-xl border border-border bg-surface px-3.5 py-2 text-xs text-ink placeholder:text-ink-secondary/50 focus:border-accent focus:outline-none"
                                    />
                                </div>

                                <label className="flex items-center gap-2.5 pt-1 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={isAnonymous}
                                        onChange={(e) => setIsAnonymous(e.target.checked)}
                                        className="h-4 w-4 rounded border-border text-accent focus:ring-accent"
                                    />
                                    <span className="text-xs text-ink-secondary select-none">
                                        Keep my name anonymous on the public message wall
                                    </span>
                                </label>
                            </div>

                            {providers.length > 1 && (
                                <div
                                    className="flex gap-2"
                                    role="radiogroup"
                                    aria-label="Payment method"
                                >
                                    {providers.map((option) => (
                                        <button
                                            key={option}
                                            type="button"
                                            role="radio"
                                            aria-checked={provider === option}
                                            onClick={() => setProvider(option)}
                                            className={`flex-1 rounded-xl border px-3 py-2 text-xs font-medium transition-colors ${
                                                provider === option
                                                    ? 'border-accent bg-accent/10 text-accent'
                                                    : 'border-border bg-surface text-ink-secondary hover:bg-surface-alt'
                                            }`}
                                        >
                                            {option === 'stripe'
                                                ? 'International card (Stripe)'
                                                : 'Mobile Money & local cards'}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {errorMessage && (
                                <div className="rounded-xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900 px-3.5 py-2.5 text-xs text-rose-700 dark:text-rose-300">
                                    {errorMessage}
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-accent px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-accent/90 disabled:opacity-50 transition-all cursor-pointer"
                            >
                                <Send className="h-4 w-4" />
                                <span>
                                    {isSubmitting
                                        ? 'Connecting to Payment…'
                                        : `${event.lexicon?.contributions_cta_label || 'Contribute'} ${event.currency || 'GHS'} ${parseFloat(customAmount || 0).toFixed(2)}`}
                                </span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <GoodwillMessageForm event={event} />

            <TributeWall event={event} />
        </div>
    );
}

export default function ContributionWidget({ event }) {
    if (!event.allow_contributions) {
        return (
            <div className="space-y-12">
                <GoodwillMessageForm event={event} />
                <TributeWall event={event} />
            </div>
        );
    }

    return <GiftWidget event={event} />;
}
