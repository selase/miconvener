import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import {
    Users,
    Vote,
    QrCode,
    MessageSquare,
    Mail,
    Plus,
    CheckCircle2,
    Shield,
    Sparkles,
    Calendar,
    ArrowUpRight,
    Loader2,
} from 'lucide-react';

export default function Addons({
    catalog = {},
    activeAddons = [],
    summary = {},
    events = [],
    currency = 'GHS',
}) {
    const [submittingKey, setSubmittingKey] = useState(null);
    const [selectedEventId, setSelectedEventId] = useState(events[0]?.id || '');
    const [quantities, setQuantities] = useState({});

    const catalogList = Object.values(catalog);

    const handleQuantityChange = (key, val) => {
        const parsed = Math.max(1, Math.min(50, parseInt(val, 10) || 1));
        setQuantities((prev) => ({ ...prev, [key]: parsed }));
    };

    const handleCheckout = (addonKey) => {
        setSubmittingKey(addonKey);
        const item = catalog[addonKey];
        const payload = {
            addon_key: addonKey,
            multiplier: quantities[addonKey] || 1,
        };

        if (item?.billing_interval === 'event_pass' && selectedEventId) {
            payload.event_id = selectedEventId;
        }

        router.post(route('billing.addons.checkout'), payload, {
            onFinish: () => setSubmittingKey(null),
        });
    };

    const handleCancel = (addonId, addonName) => {
        if (
            !confirm(
                `Are you sure you want to cancel ${addonName}? It will remain active until the end of your billing cycle.`
            )
        ) {
            return;
        }
        router.post(route('billing.addons.cancel', { addon: addonId }));
    };

    const formatPrice = (pesewas) => {
        return (pesewas / 100).toFixed(2);
    };

    return (
        <ConsoleLayout>
            <Head title="Modular Add-Ons & Capacity" />

            <PageHeader
                title="Modular Add-Ons"
                actions={
                    <div className="flex items-center gap-2">
                        <Button href={route('tenant.pricing')} variant="default">
                            View Core Plans
                        </Button>
                        <Button href={route('billing.index')} variant="default">
                            Billing Overview
                        </Button>
                    </div>
                }
            />

            <div className="px-4 py-6 sm:px-8 space-y-8">
                {/* Section 1: Capacity Overview */}
                <div>
                    <h2 className="text-base font-semibold text-ink mb-4">Capacity Overview</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        {/* Team Seats */}
                        <div className="rounded-xl border border-border bg-surface p-5 shadow-xs flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                    Team Seats
                                </span>
                                <div className="h-8 w-8 rounded-lg bg-blue-50 dark:bg-blue-950/40 text-blue-600 flex items-center justify-center">
                                    <Users className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="text-2xl font-bold text-ink">
                                    {summary.team_users_count ?? 0}
                                    <span className="text-sm font-normal text-ink-secondary ml-1">
                                        /{' '}
                                        {summary.total_seats_limit !== null &&
                                        summary.total_seats_limit !== undefined
                                            ? summary.total_seats_limit
                                            : 'Unlimited'}
                                    </span>
                                </div>
                                <p className="text-xs text-ink-secondary mt-1">
                                    {summary.purchased_extra_seats
                                        ? `${summary.purchased_extra_seats} add-on seat(s) active`
                                        : 'Base tier quota only'}
                                </p>
                            </div>
                        </div>

                        {/* Live Polling */}
                        <div className="rounded-xl border border-border bg-surface p-5 shadow-xs flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                    Live Polling & Q&A
                                </span>
                                <div className="h-8 w-8 rounded-lg bg-purple-50 dark:bg-purple-950/40 text-purple-600 flex items-center justify-center">
                                    <Vote className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="text-xl font-bold text-ink flex items-center gap-2">
                                    {summary.live_polling_enabled ? (
                                        <span className="inline-flex items-center text-emerald-600 text-sm font-semibold">
                                            <CheckCircle2 className="h-4 w-4 mr-1 text-emerald-500" />{' '}
                                            Active
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center text-amber-600 text-sm font-semibold">
                                            Locked
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-ink-secondary mt-1">
                                    {summary.live_polling_enabled
                                        ? 'Enabled for current workspace events'
                                        : 'Activate monthly or with single event passes'}
                                </p>
                            </div>
                        </div>

                        {/* Scanner / Usher Passes */}
                        <div className="rounded-xl border border-border bg-surface p-5 shadow-xs flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                    Scanner Passes
                                </span>
                                <div className="h-8 w-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 flex items-center justify-center">
                                    <QrCode className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="text-2xl font-bold text-ink">
                                    {summary.usher_passes_count ?? 0}
                                    <span className="text-sm font-normal text-ink-secondary ml-1">
                                        passes
                                    </span>
                                </div>
                                <p className="text-xs text-ink-secondary mt-1">
                                    Event-day check-in & gate crew passes
                                </p>
                            </div>
                        </div>

                        {/* SMS & Email Balance */}
                        <div className="rounded-xl border border-border bg-surface p-5 shadow-xs flex flex-col justify-between">
                            <div className="flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-ink-secondary">
                                    SMS / Email Balance
                                </span>
                                <div className="h-8 w-8 rounded-lg bg-amber-50 dark:bg-amber-950/40 text-amber-600 flex items-center justify-center">
                                    <MessageSquare className="h-4 w-4" />
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="flex items-center justify-between text-ink text-sm">
                                    <span>SMS:</span>
                                    <span className="font-bold">
                                        {(summary.sms_balance ?? 0).toLocaleString()}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-ink text-sm mt-1">
                                    <span>Email:</span>
                                    <span className="font-bold">
                                        {(summary.email_balance ?? 0).toLocaleString()}
                                    </span>
                                </div>
                                <p className="text-xs text-ink-secondary mt-2">
                                    Prepaid broadcast credits
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Section 2: Add-On Catalog */}
                <div>
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
                        <div>
                            <h2 className="text-base font-semibold text-ink">Add-On Catalog</h2>
                            <p className="text-xs text-ink-secondary">
                                Select individual add-ons to boost capacity instantly. Billed
                                securely via Paystack.
                            </p>
                        </div>
                        {events.length > 0 && (
                            <div className="flex items-center gap-2">
                                <label
                                    htmlFor="event-select"
                                    className="text-xs font-medium text-ink-secondary whitespace-nowrap"
                                >
                                    Target Event for Passes:
                                </label>
                                <select
                                    id="event-select"
                                    value={selectedEventId}
                                    onChange={(e) => setSelectedEventId(e.target.value)}
                                    className="text-xs rounded-md border-border bg-surface px-2.5 py-1.5 text-ink shadow-xs"
                                >
                                    {events.map((evt) => (
                                        <option key={evt.id} value={evt.id}>
                                            {evt.name} ({evt.date})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        {catalogList.map((item) => {
                            const isSubmitting = submittingKey === item.key;
                            const isEventPass = item.billing_interval === 'event_pass';
                            const isMonthly = item.billing_interval === 'monthly';
                            const isOneOff = item.billing_interval === 'one_off';

                            return (
                                <div
                                    key={item.key}
                                    className="rounded-xl border border-border bg-surface p-6 shadow-xs flex flex-col justify-between hover:border-gray-300 dark:hover:border-gray-700 transition"
                                >
                                    <div>
                                        <div className="flex items-start justify-between gap-2">
                                            <h3 className="font-bold text-ink text-base">
                                                {item.name}
                                            </h3>
                                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 uppercase">
                                                {isMonthly
                                                    ? 'Monthly'
                                                    : isEventPass
                                                      ? 'Event Pass'
                                                      : 'Prepaid'}
                                            </span>
                                        </div>

                                        <p className="mt-2 text-xs text-ink-secondary leading-relaxed">
                                            {item.description}
                                        </p>

                                        <div className="mt-4 flex items-baseline gap-1">
                                            <span className="text-2xl font-extrabold text-ink">
                                                {currency} {formatPrice(item.unit_price)}
                                            </span>
                                            <span className="text-xs text-ink-secondary">
                                                {isMonthly ? '/mo' : isEventPass ? '/event' : ''}
                                            </span>
                                        </div>
                                    </div>

                                    <div className="mt-6 pt-4 border-t border-border flex items-center gap-3">
                                        {item.available &&
                                            (item.category === 'team' ||
                                                item.category === 'operations') && (
                                                <div className="flex items-center gap-1.5">
                                                    <label className="text-xs text-ink-secondary">
                                                        Qty:
                                                    </label>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="50"
                                                        value={quantities[item.key] || 1}
                                                        onChange={(e) =>
                                                            handleQuantityChange(
                                                                item.key,
                                                                e.target.value
                                                            )
                                                        }
                                                        className="w-14 rounded-md border-border bg-surface px-2 py-1 text-xs text-ink shadow-xs text-center"
                                                    />
                                                </div>
                                            )}

                                        {item.available ? (
                                            <Button
                                                variant="default"
                                                className="w-full flex justify-center items-center gap-1.5 text-xs"
                                                disabled={isSubmitting}
                                                onClick={() => handleCheckout(item.key)}
                                            >
                                                {isSubmitting ? (
                                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                                ) : (
                                                    <Plus className="h-3.5 w-3.5" />
                                                )}
                                                {isSubmitting
                                                    ? 'Redirecting...'
                                                    : 'Add to Workspace'}
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="default"
                                                className="w-full flex justify-center items-center text-xs"
                                                disabled
                                            >
                                                Coming soon
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Section 3: Active Subscriptions & Packs */}
                <div>
                    <h2 className="text-base font-semibold text-ink mb-4">
                        Active Workspace Add-Ons
                    </h2>
                    <div className="rounded-xl border border-border bg-surface overflow-hidden shadow-xs">
                        <Table>
                            <Thead>
                                <Tr>
                                    <Th>Add-On Item</Th>
                                    <Th>Quantity</Th>
                                    <Th>Rate / Price</Th>
                                    <Th>Billing Interval</Th>
                                    <Th>Status</Th>
                                    <Th>Event / Period</Th>
                                    <Th align="right">Action</Th>
                                </Tr>
                            </Thead>
                            <tbody>
                                {activeAddons.length === 0 ? (
                                    <tr>
                                        <td colSpan={7}>
                                            <TableEmpty
                                                title="No active modular add-ons yet"
                                                description="Pick from the catalog above to add seats, polling, or message packs."
                                            />
                                        </td>
                                    </tr>
                                ) : (
                                    activeAddons.map((addon) => (
                                        <Tr key={addon.id}>
                                            <Td>
                                                <span className="font-medium text-ink">
                                                    {addon.name}
                                                </span>
                                            </Td>
                                            <Td>{addon.quantity}</Td>
                                            <Td>{addon.total_price}</Td>
                                            <Td>
                                                <span className="capitalize">
                                                    {addon.billing_interval.replace('_', ' ')}
                                                </span>
                                            </Td>
                                            <Td>
                                                <StatusPill status={addon.status} />
                                            </Td>
                                            <Td muted>
                                                {addon.event_name ? (
                                                    <span>Event: {addon.event_name}</span>
                                                ) : addon.period_end ? (
                                                    <span>
                                                        Renews / Expires: {addon.period_end}
                                                    </span>
                                                ) : (
                                                    <span>One-off credit</span>
                                                )}
                                            </Td>
                                            <Td align="right">
                                                {addon.can_cancel && (
                                                    <Button
                                                        variant="default"
                                                        className="text-xs text-rose-600 hover:text-rose-700"
                                                        onClick={() =>
                                                            handleCancel(addon.id, addon.name)
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                )}
                                            </Td>
                                        </Tr>
                                    ))
                                )}
                            </tbody>
                        </Table>
                    </div>
                </div>
            </div>
        </ConsoleLayout>
    );
}
