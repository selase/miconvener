import { useState } from 'react';
import { useForm, usePage, router } from '@inertiajs/react';
import { 
    Plus, 
    Copy, 
    Webhook, 
    Radio, 
    CheckCircle2, 
    Clock, 
    RefreshCw, 
    Send, 
    Key, 
    Settings2, 
    Trash2, 
    ChevronDown, 
    ChevronRight, 
    Activity 
} from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Modal from '@/Components/Console/Modal';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { useToast } from '@/Components/Console/Toast';

export default function WebhooksIndex({ endpoints = [], availableEvents = {}, stats = {}, flash = {} }) {
    const showToast = useToast();
    const pageFlash = usePage().props.flash;
    const activeSecret = flash?.newSecret || pageFlash?.newSecret;

    // Modals state
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [editingEndpoint, setEditingEndpoint] = useState(null);
    const [rotatingEndpoint, setRotatingEndpoint] = useState(null);
    const [deletingEndpoint, setDeletingEndpoint] = useState(null);
    
    // Delivery Logs modal state
    const [inspectingEndpoint, setInspectingEndpoint] = useState(null);
    const [logs, setLogs] = useState([]);
    const [loadingLogs, setLoadingLogs] = useState(false);
    const [selectedLog, setSelectedLog] = useState(null);

    // Form for create/edit
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        description: '',
        url: '',
        events: ['*'],
        is_active: true,
    });

    const openCreateModal = () => {
        reset();
        setData({
            name: '',
            description: '',
            url: '',
            events: ['*'],
            is_active: true,
        });
        setEditingEndpoint(null);
        setIsCreateOpen(true);
    };

    const openEditModal = (ep) => {
        reset();
        setData({
            name: ep.name,
            description: ep.description || '',
            url: ep.url,
            events: Array.isArray(ep.events) && ep.events.length > 0 ? ep.events : ['*'],
            is_active: Boolean(ep.is_active),
        });
        setEditingEndpoint(ep);
        setIsCreateOpen(true);
    };

    const handleFormSubmit = (e) => {
        e.preventDefault();
        if (editingEndpoint) {
            put(route('tenant.settings.webhooks.update', { endpoint: editingEndpoint.id }), {
                onSuccess: () => {
                    setIsCreateOpen(false);
                    setEditingEndpoint(null);
                    reset();
                },
            });
        } else {
            post(route('tenant.settings.webhooks.store'), {
                onSuccess: () => {
                    setIsCreateOpen(false);
                    reset();
                },
            });
        }
    };

    const handleToggleEvent = (eventKey) => {
        if (eventKey === '*') {
            setData('events', ['*']);
            return;
        }

        let nextEvents = data.events.filter(e => e !== '*');
        if (nextEvents.includes(eventKey)) {
            nextEvents = nextEvents.filter(e => e !== eventKey);
        } else {
            nextEvents.push(eventKey);
        }

        if (nextEvents.length === 0) {
            nextEvents = ['*'];
        }

        setData('events', nextEvents);
    };

    const copyText = (text, message = 'Copied to clipboard') => {
        navigator.clipboard.writeText(text);
        showToast?.(message);
    };

    const triggerTestPing = (ep) => {
        router.post(route('tenant.settings.webhooks.test', { endpoint: ep.id }), {}, {
            preserveScroll: true,
        });
    };

    const openLogsModal = (ep) => {
        setInspectingEndpoint(ep);
        setSelectedLog(null);
        setLoadingLogs(true);
        fetch(route('tenant.settings.webhooks.calls', { endpoint: ep.id }))
            .then((res) => res.json())
            .then((paginated) => {
                setLogs(paginated.data || []);
                setLoadingLogs(false);
            })
            .catch(() => {
                setLogs([]);
                setLoadingLogs(false);
            });
    };

    const retryCall = (call) => {
        router.post(route('tenant.settings.webhooks.calls.retry', { call: call.id }), {}, {
            preserveScroll: true,
            onSuccess: () => {
                if (inspectingEndpoint) {
                    openLogsModal(inspectingEndpoint);
                }
            },
        });
    };

    const confirmRotate = () => {
        if (!rotatingEndpoint) return;
        router.post(route('tenant.settings.webhooks.rotate-secret', { endpoint: rotatingEndpoint.id }), {}, {
            onFinish: () => setRotatingEndpoint(null),
        });
    };

    const confirmDelete = () => {
        if (!deletingEndpoint) return;
        router.delete(route('tenant.settings.webhooks.destroy', { endpoint: deletingEndpoint.id }), {
            onFinish: () => setDeletingEndpoint(null),
        });
    };

    return (
        <ConsoleLayout>
            <PageHeader 
                title="Outgoing Webhooks" 
                actions={
                    <Button variant="primary" icon={Plus} onClick={openCreateModal}>
                        Add Endpoint
                    </Button>
                }
            />

            <div className="space-y-6 px-8 py-6">
                {/* Secret Key Alert when newly generated/rotated */}
                {activeSecret && (
                    <div className="rounded-xl border border-warning-fg/30 bg-warning-bg/40 p-5 shadow-sm">
                        <div className="flex items-start gap-3">
                            <div className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-warning-fg/10 text-warning-fg">
                                <Key className="h-5 w-5" />
                            </div>
                            <div className="flex-1">
                                <h3 className="text-sm font-semibold text-warning-fg">
                                    New Webhook Signing Secret Generated
                                </h3>
                                <p className="mt-1 text-xs text-ink-secondary">
                                    This secret is used to sign payloads with HMAC-SHA256 (in the <code className="rounded bg-surface px-1 py-0.5 text-ink">X-MiConvener-Signature</code> header). For security, this is the only time the plaintext secret will be displayed.
                                </p>
                                <div className="mt-3 flex items-center gap-2">
                                    <code className="num flex-1 truncate rounded-lg border border-warning-fg/30 bg-surface px-3 py-2 text-xs font-mono font-medium text-ink">
                                        {activeSecret}
                                    </code>
                                    <Button icon={Copy} onClick={() => copyText(activeSecret, 'Secret copied')}>
                                        Copy Secret
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Metrics Stats Row */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-xl border border-border bg-surface p-5">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium uppercase tracking-wider text-ink-muted">Configured Endpoints</span>
                            <Webhook className="h-4 w-4 text-accent" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-bold text-ink">{stats.total_endpoints ?? 0}</span>
                            <span className="text-xs text-ink-secondary">({stats.active_endpoints ?? 0} active)</span>
                        </div>
                    </div>

                    <div className="rounded-xl border border-border bg-surface p-5">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium uppercase tracking-wider text-ink-muted">Total Deliveries</span>
                            <Radio className="h-4 w-4 text-ink-secondary" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-bold text-ink">{stats.total_deliveries ?? 0}</span>
                            <span className="text-xs text-ink-muted">all time</span>
                        </div>
                    </div>

                    <div className="rounded-xl border border-border bg-surface p-5">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium uppercase tracking-wider text-ink-muted">Success Rate</span>
                            <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-bold text-ink">{stats.success_rate ?? 100}%</span>
                            <span className="text-xs text-ink-muted">({stats.failed_deliveries ?? 0} failed)</span>
                        </div>
                    </div>

                    <div className="rounded-xl border border-border bg-surface p-5">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-medium uppercase tracking-wider text-ink-muted">Average Latency</span>
                            <Clock className="h-4 w-4 text-ink-secondary" />
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="text-2xl font-bold text-ink">{stats.avg_duration_ms ?? 0} ms</span>
                            <span className="text-xs text-ink-muted">round trip</span>
                        </div>
                    </div>
                </div>

                {/* Endpoints Table */}
                <div className="rounded-xl border border-border bg-surface">
                    <div className="border-b border-border px-6 py-4">
                        <h2 className="text-base font-semibold text-ink">Active Subscriptions</h2>
                        <p className="mt-0.5 text-xs text-ink-muted">
                            HTTP POST webhooks triggered whenever registered domain events occur.
                        </p>
                    </div>

                    {endpoints.length === 0 ? (
                        <div className="p-8">
                            <TableEmpty
                                title="No Webhook Endpoints Configured"
                                description="Create an endpoint to receive instant notifications for ticket purchases, check-ins, voluntary tributes, and offline verification events."
                            />
                            <div className="mt-4 flex justify-center">
                                <Button variant="primary" icon={Plus} onClick={openCreateModal}>
                                    Add Your First Endpoint
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <Table>
                            <Thead>
                                <Tr>
                                    <Th>Endpoint / Destination</Th>
                                    <Th>Subscribed Events</Th>
                                    <Th>Status</Th>
                                    <Th>Recent Health</Th>
                                    <Th align="right">Actions</Th>
                                </Tr>
                            </Thead>
                            <tbody>
                                {endpoints.map((ep) => (
                                    <Tr key={ep.id}>
                                        <Td>
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-semibold text-ink">{ep.name}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => copyText(ep.url, 'URL copied')}
                                                        className="text-ink-muted hover:text-ink"
                                                        title="Copy URL"
                                                    >
                                                        <Copy className="h-3 w-3" />
                                                    </button>
                                                </div>
                                                <div className="text-xs font-mono text-ink-secondary truncate max-w-xs md:max-w-md">
                                                    {ep.url}
                                                </div>
                                                {ep.description && (
                                                    <div className="text-xs text-ink-muted italic">
                                                        {ep.description}
                                                    </div>
                                                )}
                                            </div>
                                        </Td>
                                        <Td>
                                            <div className="flex flex-wrap gap-1 max-w-xs">
                                                {Array.isArray(ep.events) && ep.events.includes('*') ? (
                                                    <span className="inline-flex items-center rounded-md bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent">
                                                        All Events (*)
                                                    </span>
                                                ) : (
                                                    (ep.events || []).map((ev) => (
                                                        <span
                                                            key={ev}
                                                            className="inline-flex items-center rounded-md border border-border bg-surface-muted px-2 py-0.5 text-[11px] font-mono text-ink-secondary"
                                                        >
                                                            {ev}
                                                        </span>
                                                    ))
                                                )}
                                            </div>
                                        </Td>
                                        <Td>
                                            {ep.is_active ? (
                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                                    Active
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 text-xs font-medium text-ink-muted">
                                                    <span className="h-1.5 w-1.5 rounded-full bg-ink-muted" />
                                                    Disabled
                                                </span>
                                            )}
                                        </Td>
                                        <Td>
                                            <div className="space-y-1.5">
                                                <div className="flex items-center gap-1">
                                                    {ep.recent_calls && ep.recent_calls.length > 0 ? (
                                                        ep.recent_calls.map((call) => (
                                                            <span
                                                                key={call.id}
                                                                title={`${call.event_name} (HTTP ${call.status}) - ${call.formatted_time}`}
                                                                className={`grid h-5 w-5 place-items-center rounded text-[10px] font-bold ${
                                                                    call.is_successful
                                                                        ? 'bg-emerald-500/15 text-emerald-600'
                                                                        : 'bg-rose-500/15 text-rose-600'
                                                                }`}
                                                            >
                                                                {call.is_successful ? '✓' : '✗'}
                                                            </span>
                                                        ))
                                                    ) : (
                                                        <span className="text-xs text-ink-muted">No calls yet</span>
                                                    )}
                                                </div>
                                                <div className="text-[11px] text-ink-muted">
                                                    {ep.calls_count} total {ep.calls_count === 1 ? 'call' : 'calls'}
                                                </div>
                                            </div>
                                        </Td>
                                        <Td align="right">
                                            <div className="flex items-center justify-end gap-1.5">
                                                <button
                                                    type="button"
                                                    onClick={() => triggerTestPing(ep)}
                                                    className="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-ink hover:bg-surface-hover"
                                                    title="Send a sample ping event to verify destination connectivity"
                                                >
                                                    <Send className="h-3.5 w-3.5 text-accent" />
                                                    Ping
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => openLogsModal(ep)}
                                                    className="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-ink hover:bg-surface-hover"
                                                    title="View delivery logs, responses, and payload inspector"
                                                >
                                                    <Activity className="h-3.5 w-3.5" />
                                                    Logs
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setRotatingEndpoint(ep)}
                                                    className="grid h-8 w-8 place-items-center rounded-md text-ink-muted hover:bg-surface-hover hover:text-ink"
                                                    title="Rotate Signing Secret"
                                                >
                                                    <Key className="h-3.5 w-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => openEditModal(ep)}
                                                    className="grid h-8 w-8 place-items-center rounded-md text-ink-muted hover:bg-surface-hover hover:text-ink"
                                                    title="Edit Endpoint"
                                                >
                                                    <Settings2 className="h-3.5 w-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeletingEndpoint(ep)}
                                                    className="grid h-8 w-8 place-items-center rounded-md text-ink-muted hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/20"
                                                    title="Delete Endpoint"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </button>
                                            </div>
                                        </Td>
                                    </Tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </div>
            </div>

            {/* Create / Edit Modal */}
            <Modal
                open={isCreateOpen}
                onClose={() => {
                    setIsCreateOpen(false);
                    setEditingEndpoint(null);
                }}
                title={editingEndpoint ? 'Edit Webhook Endpoint' : 'Register New Webhook Endpoint'}
                className="max-w-xl"
            >
                <form onSubmit={handleFormSubmit} className="space-y-4">
                    <div>
                        <label className="block text-xs font-medium text-ink mb-1">
                            Endpoint Label / Name *
                        </label>
                        <Input
                            type="text"
                            required
                            placeholder="e.g. Zapier CRM Integration, Internal ERP Sync"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            error={errors.name}
                        />
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-ink mb-1">
                            Destination Payload URL *
                        </label>
                        <Input
                            type="url"
                            required
                            placeholder="https://api.yourdomain.com/webhooks/miconvener"
                            value={data.url}
                            onChange={(e) => setData('url', e.target.value)}
                            error={errors.url}
                        />
                        <p className="mt-1 text-[11px] text-ink-muted">
                            Must be an active HTTP/HTTPS endpoint capable of responding with a 2xx status within 10 seconds.
                        </p>
                    </div>

                    <div>
                        <label className="block text-xs font-medium text-ink mb-1">
                            Description (Optional)
                        </label>
                        <Input
                            type="text"
                            placeholder="Optional notes regarding the receiver or purpose"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            error={errors.description}
                        />
                    </div>

                    <div>
                        <div className="flex items-center justify-between mb-2">
                            <label className="block text-xs font-medium text-ink">
                                Subscribed Event Topics *
                            </label>
                            <button
                                type="button"
                                onClick={() => handleToggleEvent('*')}
                                className="text-xs font-medium text-accent hover:underline"
                            >
                                {data.events.includes('*') ? 'Select Specific Events' : 'Subscribe to All (*)'}
                            </button>
                        </div>

                        {data.events.includes('*') ? (
                            <div className="rounded-lg border border-accent/30 bg-accent-soft p-3 text-xs text-accent">
                                Subscribed to <strong>all current and future lifecycle events</strong>.
                            </div>
                        ) : (
                            <div className="space-y-2 rounded-lg border border-border p-3 max-h-48 overflow-y-auto">
                                {Object.entries(availableEvents).map(([eventKey, label]) => {
                                    const checked = data.events.includes(eventKey);
                                    return (
                                        <label
                                            key={eventKey}
                                            className="flex items-start gap-2.5 cursor-pointer text-xs"
                                        >
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={() => handleToggleEvent(eventKey)}
                                                className="mt-0.5 rounded border-border text-accent focus:ring-accent"
                                            />
                                            <div>
                                                <div className="font-mono font-medium text-ink">{eventKey}</div>
                                                <div className="text-[11px] text-ink-muted">{label}</div>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                        )}
                        {errors.events && (
                            <p className="mt-1 text-xs text-rose-500">{errors.events}</p>
                        )}
                    </div>

                    <div className="pt-2">
                        <label className="flex items-center gap-2 cursor-pointer text-xs font-medium text-ink">
                            <input
                                type="checkbox"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-border text-accent focus:ring-accent"
                            />
                            Endpoint is active and listening for live events
                        </label>
                    </div>

                    <div className="mt-6 flex justify-end gap-3 pt-3 border-t border-border">
                        <Button
                            type="button"
                            onClick={() => {
                                setIsCreateOpen(false);
                                setEditingEndpoint(null);
                            }}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="primary" disabled={processing}>
                            {editingEndpoint ? 'Save Changes' : 'Create Endpoint'}
                        </Button>
                    </div>
                </form>
            </Modal>

            {/* Delivery Logs Inspection Modal */}
            <Modal
                open={Boolean(inspectingEndpoint)}
                onClose={() => setInspectingEndpoint(null)}
                title={`Delivery History: ${inspectingEndpoint?.name}`}
                className="max-w-3xl"
            >
                <div className="space-y-4">
                    <div className="flex items-center justify-between text-xs text-ink-muted">
                        <span className="font-mono truncate max-w-md">{inspectingEndpoint?.url}</span>
                        <button
                            type="button"
                            onClick={() => inspectingEndpoint && openLogsModal(inspectingEndpoint)}
                            className="inline-flex items-center gap-1 text-accent hover:underline"
                        >
                            <RefreshCw className={`h-3 w-3 ${loadingLogs ? 'animate-spin' : ''}`} />
                            Refresh
                        </button>
                    </div>

                    {loadingLogs ? (
                        <div className="py-12 text-center text-sm text-ink-muted">
                            Loading delivery logs...
                        </div>
                    ) : logs.length === 0 ? (
                        <div className="py-8 text-center text-sm text-ink-muted">
                            No recorded webhook deliveries for this endpoint yet.
                        </div>
                    ) : (
                        <div className="divide-y divide-border rounded-lg border border-border">
                            {logs.map((call) => {
                                const isExpanded = selectedLog?.id === call.id;
                                return (
                                    <div key={call.id} className="p-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-bold ${
                                                        call.is_successful
                                                            ? 'bg-emerald-500/15 text-emerald-600'
                                                            : 'bg-rose-500/15 text-rose-600'
                                                    }`}
                                                >
                                                    HTTP {call.status || 'ERR'}
                                                </span>
                                                <span className="font-mono text-xs font-semibold text-ink">
                                                    {call.event_name}
                                                </span>
                                                <span className="text-[11px] text-ink-muted">
                                                    ({call.duration_ms}ms)
                                                </span>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <span className="text-[11px] text-ink-muted">
                                                    {call.formatted_time}
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => retryCall(call)}
                                                    className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-[11px] font-medium text-ink hover:bg-surface-hover"
                                                    title="Replay this webhook payload"
                                                >
                                                    <RefreshCw className="h-3 w-3" />
                                                    Retry
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedLog(isExpanded ? null : call)}
                                                    className="grid h-6 w-6 place-items-center rounded text-ink-muted hover:bg-surface-hover hover:text-ink"
                                                >
                                                    {isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                                </button>
                                            </div>
                                        </div>

                                        {isExpanded && (
                                            <div className="mt-3 space-y-3 rounded-lg bg-surface-muted p-3 text-xs">
                                                <div>
                                                    <span className="font-semibold text-ink">Payload Sent:</span>
                                                    <pre className="mt-1 overflow-x-auto rounded bg-surface p-2 font-mono text-[11px] text-ink">
                                                        {JSON.stringify(call.payload, null, 2)}
                                                    </pre>
                                                </div>

                                                {call.response && (
                                                    <div>
                                                        <span className="font-semibold text-ink">Response Received:</span>
                                                        <pre className="mt-1 overflow-x-auto rounded bg-surface p-2 font-mono text-[11px] text-ink">
                                                            {call.response}
                                                        </pre>
                                                    </div>
                                                )}

                                                {call.exception && (
                                                    <div>
                                                        <span className="font-semibold text-rose-600">Error / Exception:</span>
                                                        <pre className="mt-1 overflow-x-auto rounded bg-rose-50 p-2 font-mono text-[11px] text-rose-700 dark:bg-rose-950/20 dark:text-rose-400">
                                                            {call.exception}
                                                        </pre>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <div className="mt-4 flex justify-end">
                        <Button onClick={() => setInspectingEndpoint(null)}>
                            Close
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Rotate Secret Confirm Modal */}
            <ConfirmModal
                open={Boolean(rotatingEndpoint)}
                onClose={() => setRotatingEndpoint(null)}
                onConfirm={confirmRotate}
                title="Rotate Signing Secret?"
                description={`Rotating the signing secret for "${rotatingEndpoint?.name}" will immediately invalidate the existing key. Any external system verifying payloads with the old key will fail until updated.`}
                confirmLabel="Rotate Secret"
                danger={true}
            />

            {/* Delete Endpoint Confirm Modal */}
            <ConfirmModal
                open={Boolean(deletingEndpoint)}
                onClose={() => setDeletingEndpoint(null)}
                onConfirm={confirmDelete}
                title="Delete Webhook Endpoint?"
                description={`Are you sure you want to delete "${deletingEndpoint?.name}"? All past delivery logs for this endpoint will also be permanently deleted.`}
                confirmLabel="Delete Endpoint"
                danger={true}
            />
        </ConsoleLayout>
    );
}
