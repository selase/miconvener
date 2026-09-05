import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import StatusPill from '@/Components/Console/StatusPill';
import EventFormModal from './EventFormModal';

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

export default function Index({ events, stats }) {
    const [modal, setModal] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const confirmDelete = () => {
        router.delete(route('tenant.events.destroy', { event: deleting.id }), {
            onFinish: () => setDeleting(null),
        });
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title="Events"
                actions={<Button icon={Plus} onClick={() => setModal({ mode: 'create' })}>New event</Button>}
            />

            <div className="px-8 py-6">
                <div className="mb-6 grid grid-cols-4 gap-4">
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">Events this year</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">{stats.events_this_year}</div>
                    </div>
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">Running now</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">{stats.running_now}</div>
                    </div>
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">People registered</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">{stats.total_registered.toLocaleString()}</div>
                    </div>
                    <div className="rounded-lg border border-border p-4">
                        <div className="text-xs text-ink-secondary">Collected this year</div>
                        <div className="mt-1 text-2xl font-semibold text-ink">{formatMoney(stats.collected_this_year, 'GHS')}</div>
                    </div>
                </div>

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
                                        <StatusPill status={STATUS_VARIANT[event.status]}>{event.status}</StatusPill>
                                    </Td>
                                    <Td muted>{formatMoney(event.ticket_price, event.currency)}</Td>
                                    <Td muted>
                                        {event.registrations_count}
                                        {event.capacity ? ` / ${event.capacity}` : ''}
                                    </Td>
                                    <Td align="right">
                                        <div className="flex justify-end gap-3">
                                            <button onClick={() => setModal({ mode: 'edit', event })} title="Edit event" className="text-ink-secondary hover:text-accent">
                                                <Pencil className="h-4 w-4" strokeWidth={1.75} />
                                            </button>
                                            <button onClick={() => setDeleting(event)} title="Delete event" className="text-ink-secondary hover:text-danger-fg">
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
                                    <TableEmpty title="No events yet" description="Create your first event to start collecting registrations." />
                                </td>
                            </tr>
                        </tbody>
                    </Table>
                )}
            </div>

            {modal && <EventFormModal mode={modal.mode} event={modal.event} onClose={() => setModal(null)} />}

            <ConfirmModal
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={confirmDelete}
                title="Delete event"
                description={deleting && `Delete "${deleting.name}"? This cannot be undone.`}
                confirmLabel="Delete"
                danger
            />
        </ConsoleLayout>
    );
}
