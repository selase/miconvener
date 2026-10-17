import { useState } from 'react';
import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import Button from '@/Components/Console/Button';
import { formatDuesMoney, sendJson } from '@/lib/dues';
export default function Fund({ organisation, fund, providers, accepting }) {
    const [form, setForm] = useState({
        amount: '',
        payer_name: '',
        payer_email: '',
        payer_phone: '',
        is_anonymous: false,
        provider: providers[0] ?? '',
        submission_key: crypto.randomUUID(),
    });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setMessage('');
        try {
            const result = await sendJson(
                'POST',
                route('public.giving.checkout', { slug: fund.slug }),
                { ...form, currency: fund.currency }
            );
            if (!result.ok) {
                setErrors(result.errors ?? {});
                setMessage(result.message ?? 'Please check the form and try again.');
            } else {
                window.location.assign(result.body.checkout_url);
            }
        } catch {
            setMessage(
                'We could not confirm the checkout outcome. Keep this page open and try again with the same details.'
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <PublicLayout>
            <Head title={`${fund.name} · ${organisation.name}`} />
            <main className="mx-auto max-w-5xl px-5 py-10">
                <a href={route('public.giving.index')} className="text-sm text-accent underline">
                    All contribution funds
                </a>
                <div className="mt-8 grid gap-10 lg:grid-cols-2">
                    <section>
                        <p className="text-sm uppercase tracking-widest text-accent">
                            {organisation.name}
                        </p>
                        <h1 className="mt-3 text-4xl font-semibold text-ink">{fund.name}</h1>
                        <p className="mt-6 whitespace-pre-line leading-relaxed text-ink-secondary">
                            {fund.description}
                        </p>
                        {fund.progress && (
                            <div className="mt-8 border-y border-border py-5">
                                <p className="text-lg font-semibold text-ink">
                                    {fund.progress.raised !== undefined
                                        ? formatDuesMoney(fund.progress.raised, fund.currency)
                                        : `${fund.progress.percent ?? 0}%`}
                                    {fund.target
                                        ? ` of ${formatDuesMoney(fund.target, fund.currency)}`
                                        : ''}
                                </p>
                                {fund.progress.percent !== null &&
                                    fund.progress.percent !== undefined && (
                                        <progress
                                            aria-label="Fund progress"
                                            max="100"
                                            value={Math.min(100, fund.progress.percent)}
                                            className="mt-3 w-full accent-blue-600"
                                        />
                                    )}
                            </div>
                        )}
                        <p className="mt-6 text-sm text-ink-secondary">
                            A voluntary contribution, with no dues or membership obligation. Your
                            name and contact details are never shown publicly.
                        </p>
                    </section>
                    <section className="border border-border bg-surface p-5 sm:p-7">
                        <h2 className="text-xl font-semibold text-ink">{fund.button_label}</h2>
                        {!accepting ? (
                            <p role="status" className="mt-5 text-ink-secondary">
                                This fund is not accepting new contributions.
                            </p>
                        ) : providers.length === 0 ? (
                            <p role="status" className="mt-5 text-ink-secondary">
                                Online contributions are not available yet. Please contact the
                                organisation to contribute in person.
                            </p>
                        ) : (
                            <form onSubmit={submit} className="mt-6 space-y-5">
                                <Input
                                    label={`Amount (${fund.currency})`}
                                    inputMode="decimal"
                                    value={form.amount}
                                    onChange={(e) => setForm({ ...form, amount: e.target.value })}
                                    error={errors.amount}
                                    hint={`Minimum ${formatDuesMoney(fund.minimum_amount, fund.currency)}`}
                                    required
                                />
                                {[
                                    ['payer_name', 'Name (optional)'],
                                    ['payer_email', 'Email for your receipt (optional)'],
                                    ['payer_phone', 'Phone (optional)'],
                                ].map(([key, label]) => (
                                    <Input
                                        key={key}
                                        label={label}
                                        type={key === 'payer_email' ? 'email' : 'text'}
                                        value={form[key]}
                                        onChange={(e) =>
                                            setForm({ ...form, [key]: e.target.value })
                                        }
                                        error={errors[key]}
                                    />
                                ))}
                                <label className="flex gap-3 text-sm text-ink">
                                    <input
                                        type="checkbox"
                                        checked={form.is_anonymous}
                                        onChange={(e) =>
                                            setForm({ ...form, is_anonymous: e.target.checked })
                                        }
                                    />
                                    Keep this contribution anonymous
                                </label>
                                <p className="text-xs text-ink-secondary">
                                    Your receipt remains private. An email address entered here does
                                    not verify your identity, join the community or subscribe you to
                                    announcements.
                                </p>
                                <Select
                                    label="Payment method"
                                    value={form.provider}
                                    onChange={(e) => setForm({ ...form, provider: e.target.value })}
                                    error={errors.provider}
                                >
                                    {providers.map((provider) => (
                                        <option key={provider} value={provider}>
                                            {provider === 'stripe'
                                                ? 'Card'
                                                : 'Card or mobile money'}
                                        </option>
                                    ))}
                                </Select>
                                {message && (
                                    <p role="alert" className="text-sm text-ink">
                                        {message}
                                    </p>
                                )}
                                <Button type="submit" variant="primary" disabled={busy}>
                                    {busy ? 'Opening secure payment…' : fund.button_label}
                                </Button>
                            </form>
                        )}
                    </section>
                </div>
            </main>
        </PublicLayout>
    );
}
