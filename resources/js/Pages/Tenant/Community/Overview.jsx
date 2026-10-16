import JoinSettingsPanel from './JoinSettingsPanel';
import { useState } from 'react';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';
import { useToast } from '@/Components/Console/Toast';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import { formatDuesMoney, sendJson } from '@/lib/dues';

function Stat({ label, value, href, hint }) {
    return (
        <Link href={href} className="block border border-border p-5 hover:bg-surface-hover">
            <div className="text-[13px] text-ink-secondary">{label}</div>
            <div className="mt-1 text-2xl font-bold text-ink">{value}</div>
            {hint && <div className="mt-2 text-xs text-ink-secondary">{hint}</div>}
        </Link>
    );
}

function ReminderSettings({ settings, form, setForm, errors, setErrors }) {
    const [saving, setSaving] = useState(false);
    const showToast = useToast();
    const submit = async (event) => {
        event.preventDefault();
        setSaving(true);
        const result = await sendJson('PATCH', route('tenant.community.settings.update'), {
            reminders_enabled: form.reminders_enabled,
            reminder_offsets: form.reminder_offsets,
            frequent_digest: form.frequent_digest,
            sms_monthly_limit: form.sms_monthly_limit === '' ? null : form.sms_monthly_limit,
        });
        setSaving(false);
        setErrors(result.errors ?? {});
        showToast?.(result.ok ? 'Reminder settings saved.' : result.message);
    };
    return (
        <form onSubmit={submit} className="max-w-2xl space-y-5 px-4 py-6 sm:px-8">
            <p className="text-sm text-ink-secondary">
                Dues reminders include every open charge and a payment link. Email reminders are
                free. SMS uses your organisation’s credits when SMS is enabled in notification
                settings.
            </p>
            <Select
                label="Dues reminders"
                aria-label="Dues reminders"
                value={form.reminders_enabled ? 'on' : 'off'}
                onChange={(e) => setForm({ ...form, reminders_enabled: e.target.value === 'on' })}
                error={errors.reminders_enabled}
            >
                <option value="on">Enabled — automatic and manual reminders</option>
                <option value="off">Disabled</option>
            </Select>
            <div className="grid gap-4 sm:grid-cols-3">
                {[
                    ['before', 'Days before due'],
                    ['after_7', 'First reminder after (days)'],
                    ['after_30', 'Second reminder after (days)'],
                ].map(([key, label]) => (
                    <Input
                        key={key}
                        type="number"
                        min={1}
                        max={365}
                        label={label}
                        aria-label={label}
                        value={form.reminder_offsets[key]}
                        onChange={(e) =>
                            setForm({
                                ...form,
                                reminder_offsets: {
                                    ...form.reminder_offsets,
                                    [key]: e.target.value,
                                },
                            })
                        }
                        error={errors[`reminder_offsets.${key}`]}
                        required
                    />
                ))}
            </div>
            <p className="text-xs text-ink-secondary">
                Monthly, quarterly and yearly dues also receive a reminder on the due date. Each
                member receives at most one reminder per channel each local day.
            </p>
            <Select
                label="Daily and weekly dues digest"
                aria-label="Daily and weekly dues digest"
                value={form.frequent_digest}
                onChange={(e) => setForm({ ...form, frequent_digest: e.target.value })}
                error={errors.frequent_digest}
            >
                <option value="daily">Every day while charges are open</option>
                <option value="weekly">
                    Weekly on{' '}
                    {
                        [
                            'Sunday',
                            'Monday',
                            'Tuesday',
                            'Wednesday',
                            'Thursday',
                            'Friday',
                            'Saturday',
                        ][settings.week_starts_on]
                    }
                </option>
            </Select>
            <Input
                label="Monthly Community SMS limit"
                aria-label="Monthly Community SMS limit"
                type="number"
                min={0}
                value={form.sms_monthly_limit ?? ''}
                onChange={(e) => setForm({ ...form, sms_monthly_limit: e.target.value })}
                error={errors.sms_monthly_limit}
                hint="Leave blank for no spending limit; 0 disables SMS reminders. Existing credit balances still apply."
            />
            <Button type="submit" variant="primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save reminder settings'}
            </Button>
        </form>
    );
}

export default function Overview({
    counts,
    collectedThisMonth,
    reminderSettings,
    canReadFinance = false,
    recentMessages = null,
    joinSettings = null,
    membershipTypes = [],
    reviewCounts = null,
}) {
    const [tab, setTab] = useState('overview');
    const [settingsForm, setSettingsForm] = useState(reminderSettings);
    const [settingsErrors, setSettingsErrors] = useState({});
    const collected =
        collectedThisMonth.length === 0
            ? 'No online collections'
            : collectedThisMonth
                  .map((row) => formatDuesMoney(row.amount, row.currency))
                  .join(' · ');

    return (
        <ConsoleLayout>
            <PageHeader title="Community" />
            <div
                role="tablist"
                aria-label="Community"
                className="flex gap-3 border-b border-border px-4 py-3 sm:px-8"
            >
                {['overview', 'settings'].map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        id={`community-tab-${key}`}
                        aria-controls="community-tab-panel"
                        aria-selected={tab === key}
                        tabIndex={tab === key ? 0 : -1}
                        onKeyDown={(event) => {
                            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                                event.preventDefault();
                                const next =
                                    event.key === 'Home'
                                        ? 'overview'
                                        : event.key === 'End'
                                          ? 'settings'
                                          : key === 'overview'
                                            ? 'settings'
                                            : 'overview';
                                setTab(next);
                                document.getElementById(`community-tab-${next}`)?.focus();
                            }
                        }}
                        className={`px-3 py-2 text-sm font-medium ${tab === key ? 'border-b-2 border-ink text-ink' : 'text-ink-secondary'}`}
                        onClick={() => setTab(key)}
                    >
                        {key === 'overview' ? 'Overview' : 'Settings'}
                    </button>
                ))}
            </div>
            <div role="tabpanel" id="community-tab-panel" aria-labelledby={`community-tab-${tab}`}>
                {tab === 'settings' ? (
                    <>
                        <JoinSettingsPanel
                            settings={joinSettings}
                            membershipTypes={membershipTypes}
                        />
                        <ReminderSettings
                            settings={reminderSettings}
                            form={settingsForm}
                            setForm={setSettingsForm}
                            errors={settingsErrors}
                            setErrors={setSettingsErrors}
                        />
                    </>
                ) : (
                    <div className="grid gap-4 px-4 py-6 sm:grid-cols-2 sm:px-8 xl:grid-cols-3">
                        {reviewCounts &&
                            Object.entries({
                                joins: 'Membership requests',
                                claims: 'Access reviews',
                                reports: 'Wrong-number reports',
                            }).map(([key, label]) => (
                                <Stat
                                    key={key}
                                    label={label}
                                    value={reviewCounts[key]}
                                    href={`${route('tenant.community.people.index')}?tab=reviews&queue=${key}`}
                                    hint="Independent review required"
                                />
                            ))}
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
                        {canReadFinance && (
                            <Stat
                                label="Collected online this month"
                                value={collected}
                                hint="View collections, cash and current balances in Finance"
                                href={route('tenant.finance.index')}
                            />
                        )}
                        {recentMessages !== null && (
                            <section className="border border-border p-5 sm:col-span-2 xl:col-span-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <h2 className="font-semibold text-ink">Recent announcements</h2>
                                    <Link
                                        className="text-sm text-ink underline"
                                        href={route('tenant.community.messages.index')}
                                    >
                                        View messages
                                    </Link>
                                </div>
                                {recentMessages.length === 0 ? (
                                    <p className="mt-3 text-sm text-ink-secondary">
                                        No announcements yet. Write your first message to keep
                                        members informed.
                                    </p>
                                ) : (
                                    <ul className="mt-3 divide-y divide-border">
                                        {recentMessages.map((message) => (
                                            <li
                                                className="flex flex-wrap justify-between gap-2 py-2 text-sm"
                                                key={message.id}
                                            >
                                                <span className="break-words text-ink">
                                                    {message.subject}
                                                </span>
                                                <span className="text-ink-secondary">
                                                    {message.status}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        )}
                    </div>
                )}
            </div>
        </ConsoleLayout>
    );
}
