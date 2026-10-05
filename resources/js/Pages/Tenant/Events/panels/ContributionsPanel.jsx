import { useState, useMemo } from 'react';
import { useForm, router } from '@inertiajs/react';
import Button from '@/Components/Console/Button';
import Modal from '@/Components/Console/Modal';
import Input from '@/Components/Console/Input';
import StatusPill from '@/Components/Console/StatusPill';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import {
    Download,
    Settings,
    HeartHandshake,
    Eye,
    EyeOff,
    Search,
    MessageSquareQuote,
} from 'lucide-react';

function formatMoney(pesewas, currency = 'GHS') {
    if (pesewas === null || pesewas === undefined) return `${currency} 0.00`;
    return `${currency} ${(Number(pesewas) / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

export default function ContributionsPanel({ event, contributions = [], stats = {} }) {
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [settingsModalOpen, setSettingsModalOpen] = useState(false);
    const [togglingId, setTogglingId] = useState(null);

    // Settings form
    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        allow_contributions: Boolean(event.allow_contributions),
        contribution_title: event.contribution_title || event.lexicon?.contributions_title || 'Voluntary Contributions',
        contribution_description: event.contribution_description || '',
        contribution_presets: Array.isArray(event.contribution_presets) && event.contribution_presets.length > 0
            ? event.contribution_presets.map((p) => Math.round(p / 100)).join(', ')
            : '20, 50, 100, 200, 500',
        contribution_min_amount: event.contribution_min_amount_pesewas
            ? (event.contribution_min_amount_pesewas / 100).toString()
            : '1.00',
        contribution_goal_amount: event.contribution_goal_amount_pesewas
            ? (event.contribution_goal_amount_pesewas / 100).toString()
            : '',
        show_tribute_wall: event.show_tribute_wall !== false,
        show_contributor_amounts: Boolean(event.show_contributor_amounts),
    });

    const submitSettings = (e) => {
        e.preventDefault();

        const presetsArray = data.contribution_presets
            ? data.contribution_presets
                  .split(',')
                  .map((p) => Math.round(parseFloat(p.trim()) * 100))
                  .filter((p) => !isNaN(p) && p >= 100)
            : [];

        const minAmountPesewas = data.contribution_min_amount
            ? Math.round(parseFloat(data.contribution_min_amount) * 100)
            : 100;

        const goalAmountPesewas = data.contribution_goal_amount
            ? Math.round(parseFloat(data.contribution_goal_amount) * 100)
            : null;

        patch(
            route('tenant.events.contributions.settings', {
                event: event.slug || event.id,
            }),
            {
                preserveScroll: true,
                data: {
                    allow_contributions: data.allow_contributions,
                    contribution_title: data.contribution_title,
                    contribution_description: data.contribution_description,
                    contribution_presets: presetsArray,
                    contribution_min_amount_pesewas: minAmountPesewas,
                    contribution_goal_amount_pesewas: goalAmountPesewas,
                    show_tribute_wall: data.show_tribute_wall,
                    show_contributor_amounts: data.show_contributor_amounts,
                },
                onSuccess: () => {
                    setSettingsModalOpen(false);
                },
            }
        );
    };

    const toggleApproval = (contribution) => {
        setTogglingId(contribution.id);
        router.patch(
            route('tenant.events.contributions.toggle-approval', {
                event: event.slug || event.id,
                contribution: contribution.id,
            }),
            {},
            {
                preserveScroll: true,
                onFinish: () => setTogglingId(null),
            }
        );
    };

    const filteredContributions = useMemo(() => {
        return contributions.filter((c) => {
            if (statusFilter !== 'all' && c.status !== statusFilter) {
                return false;
            }

            if (!search) return true;
            const query = search.toLowerCase();
            return (
                (c.contributor_name && c.contributor_name.toLowerCase().includes(query)) ||
                (c.contributor_email && c.contributor_email.toLowerCase().includes(query)) ||
                (c.contributor_phone && c.contributor_phone.toLowerCase().includes(query)) ||
                (c.payment_reference && c.payment_reference.toLowerCase().includes(query)) ||
                (c.tribute_message && c.tribute_message.toLowerCase().includes(query))
            );
        });
    }, [contributions, search, statusFilter]);

    const exportUrl = route('tenant.events.contributions.export', {
        event: event.slug || event.id,
    });

    return (
        <div className="space-y-6">
            {/* Header & Actions */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="text-xl font-bold tracking-tight text-ink flex items-center gap-2">
                        <HeartHandshake className="h-6 w-6 text-accent" />
                        {event.lexicon?.contributions_panel_title || 'Voluntary Contributions & Support'}
                    </h2>
                    <p className="mt-1 text-sm text-ink-secondary">
                        {event.lexicon?.contributions_panel_subtitle || 'Collect voluntary contributions with live message moderation.'}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <Button
                        variant="default"
                        icon={Settings}
                        onClick={() => setSettingsModalOpen(true)}
                    >
                        Settings
                    </Button>
                    <a
                        href={exportUrl}
                        className="inline-flex h-control items-center gap-2 rounded-md border border-border bg-surface px-4 text-sm font-medium text-ink transition-colors duration-120 ease-out hover:border-border-strong hover:bg-surface-hover"
                    >
                        <Download className="h-4 w-4" strokeWidth={1.75} />
                        Export CSV
                    </a>
                </div>
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div className="rounded-lg border border-border bg-surface p-4">
                    <div className="text-xs font-medium text-ink-secondary">Total Raised</div>
                    <div className="mt-1 text-2xl font-bold text-ink">
                        {formatMoney(stats?.total_raised ?? 0, event.currency)}
                    </div>
                    <div className="mt-1 text-xs text-ink-secondary">Gross contributions received</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-4">
                    <div className="text-xs font-medium text-ink-secondary">Net Settlement</div>
                    <div className="mt-1 text-2xl font-bold text-success-fg">
                        {formatMoney(stats?.net_payout ?? 0, event.currency)}
                    </div>
                    <div className="mt-1 text-xs text-ink-secondary">After gateway & platform fees</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-4">
                    <div className="text-xs font-medium text-ink-secondary">Confirmed Donors</div>
                    <div className="mt-1 text-2xl font-bold text-ink">
                        {stats?.contributors_count ?? 0}
                    </div>
                    <div className="mt-1 text-xs text-ink-secondary">Completed transactions</div>
                </div>
                <div className="rounded-lg border border-border bg-surface p-4">
                    <div className="text-xs font-medium text-ink-secondary">Pending Checkouts</div>
                    <div className="mt-1 text-2xl font-bold text-ink">
                        {stats?.pending_count ?? 0}
                    </div>
                    <div className="mt-1 text-xs text-ink-secondary">Awaiting completion</div>
                </div>
            </div>

            {/* Search & Filter Toolbar */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="relative flex-1 max-w-sm">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-secondary" />
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search donors, phone, tributes..."
                        className="h-9 w-full rounded-md border border-border bg-surface pl-9 pr-3 text-sm text-ink placeholder:text-ink-secondary focus:border-accent focus:outline-none"
                    />
                </div>
                <div className="flex items-center gap-2">
                    {['all', 'completed', 'pending_payment', 'failed'].map((st) => (
                        <button
                            key={st}
                            type="button"
                            onClick={() => setStatusFilter(st)}
                            className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                statusFilter === st
                                    ? 'bg-ink text-surface'
                                    : 'bg-surface-sunken text-ink-secondary hover:text-ink'
                            }`}
                        >
                            {st === 'all'
                                ? 'All'
                                : st === 'completed'
                                ? 'Completed'
                                : st === 'pending_payment'
                                ? 'Pending'
                                : 'Failed'}
                        </button>
                    ))}
                </div>
            </div>

            {/* Contributions Table */}
            <Table>
                <Thead>
                    <Th>Contributor</Th>
                    <Th>Contact</Th>
                    <Th>Amount</Th>
                    <Th>Status</Th>
                    <Th>Tribute Message</Th>
                    <Th>Date</Th>
                    <Th align="right">Moderation</Th>
                </Thead>
                <tbody>
                    {filteredContributions.length > 0 ? (
                        filteredContributions.map((c) => {
                            const isCompleted = c.status === 'completed';
                            const pillStatus = isCompleted ? 'success' : c.status === 'pending_payment' ? 'pending' : 'failed';
                            const statusLabel = isCompleted ? 'Completed' : c.status === 'pending_payment' ? 'Pending' : c.status;

                            return (
                                <Tr key={c.id}>
                                    <Td>
                                        <div className="font-medium text-ink">
                                            {c.contributor_name}
                                        </div>
                                        {c.is_anonymous && (
                                            <span className="inline-block mt-0.5 rounded bg-surface-sunken px-1.5 py-0.5 text-[10px] text-ink-secondary font-medium">
                                                Anonymous on Wall
                                            </span>
                                        )}
                                    </Td>
                                    <Td muted>
                                        <div className="text-xs">
                                            {c.contributor_phone || c.contributor_email || '—'}
                                        </div>
                                        <div className="text-[11px] text-ink-secondary mono font-mono mt-0.5">
                                            {c.payment_reference}
                                        </div>
                                    </Td>
                                    <Td>
                                        <div className="font-semibold text-ink">
                                            {formatMoney(c.amount, c.currency)}
                                        </div>
                                        {isCompleted && c.net_amount > 0 && (
                                            <div className="text-[11px] text-success-fg">
                                                Net: {formatMoney(c.net_amount, c.currency)}
                                            </div>
                                        )}
                                    </Td>
                                    <Td>
                                        <StatusPill status={pillStatus}>
                                            {statusLabel}
                                        </StatusPill>
                                    </Td>
                                    <Td>
                                        {c.tribute_message ? (
                                            <div className="max-w-xs text-xs text-ink italic flex items-start gap-1.5">
                                                <MessageSquareQuote className="h-3.5 w-3.5 text-accent shrink-0 mt-0.5" />
                                                <span className="line-clamp-2">{c.tribute_message}</span>
                                            </div>
                                        ) : (
                                            <span className="text-xs text-ink-secondary">—</span>
                                        )}
                                    </Td>
                                    <Td muted>
                                        <span className="text-xs">
                                            {c.paid_at
                                                ? new Date(c.paid_at).toLocaleDateString()
                                                : new Date(c.created_at).toLocaleDateString()}
                                        </span>
                                    </Td>
                                    <Td align="right">
                                        {c.tribute_message ? (
                                            <button
                                                type="button"
                                                disabled={togglingId === c.id}
                                                onClick={() => toggleApproval(c)}
                                                className={`inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors ${
                                                    c.is_approved
                                                        ? 'bg-surface-sunken text-ink hover:bg-surface-hover'
                                                        : 'bg-warning-bg text-warning-fg hover:opacity-80'
                                                }`}
                                                title={c.is_approved ? 'Hide from Tribute Wall' : 'Show on Tribute Wall'}
                                            >
                                                {c.is_approved ? (
                                                    <>
                                                        <Eye className="h-3.5 w-3.5" /> Visible
                                                    </>
                                                ) : (
                                                    <>
                                                        <EyeOff className="h-3.5 w-3.5" /> Hidden
                                                    </>
                                                )}
                                            </button>
                                        ) : (
                                            <span className="text-xs text-ink-secondary">—</span>
                                        )}
                                    </Td>
                                </Tr>
                            );
                        })
                    ) : (
                        <tr>
                            <td colSpan={7}>
                                <TableEmpty
                                    title="No contributions found"
                                    description={
                                        search || statusFilter !== 'all'
                                            ? 'No records matching the filter criteria.'
                                            : 'Enable voluntary contributions and share your event page to start receiving support.'
                                    }
                                />
                            </td>
                        </tr>
                    )}
                </tbody>
            </Table>

            {/* Settings Modal */}
            <Modal
                open={settingsModalOpen}
                onClose={() => setSettingsModalOpen(false)}
                title="Contribution & Tribute Settings"
                className="max-w-lg"
            >
                <form onSubmit={submitSettings} className="space-y-4">
                    <label className="flex items-center gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            checked={data.allow_contributions}
                            onChange={(e) => setData('allow_contributions', e.target.checked)}
                            className="h-4 w-4 rounded border-border text-accent focus:ring-accent"
                        />
                        <span className="text-sm font-medium text-ink">
                            Enable voluntary contributions for this event
                        </span>
                    </label>
                    {event.contribution_fee_note && (
                        <p className="-mt-2 text-xs text-ink-secondary">{event.contribution_fee_note}</p>
                    )}

                    <div>
                        <label className="block text-xs font-medium text-ink-secondary mb-1">
                            Section Title
                        </label>
                        <Input
                            type="text"
                            value={data.contribution_title}
                            onChange={(e) => setData('contribution_title', e.target.value)}
                            placeholder={event.lexicon?.contributions_title ? `e.g. ${event.lexicon.contributions_title}` : 'e.g. Voluntary Contributions'}
                        />
                        {errors.contribution_title && (
                            <p className="mt-1 text-xs text-danger-fg">{errors.contribution_title}</p>
                        )}
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-ink-secondary mb-1">
                            Description / Callout Note
                        </label>
                        <textarea
                            rows={3}
                            value={data.contribution_description}
                            onChange={(e) => setData('contribution_description', e.target.value)}
                            placeholder={event.lexicon?.contributions_subtitle ? `e.g. ${event.lexicon.contributions_subtitle}` : 'Describe what contributions will be used for...'}
                            className="w-full rounded-md border border-border bg-surface p-2.5 text-sm text-ink placeholder:text-ink-secondary focus:border-accent focus:outline-none"
                        />
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-ink-secondary mb-1">
                                Minimum Amount ({event.currency})
                            </label>
                            <Input
                                type="number"
                                step="0.5"
                                min="1"
                                value={data.contribution_min_amount}
                                onChange={(e) => setData('contribution_min_amount', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-ink-secondary mb-1">
                                Goal Amount ({event.currency}, optional)
                            </label>
                            <Input
                                type="number"
                                step="1"
                                placeholder="e.g. 10000"
                                value={data.contribution_goal_amount}
                                onChange={(e) => setData('contribution_goal_amount', e.target.value)}
                            />
                        </div>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-ink-secondary mb-1">
                            Amount Preset Options ({event.currency}, comma-separated)
                        </label>
                        <Input
                            type="text"
                            value={data.contribution_presets}
                            onChange={(e) => setData('contribution_presets', e.target.value)}
                            placeholder="20, 50, 100, 200, 500"
                        />
                        <p className="mt-1 text-[11px] text-ink-secondary">
                            Presets allow supporters on mobile money to tap and pay quickly.
                        </p>
                    </div>

                    <div className="space-y-2 pt-2 border-t border-border">
                        <label className="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={data.show_tribute_wall}
                                onChange={(e) => setData('show_tribute_wall', e.target.checked)}
                                className="h-4 w-4 rounded border-border text-accent focus:ring-accent"
                            />
                            <span className="text-sm font-medium text-ink">
                                Display public Tribute Wall on event page
                            </span>
                        </label>

                        <label className="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={data.show_contributor_amounts}
                                onChange={(e) => setData('show_contributor_amounts', e.target.checked)}
                                className="h-4 w-4 rounded border-border text-accent focus:ring-accent"
                            />
                            <span className="text-sm font-medium text-ink">
                                Display contribution amounts publicly alongside tributes
                            </span>
                        </label>
                    </div>

                    <div className="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <Button
                            type="button"
                            variant="default"
                            onClick={() => setSettingsModalOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="primary"
                            disabled={processing}
                        >
                            {processing ? 'Saving...' : 'Save Settings'}
                        </Button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}
