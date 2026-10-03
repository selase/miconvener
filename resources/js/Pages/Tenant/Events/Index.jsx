import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Plus, Pencil, Trash2, RotateCcw, Clock } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import StatusPill from '@/Components/Console/StatusPill';
import EventFormModal from './EventFormModal';
import DeleteEventModal from './DeleteEventModal';

const STATUS_VARIANT = {
    draft: 'neutral',
    published: 'success',
    cancelled: 'failed',
};

function formatDate(iso) {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatMoney(amount, currency) {
    return amount > 0 ? `${currency} ${(amount / 100).toFixed(2)}` : 'Free';
}

export default function Index({ events, stats, recentlyDeletedEvents = [] }) {
    const [modal, setModal] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [forcePurging, setForcePurging] = useState(null);

    const handleRestore = (delEvent) => {
        router.post(route('tenant.events.restore', { event: delEvent.id }), {}, {
            preserveScroll: true,
        });
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title="Events"
                actions={
                    <Button icon={Plus} onClick={() => setModal({ mode: 'create' })}>
                        New event
                    </Button>
                }
            />

            <div className="px-8 py-6">
                <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">Events this year</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">
                            {stats.events_this_year}
                        </div>
                    </div>
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">Running now</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">
                            {stats.running_now}
                        </div>
                    </div>
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">People registered</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">
                            {stats.total_registered.toLocaleString()}
                        </div>
                    </div>
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">Collected this year</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">
                            {formatMoney(stats.collected_this_year, 'GHS')}
                        </div>
                    </div>
                </div>

                {recentlyDeletedEvents?.length > 0 && (
                    <div className="mb-6 rounded-xl border border-amber-300 bg-amber-50/70 p-5">
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Clock className="h-5 w-5 text-amber-700" />
                                <h3 className="font-semibold text-amber-950">
                                    Recently Deleted Events (6-Hour Recovery Window Active)
                                </h3>
                            </div>
                            <span className="text-xs font-medium text-amber-800">
                                {recentlyDeletedEvents.length} event{recentlyDeletedEvents.length > 1 ? 's' : ''} in recovery
                            </span>
                        </div>
                        <div className="space-y-2">
                            {recentlyDeletedEvents.map((delEvent) => (
                                <div
                                    key={delEvent.id}
                                    className="flex flex-col justify-between gap-3 rounded-lg border border-amber-200 bg-surface px-4 py-3 sm:flex-row sm:items-center"
                                >
                                    <div>
                                        <p className="font-medium text-ink">{delEvent.name}</p>
                                        <div className="mt-0.5 flex items-center gap-2 text-xs text-ink-secondary">
                                            <span>Deleted {formatDate(delEvent.deleted_at)}</span>
                                            <span>·</span>
                                            <span className="inline-flex items-center gap-1 font-semibold text-amber-800">
                                                <Clock className="h-3.5 w-3.5" />
                                                {delEvent.remaining_human}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => handleRestore(delEvent)}
                                            className="inline-flex h-control items-center gap-1.5 rounded-md border border-emerald-600 bg-emerald-600 px-3 text-xs font-semibold text-white transition-colors hover:bg-emerald-700"
                                        >
                                            <RotateCcw className="h-3.5 w-3.5" />
                                            Restore Event
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setForcePurging(delEvent)}
                                            className="inline-flex h-control items-center gap-1.5 rounded-md border border-border bg-surface px-3 text-xs font-medium text-ink-secondary transition-colors hover:border-red-300 hover:text-red-600"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                            Purge Now
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {events.length > 0 ? (
                    <Table>
                        <Thead>
                            <Th>Name</Th>
                            <Th>When</Th>
                            <Th>Status</Th>
                            <Th>Price</Th>
                            <Th>Registrations</Th>
                            <Th />
                        </Thead>
                        <tbody>
                            {events.map((event) => (
                                <Tr key={event.id}>
                                    <Td>
                                        <Link
                                            href={route('tenant.events.show', { event: event.id })}
                                            className="font-medium text-ink hover:underline"
                                        >
                                            {event.name}
                                        </Link>
                                    </Td>
                                    <Td muted>{formatDate(event.starts_at)}</Td>
                                    <Td>
                                        <StatusPill status={STATUS_VARIANT[event.status]}>
                                            {event.status}
                                        </StatusPill>
                                    </Td>
                                    <Td muted>{formatMoney(event.ticket_price, event.currency)}</Td>
                                    <Td muted>
                                        {event.registrations_count}
                                        {event.capacity ? ` / ${event.capacity}` : ''}
                                    </Td>
                                    <Td align="right">
                                        <div className="flex justify-end gap-3">
                                            <button
                                                onClick={() => setModal({ mode: 'edit', event })}
                                                title="Edit event"
                                                className="text-ink-secondary hover:text-accent"
                                            >
                                                <Pencil className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                            <button
                                                onClick={() => setDeleting(event)}
                                                title="Delete event"
                                                className="text-ink-secondary hover:text-danger-fg"
                                            >
                                                <Trash2 className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                        </div>
                                    </Td>
                                </Tr>
                            ))}
                        </tbody>
                    </Table>
                ) : (
                    <Table>
                        <Thead>
                            <Th>Name</Th>
                            <Th>When</Th>
                            <Th>Status</Th>
                            <Th>Price</Th>
                            <Th>Registrations</Th>
                            <Th />
                        </Thead>
                        <tbody>
                            <tr>
                                <td colSpan={6}>
                                    <TableEmpty
                                        title="No events yet"
                                        description="Create your first event to start collecting registrations."
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </Table>
                )}
            </div>

            {modal && (
                <EventFormModal
                    mode={modal.mode}
                    event={modal.event}
                    onClose={() => setModal(null)}
                />
            )}

            <DeleteEventModal
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                event={deleting}
            />

            <DeleteEventModal
                open={forcePurging !== null}
                onClose={() => setForcePurging(null)}
                event={forcePurging}
                isForcePurge
            />
        </ConsoleLayout>
    );
}
