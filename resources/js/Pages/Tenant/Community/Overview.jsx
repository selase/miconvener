import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import { formatDuesMoney } from '@/lib/dues';

function Stat({ label, value, href, hint }) {
    return (
        <Link href={href} className="block border border-border p-5 hover:bg-surface-hover">
            <div className="text-[13px] text-ink-secondary">{label}</div>
            <div className="mt-1 text-2xl font-bold text-ink">{value}</div>
            {hint && <div className="mt-2 text-xs text-ink-secondary">{hint}</div>}
        </Link>
    );
}

export default function Overview({ counts, collectedThisMonth }) {
    const collected =
        collectedThisMonth.length === 0
            ? formatDuesMoney(0, 'GHS')
            : collectedThisMonth
                  .map((row) => formatDuesMoney(row.amount, row.currency))
                  .join(' · ');

    return (
        <ConsoleLayout>
            <PageHeader title="Community" />
            <div className="grid gap-4 px-4 py-6 sm:grid-cols-2 sm:px-8 xl:grid-cols-3">
                <Stat
                    label="Members"
                    value={counts.members}
                    hint={`${counts.activeMembers} active`}
                    href={route('tenant.community.people.index')}
                />
                <Stat
                    label="Open dues"
                    value={counts.openCharges}
                    hint={`${counts.overdueCharges} overdue`}
                    href={route('tenant.community.money.dues.index')}
                />
                <Stat
                    label="Collected online this month"
                    value={collected}
                    hint="Ready to pay out from Finance > Payouts"
                    href={route('tenant.finance.payouts.index')}
                />
            </div>
        </ConsoleLayout>
    );
}
