import { router, usePage } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import ChecklistItem from '@/Components/ChecklistItem';

export default function Dashboard({ checklist, links }) {
    const { tenant } = usePage().props;

    const finishOnboarding = () => {
        router.post(links.finishOnboarding);
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Dashboard" />

            <div className="space-y-6 px-8 py-6">
                {!checklist.onboarding && (
                    <div className="rounded-lg border border-border bg-surface p-6">
                        <h2 className="text-lg font-bold text-ink">Getting Started</h2>
                        <p className="mt-1 text-sm text-ink-secondary">Complete these to set up your organization</p>

                        <div className="mt-5 flex flex-wrap gap-8">
                            <ChecklistItem
                                done={checklist.branding}
                                number={1}
                                href={links.branding}
                                label="Customize Branding"
                            />
                            <ChecklistItem
                                done={checklist.team}
                                number={2}
                                href={links.team}
                                label="Add Your Team"
                            />
                            <ChecklistItem
                                done={false}
                                number={3}
                                label="Mark as Setup Complete"
                                onClick={finishOnboarding}
                            />
                        </div>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
                    <div className="rounded-lg border border-border bg-surface p-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <span className="text-xs font-semibold uppercase text-ink-secondary">Welcome</span>
                                <div className="mt-1 text-2xl font-extrabold text-ink">{tenant.name}</div>
                            </div>
                            <span className="text-3xl">🏢</span>
                        </div>
                    </div>
                </div>
            </div>
        </ConsoleLayout>
    );
}
