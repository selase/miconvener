import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import StatusDot from '@/Components/Console/StatusDot';
import StatusPill from '@/Components/Console/StatusPill';
import Drawer from '@/Components/Console/Drawer';
import Button from '@/Components/Console/Button';
import DetailCard from '@/Components/Console/DetailCard';
import CopyField from '@/Components/Console/CopyField';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { useToast } from '@/Components/Console/Toast';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import TeamFormModal from './TeamFormModal';

export default function Index({ users, roles, statuses }) {
    const { flash } = usePage().props;
    const showToast = useToast();
    const [selectedUuid, setSelectedUuid] = useState(null);
    const [modal, setModal] = useState(null);
    const [removing, setRemoving] = useState(null);

    useEffect(() => {
        if (flash?.error) {
            showToast?.(flash.error);
        }
    }, [flash?.error]);

    const selected = users.data.find((user) => user.uuid === selectedUuid) ?? null;

    const confirmRemove = () => {
        router.delete(route('tenant.users.destroy', { user: removing.uuid }), {
            onFinish: () => {
                setRemoving(null);
                setSelectedUuid(null);
            },
        });
    };

    return (
        <ConsoleLayout>
            <PageHeader title="Team" actions={<Button icon={Plus} onClick={() => setModal({ mode: 'create' })}>Add team member</Button>} />

            <div className="flex">
                <div className="min-w-0 flex-1 px-8 py-6">
                    <Table>
                        <Thead>
                            <Th>Name</Th>
                            <Th>Role</Th>
                            <Th>Status</Th>
                            <Th>Last login</Th>
                            <Th>Added</Th>
                        </Thead>
                        <tbody>
                            {users.data.map((user) => (
                                <Tr key={user.uuid} onClick={() => setSelectedUuid(user.uuid)} selected={selectedUuid === user.uuid}>
                                    <Td>
                                        <div className="flex items-center gap-3">
                                            <img src={user.avatar} alt="" className="h-8 w-8 rounded-full object-cover" />
                                            <div>
                                                <div className="font-medium text-ink">{user.name}</div>
                                                <div className="text-xs text-ink-secondary">{user.email}</div>
                                            </div>
                                        </div>
                                    </Td>
                                    <Td muted>{user.role ?? '—'}</Td>
                                    <Td>
                                        <StatusPill status={user.status === 'active' ? 'success' : 'neutral'}>
                                            {user.status === 'active' ? 'Active' : 'Inactive'}
                                        </StatusPill>
                                    </Td>
                                    <Td muted numeric>{user.last_login_at ?? 'Never'}</Td>
                                    <Td muted numeric>{user.created_at}</Td>
                                </Tr>
                            ))}
                            {users.data.length === 0 && (
                                <tr>
                                    <td colSpan={5}>
                                        <TableEmpty title="No team members yet" />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </Table>

                    {(users.prev_page_url || users.next_page_url) && (
                        <div className="mt-4 flex justify-end gap-2">
                            <Button href={users.prev_page_url ?? undefined} disabled={!users.prev_page_url}>Previous</Button>
                            <Button href={users.next_page_url ?? undefined} disabled={!users.next_page_url}>Next</Button>
                        </div>
                    )}
                </div>

                <Drawer open={selected !== null} onClose={() => setSelectedUuid(null)}>
                    {selected && (
                        <div>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-4">
                                    <img src={selected.avatar} alt="" className="h-14 w-14 rounded-full object-cover" />
                                    <div>
                                        <div className="text-lg font-bold text-ink">{selected.name}</div>
                                        <div className="mt-1 flex items-center gap-2 text-sm text-ink-secondary">
                                            <StatusDot status={selected.status === 'active' ? 'success' : 'neutral'} />
                                            {selected.status === 'active' ? 'Active' : 'Inactive'}
                                        </div>
                                    </div>
                                </div>
                                <div className="flex gap-2">
                                    <Button onClick={() => setModal({ mode: 'edit', user: selected })}>Edit</Button>
                                    <button
                                        type="button"
                                        onClick={() => setRemoving(selected)}
                                        className="inline-flex h-control items-center rounded-md border border-danger-fg/30 px-4 text-sm font-medium text-danger-fg transition-colors duration-120 ease-out hover:bg-danger-bg"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>

                            <div className="mt-6 space-y-5">
                                <DetailCard title="Contact">
                                    <CopyField label="Email" value={selected.email} />
                                    <CopyField label="Phone" value={selected.phone_no ?? '—'} />
                                </DetailCard>

                                <DetailCard title="Membership">
                                    <CopyField label="Role" value={selected.role ?? '—'} />
                                    <CopyField label="Added" value={selected.created_at} />
                                    <CopyField label="Last login" value={selected.last_login_at ?? 'Never'} />
                                    <CopyField label="ID" value={selected.uuid} />
                                </DetailCard>
                            </div>
                        </div>
                    )}
                </Drawer>
            </div>

            {modal && (
                <TeamFormModal
                    mode={modal.mode}
                    user={modal.user}
                    roles={roles}
                    statuses={statuses}
                    onClose={() => setModal(null)}
                />
            )}

            <ConfirmModal
                open={removing !== null}
                onClose={() => setRemoving(null)}
                onConfirm={confirmRemove}
                title="Remove from team"
                description={removing && `Remove ${removing.name} from this organization? They will immediately lose access.`}
                confirmLabel="Remove"
                danger
            />
        </ConsoleLayout>
    );
}
