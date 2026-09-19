import { useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';

export default function Account({ account, setup }) {
    const setupForm = useForm({});
    const confirmForm = useForm({ code: '' });
    const disableForm = useForm({ password: '' });

    const confirm = (event) => {
        event.preventDefault();
        confirmForm.post(route('tenant.account.two-factor.confirm'));
    };

    const disable = (event) => {
        event.preventDefault();
        disableForm.post(route('tenant.account.two-factor.disable'), {
            onSuccess: () => disableForm.reset(),
        });
    };

    return (
        <ConsoleLayout>
            <PageHeader title="My account" />

            <div className="max-w-2xl space-y-6 px-8 py-6">
                <section className="rounded-lg border border-border bg-surface p-6">
                    <h2 className="text-base font-semibold text-ink">Profile</h2>
                    <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-sm text-ink-secondary">Name</dt>
                            <dd className="mt-1 text-sm font-medium text-ink">{account.name}</dd>
                        </div>
                        <div>
                            <dt className="text-sm text-ink-secondary">Email</dt>
                            <dd className="mt-1 text-sm font-medium text-ink">{account.email}</dd>
                        </div>
                    </dl>
                </section>

                <section className="rounded-lg border border-border bg-surface p-6">
                    <div className="flex items-start gap-3">
                        <ShieldCheck
                            className="mt-0.5 h-5 w-5 text-ink-secondary"
                            aria-hidden="true"
                        />
                        <div>
                            <h2 className="text-base font-semibold text-ink">
                                Two-factor authentication
                            </h2>
                            <p className="mt-1 text-sm text-ink-secondary">
                                Use an authenticator app to protect your account when signing in.
                            </p>
                        </div>
                    </div>

                    {account.two_factor_required && (
                        <p className="mt-5 rounded-lg border border-warning-fg/30 bg-warning-bg px-4 py-3 text-sm text-warning-fg">
                            Your organization requires two-factor authentication.
                        </p>
                    )}

                    {account.two_factor_enabled ? (
                        <div className="mt-6 space-y-5">
                            <p className="text-sm font-medium text-success-fg">
                                Enabled
                                {account.two_factor_confirmed_at
                                    ? ` on ${account.two_factor_confirmed_at}`
                                    : ''}
                            </p>
                            {!account.two_factor_required && (
                                <form
                                    onSubmit={disable}
                                    className="space-y-3 border-t border-border pt-5"
                                >
                                    <p className="text-sm text-ink-secondary">
                                        Enter your password to disable two-factor authentication.
                                    </p>
                                    <Input
                                        label="Password"
                                        type="password"
                                        value={disableForm.data.password}
                                        onChange={(event) =>
                                            disableForm.setData('password', event.target.value)
                                        }
                                        error={disableForm.errors.password}
                                    />
                                    <Button type="submit" disabled={disableForm.processing}>
                                        Disable two-factor authentication
                                    </Button>
                                </form>
                            )}
                        </div>
                    ) : setup ? (
                        <div className="mt-6 space-y-5">
                            <p className="text-sm text-ink-secondary">
                                Scan this code in your authenticator app, then enter the six-digit
                                code it shows.
                            </p>
                            <img
                                src={setup.qr_code}
                                alt="Authenticator setup QR code"
                                className="h-48 w-48 rounded border border-border bg-white p-2"
                            />
                            <p className="text-sm text-ink-secondary">
                                Manual setup key:{' '}
                                <code className="select-all font-mono text-ink">
                                    {setup.secret}
                                </code>
                            </p>
                            <form onSubmit={confirm} className="space-y-3">
                                <Input
                                    label="Six-digit code"
                                    type="text"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={confirmForm.data.code}
                                    onChange={(event) =>
                                        confirmForm.setData('code', event.target.value)
                                    }
                                    error={confirmForm.errors.code}
                                />
                                <Button type="submit" disabled={confirmForm.processing}>
                                    Confirm and enable
                                </Button>
                            </form>
                        </div>
                    ) : (
                        <div className="mt-6">
                            <p className="mb-4 text-sm font-medium text-ink-secondary">
                                Status: Disabled
                            </p>
                            <Button
                                type="button"
                                disabled={setupForm.processing}
                                onClick={() =>
                                    setupForm.post(route('tenant.account.two-factor.setup'))
                                }
                            >
                                Set up two-factor authentication
                            </Button>
                        </div>
                    )}
                </section>

                <section className="rounded-lg border border-border bg-surface p-6">
                    <h2 className="text-base font-semibold text-ink">Email delivery</h2>
                    <p className="mt-2 text-sm text-ink-secondary">
                        MiConvener has no optional personal email subscriptions at this time.
                        Billing receipts and renewal notices are transactional and are sent to the
                        payer and your organization email.
                    </p>
                </section>
            </div>
        </ConsoleLayout>
    );
}
