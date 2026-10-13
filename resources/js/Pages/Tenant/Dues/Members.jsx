import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import Modal from '@/Components/Console/Modal';
import Drawer from '@/Components/Console/Drawer';
import DetailCard from '@/Components/Console/DetailCard';
import CopyField from '@/Components/Console/CopyField';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import StatusPill from '@/Components/Console/StatusPill';
import { useToast } from '@/Components/Console/Toast';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import { formatDuesMoney, sendJson } from '@/lib/dues';
import MemberFormModal from './MemberFormModal';

const CHARGE_PILL = { open: 'pending', paid: 'success', waived: 'neutral', void: 'neutral' };

const CHARGE_ACTIONS = {
    waive: {
        route: 'tenant.dues.charges.waive',
        title: 'Waive charge',
        description:
            'The member will no longer owe this charge. This is recorded and cannot be undone.',
        label: 'Waive',
    },
    void: {
        route: 'tenant.dues.charges.void',
        title: 'Void charge',
        description: 'Cancel this charge because it was raised by mistake. It cannot be undone.',
        label: 'Void',
    },
    'record-offline': {
        route: 'tenant.dues.charges.record-offline',
        title: 'Record cash payment',
        description:
            'Record that the member paid this charge in cash or by transfer. No money moves through MiConvener.',
        label: 'Record payment',
    },
};

function memberPill(member) {
    if (member.status === 'exited') {
        return <StatusPill status="neutral">Exited</StatusPill>;
    }

    if (member.overdue) {
        return <StatusPill status="failed">Overdue</StatusPill>;
    }

    return <StatusPill status="success">Active</StatusPill>;
}

function periodLabel(charge) {
    const start = new Date(`${charge.period_start}T00:00:00`);
    const end = new Date(`${charge.period_end}T00:00:00`);
    const isMonth =
        start.getDate() === 1 &&
        end.getMonth() === start.getMonth() &&
        end.getDate() === new Date(end.getFullYear(), end.getMonth() + 1, 0).getDate();

    return isMonth
        ? start.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
        : `${start.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })} – ${end.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

function CreateChargeModal({ member, onClose, onDone }) {
    const showToast = useToast();
    const [periodStart, setPeriodStart] = useState(new Date().toLocaleDateString('en-CA'));
    const [error, setError] = useState(null);
    const [processing, setProcessing] = useState(false);

    const submit = async (event) => {
        event.preventDefault();
        setProcessing(true);
        const result = await sendJson('POST', route('tenant.dues.charges.store'), {
            member_id: member.id,
            period_start: periodStart,
        });
        setProcessing(false);

        if (!result.ok) {
            setError(result.errors.period_start ?? result.message);
            return;
        }

        showToast?.(result.status === 201 ? 'Charge created' : 'That period already has a charge');
        onDone();
    };

    return (
        <Modal open onClose={onClose} title={`Create charge for ${member.name}`}>
            <form onSubmit={submit} className="space-y-4">
                <Input
                    label="Any date in the period to charge"
                    type="date"
                    value={periodStart}
                    onChange={(e) => setPeriodStart(e.target.value)}
                    error={error}
                    hint={`Charges ${formatDuesMoney(member.effective_amount, member.currency)} for the class period containing this date.`}
                    required
                />
                <Button type="submit" variant="primary" disabled={processing}>
                    Create charge
                </Button>
            </form>
        </Modal>
    );
}

export default function Members({ members, classes }) {
    const showToast = useToast();
    const [selectedId, setSelectedId] = useState(null);
    const [modal, setModal] = useState(null);
    const [confirm, setConfirm] = useState(null);
    const [busy, setBusy] = useState(false);

    const selected = members.data.find((member) => member.id === selectedId) ?? null;
    const refresh = () => router.reload({ only: ['members', 'classes'], preserveScroll: true });

    const run = async (request, success) => {
        setBusy(true);
        const result = await request();
        setBusy(false);
        setConfirm(null);

        if (!result.ok) {
            showToast?.(result.message);
            return;
        }

        if (success) {
            showToast?.(success);
        }
        refresh();
    };

    const confirmAction = () => {
        if (confirm.kind === 'exit') {
            return run(
                () =>
                    sendJson(
                        'POST',
                        route('tenant.dues.members.exit', { member: confirm.member.id })
                    ),
                'Member marked as exited'
            );
        }

        if (confirm.kind === 'rotate') {
            return run(
                () =>
                    sendJson(
                        'POST',
                        route('tenant.dues.members.rotate-link', { member: confirm.member.id })
                    ),
                'Pay link replaced'
            );
        }

        const action = CHARGE_ACTIONS[confirm.kind];

        return run(
            () => sendJson('POST', route(action.route, { charge: confirm.charge.id }), {}),
            'Charge updated'
        );
    };

    const confirmCopy = () => {
        if (confirm?.kind === 'exit') {
            return {
                title: 'Mark as exited',
                description: `${confirm.member.name} will stop being charged. Their existing charges are kept and can still be settled.`,
                label: 'Mark as exited',
                danger: true,
            };
        }

        if (confirm?.kind === 'rotate') {
            return {
                title: 'Replace pay link',
                description:
                    'The current link stops working straight away. Send the member the new one.',
                label: 'Replace link',
                danger: true,
            };
        }

        const action = confirm ? CHARGE_ACTIONS[confirm.kind] : null;

        return {
            title: action?.title,
            description:
                action &&
                `${action.description} (${periodLabel(confirm.charge)}, ${formatDuesMoney(confirm.charge.amount, confirm.charge.currency)})`,
            label: action?.label,
            danger: confirm?.kind !== 'record-offline',
        };
    };

    const copy = confirmCopy();

    return (
        <ConsoleLayout>
            <PageHeader
                title="Members"
                actions={
                    <>
                        <Button href={route('tenant.dues.classes.index')} icon={Layers}>
                            Classes
                        </Button>
                        <Button
                            icon={Plus}
                            variant="primary"
                            onClick={() => setModal({ kind: 'form', member: null })}
                        >
                            Add member
                        </Button>
                    </>
                }
            />

            <div className="flex">
                <div className="min-w-0 flex-1 px-8 py-6">
                    <Table>
                        <Thead>
                            <Th>Name</Th>
                            <Th>Class</Th>
                            <Th>Amount</Th>
                            <Th>Owes</Th>
                            <Th>Status</Th>
                        </Thead>
                        <tbody>
                            {members.data.map((member) => (
                                <Tr
                                    key={member.id}
                                    onClick={() => setSelectedId(member.id)}
                                    selected={selectedId === member.id}
                                >
                                    <Td>
                                        <div className="font-medium text-ink">{member.name}</div>
                                        <div className="text-xs text-ink-secondary">
                                            {member.phone ?? member.email}
                                        </div>
                                    </Td>
                                    <Td muted>{member.class_name}</Td>
                                    <Td numeric>
                                        {formatDuesMoney(member.effective_amount, member.currency)}
                                        {member.amount_override !== null && (
                                            <span className="ml-1 text-xs text-ink-secondary">
                                                (custom)
                                            </span>
                                        )}
                                    </Td>
                                    <Td numeric>
                                        {member.open_total > 0
                                            ? formatDuesMoney(member.open_total, member.currency)
                                            : '—'}
                                    </Td>
                                    <Td>{memberPill(member)}</Td>
                                </Tr>
                            ))}
                            {members.data.length === 0 && (
                                <tr>
                                    <td colSpan={5}>
                                        <TableEmpty
                                            title="No members yet"
                                            description={
                                                classes.length === 0
                                                    ? 'Add a class first, then add your members.'
                                                    : 'Add your first member to start collecting dues.'
                                            }
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </Table>

                    {(members.prev_page_url || members.next_page_url) && (
                        <div className="mt-4 flex justify-end gap-2">
                            <Button
                                href={members.prev_page_url ?? undefined}
                                disabled={!members.prev_page_url}
                            >
                                Previous
                            </Button>
                            <Button
                                href={members.next_page_url ?? undefined}
                                disabled={!members.next_page_url}
                            >
                                Next
                            </Button>
                        </div>
                    )}
                </div>

                <Drawer open={selected !== null} onClose={() => setSelectedId(null)}>
                    {selected && (
                        <div>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <div className="text-lg font-bold text-ink">
                                        {selected.name}
                                    </div>
                                    <div className="mt-1">{memberPill(selected)}</div>
                                </div>
                                <Button
                                    onClick={() => setModal({ kind: 'form', member: selected })}
                                >
                                    Edit
                                </Button>
                            </div>

                            <div className="mt-6 space-y-5">
                                <DetailCard title="Contact">
                                    <CopyField label="Phone" value={selected.phone ?? '—'} />
                                    <CopyField label="Email" value={selected.email ?? '—'} />
                                    <CopyField label="Class" value={selected.class_name} />
                                </DetailCard>

                                <DetailCard title="Pay link">
                                    <CopyField
                                        label="Link"
                                        value={selected.pay_url}
                                        display="Open pay page"
                                    />
                                    <div className="pt-1">
                                        <Button
                                            onClick={() =>
                                                setConfirm({ kind: 'rotate', member: selected })
                                            }
                                        >
                                            Replace link
                                        </Button>
                                    </div>
                                </DetailCard>

                                <div>
                                    <div className="mb-3 flex items-center justify-between">
                                        <h3 className="text-sm font-semibold text-ink">Charges</h3>
                                        {selected.status !== 'exited' && (
                                            <Button
                                                icon={Plus}
                                                onClick={() =>
                                                    setModal({ kind: 'charge', member: selected })
                                                }
                                            >
                                                Create charge
                                            </Button>
                                        )}
                                    </div>

                                    {selected.charges.length === 0 && (
                                        <p className="text-sm text-ink-secondary">
                                            No charges yet. They are raised each period, or create
                                            one now.
                                        </p>
                                    )}

                                    <ul className="divide-y divide-border border border-border">
                                        {selected.charges.map((charge) => (
                                            <li key={charge.id} className="px-4 py-3">
                                                <div className="flex items-center justify-between gap-3">
                                                    <div>
                                                        <div className="text-sm font-medium text-ink">
                                                            {periodLabel(charge)}
                                                        </div>
                                                        <div className="text-xs text-ink-secondary">
                                                            {formatDuesMoney(
                                                                charge.amount,
                                                                charge.currency
                                                            )}
                                                            {charge.status === 'open' &&
                                                                ` · due ${charge.due_on}`}
                                                        </div>
                                                    </div>
                                                    <StatusPill
                                                        status={
                                                            charge.overdue
                                                                ? 'failed'
                                                                : CHARGE_PILL[charge.status]
                                                        }
                                                    >
                                                        {charge.overdue
                                                            ? 'Overdue'
                                                            : charge.status[0].toUpperCase() +
                                                              charge.status.slice(1)}
                                                    </StatusPill>
                                                </div>
                                                {charge.status === 'open' && (
                                                    <div className="mt-2 flex flex-wrap gap-2">
                                                        <Button
                                                            onClick={() =>
                                                                setConfirm({
                                                                    kind: 'record-offline',
                                                                    charge,
                                                                })
                                                            }
                                                        >
                                                            Record cash payment
                                                        </Button>
                                                        <Button
                                                            onClick={() =>
                                                                setConfirm({
                                                                    kind: 'waive',
                                                                    charge,
                                                                })
                                                            }
                                                        >
                                                            Waive
                                                        </Button>
                                                        <Button
                                                            onClick={() =>
                                                                setConfirm({ kind: 'void', charge })
                                                            }
                                                        >
                                                            Void
                                                        </Button>
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </div>

                                {selected.status !== 'exited' && (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setConfirm({ kind: 'exit', member: selected })
                                        }
                                        className="inline-flex h-control items-center rounded-md border border-danger-fg/30 px-4 text-sm font-medium text-danger-fg transition-colors duration-120 ease-out hover:bg-danger-bg"
                                    >
                                        Mark as exited
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </Drawer>
            </div>

            {modal?.kind === 'form' && (
                <MemberFormModal
                    member={modal.member}
                    classes={classes}
                    onClose={() => setModal(null)}
                    onSaved={() => {
                        setModal(null);
                        refresh();
                    }}
                />
            )}

            {modal?.kind === 'charge' && (
                <CreateChargeModal
                    member={modal.member}
                    onClose={() => setModal(null)}
                    onDone={() => {
                        setModal(null);
                        refresh();
                    }}
                />
            )}

            <ConfirmModal
                open={confirm !== null}
                onClose={() => setConfirm(null)}
                onConfirm={confirmAction}
                processing={busy}
                title={copy.title}
                description={copy.description}
                confirmLabel={copy.label}
                danger={copy.danger}
            />
        </ConsoleLayout>
    );
}
