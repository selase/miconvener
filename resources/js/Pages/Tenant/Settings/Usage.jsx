import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';

const numberFormat = new Intl.NumberFormat();

function barTone(percent) {
    if (percent >= 90) return 'bg-danger-fg';
    if (percent >= 70) return 'bg-warning-fg';
    return 'bg-accent';
}

function Meter({ label, used, limit }) {
    const percent = limit > 0 ? Math.min(Math.round((used / limit) * 100), 100) : 0;
    const remaining = Math.max(limit - used, 0);

    return (
        <div>
            <div className="flex items-center justify-between gap-4">
                <span className="text-sm font-medium text-ink">{label}</span>
                <span className="flex items-center gap-2">
                    {percent >= 90 && <StatusPill status="failed">Near limit</StatusPill>}
                    <span className="num text-sm text-ink">
                        {numberFormat.format(used)} / {numberFormat.format(limit)}
                    </span>
                </span>
            </div>
            <div
                className="mt-2 h-2 w-full rounded-full bg-surface-sunken"
                role="progressbar"
                aria-label={label}
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    className={`h-2 rounded-full ${barTone(percent)}`}
                    style={{ width: `${percent}%` }}
                />
            </div>
            <div className="mt-1.5 flex justify-between text-xs text-ink-secondary">
                <span className="num">{percent}% used</span>
                <span className="num">{numberFormat.format(remaining)} remaining</span>
            </div>
        </div>
    );
}

function Section({ title, action, children }) {
    return (
        <section className="rounded-lg border border-border p-5 sm:p-6">
            <div className="mb-5 flex items-center justify-between gap-4">
                <h2 className="text-base font-semibold text-ink">{title}</h2>
                {action}
            </div>
            {children}
        </section>
    );
}

export default function Usage({ planName, periodLabel, limits, seats, includedFeatures }) {
    const isEmpty = limits.length === 0 && seats === null && includedFeatures.length === 0;

    return (
        <ConsoleLayout>
            <PageHeader
                title="Plan usage"
                actions={
                    <Button href={route('tenant.pricing')} variant="primary">
                        Upgrade plan
                    </Button>
                }
            />

            <div className="max-w-3xl space-y-6 px-4 py-6 sm:px-8">
                <p className="text-sm text-ink-secondary">
                    {periodLabel} billing period ·{' '}
                    <span className="font-medium text-ink">{planName} plan</span>
                </p>

                {limits.length > 0 && (
                    <Section title="Plan limits">
                        <div className="space-y-6">
                            {limits.map((row) => (
                                <Meter
                                    key={row.slug}
                                    label={row.name}
                                    used={row.used}
                                    limit={row.limit}
                                />
                            ))}
                        </div>
                    </Section>
                )}

                {seats && (
                    <Section
                        title="Team seats"
                        action={
                            <Link
                                href={route('tenant.users.index')}
                                className="text-sm font-medium text-accent hover:underline"
                            >
                                Manage team
                            </Link>
                        }
                    >
                        <Meter label="Seats used" used={seats.used} limit={seats.limit} />
                    </Section>
                )}

                {includedFeatures.length > 0 && (
                    <Section title="Included features">
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {includedFeatures.map((feature) => (
                                <li key={feature.slug} className="flex gap-2.5">
                                    <Check
                                        className="mt-0.5 h-4 w-4 shrink-0 text-success-fg"
                                        strokeWidth={2}
                                    />
                                    <div>
                                        <div className="text-sm font-medium text-ink">
                                            {feature.name}
                                        </div>
                                        {feature.description && (
                                            <div className="text-xs text-ink-secondary">
                                                {feature.description}
                                            </div>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Section>
                )}

                {isEmpty && (
                    <div className="rounded-lg border border-border px-6 py-12 text-center">
                        <h2 className="text-base font-semibold text-ink">No usage data yet</h2>
                        <p className="mt-1 text-sm text-ink-secondary">
                            Usage appears here once your plan has limits to measure.
                        </p>
                        <Button className="mt-5" href={route('tenant.pricing')}>
                            View plans
                        </Button>
                    </div>
                )}
            </div>
        </ConsoleLayout>
    );
}
