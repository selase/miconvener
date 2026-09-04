import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Plus, Copy } from 'lucide-react';
import ConsoleLayout from '@/Layouts/ConsoleLayout';
import PageHeader from '@/Components/Console/PageHeader';
import Drawer from '@/Components/Console/Drawer';
import Button from '@/Components/Console/Button';
import ConfirmModal from '@/Components/Console/ConfirmModal';
import { Table, Thead, Th, Tr, Td, TableEmpty } from '@/Components/Console/Table';
import RoleFormModal from './RoleFormModal';

function RoleTable({ roles, onSelect, selectedId }) {
    return (
        <Table>
            <Thead>
                <Th>Name</Th>
                <Th>Permissions</Th>
            </Thead>
            <tbody>
                {roles.map((role) => (
                    <Tr key={role.id} onClick={() => onSelect(role)} selected={role.id === selectedId}>
                        <Td>{role.name}</Td>
                        <Td muted>{role.permissions.length} permission{role.permissions.length === 1 ? '' : 's'}</Td>
                    </Tr>
                ))}
            </tbody>
        </Table>
    );
}

export default function Index({ systemRoles, customRoles, permissions }) {
    const [selectedId, setSelectedId] = useState(null);
    const [modal, setModal] = useState(null);
    const [deleting, setDeleting] = useState(null);
    const [blocked, setBlocked] = useState(null);

    const selected = [...systemRoles, ...customRoles].find((role) => role.id === selectedId) ?? null;

    const requestDelete = (role) => {
        if (role.users_count > 0) {
            setBlocked(role);
            return;
        }

        setDeleting(role);
    };

    const confirmDelete = () => {
        router.delete(route('tenant.roles.destroy', { role: deleting.id }), {
            onFinish: () => {
                setDeleting(null);
                setSelectedId(null);
            },
        });
    };

    const openDuplicate = async (role) => {
        const response = await fetch(route('tenant.roles.duplicate.form', { role: role.id }), {
            headers: { Accept: 'application/json' },
        });
        const json = await response.json();
        setModal({ mode: 'duplicate', sourceRole: json.sourceRole, sourcePermissionNames: json.sourcePermissionNames });
    };

    return (
        <ConsoleLayout>
            <PageHeader
                title="Roles"
                actions={<Button icon={Plus} onClick={() => setModal({ mode: 'create' })}>New role</Button>}
            />

            <div className="flex">
                <div className="min-w-0 flex-1 space-y-8 px-8 py-6">
                    <div>
                        <h2 className="mb-3 text-sm font-semibold text-ink-secondary">Custom roles</h2>
                        {customRoles.length > 0 ? (
                            <RoleTable roles={customRoles} onSelect={(role) => setSelectedId(role.id)} selectedId={selectedId} />
                        ) : (
                            <Table>
                                <Thead>
                                    <Th>Name</Th>
                                    <Th>Permissions</Th>
                                </Thead>
                                <tbody>
                                    <tr>
                                        <td colSpan={2}>
                                            <TableEmpty title="No custom roles yet" />
                                        </td>
                                    </tr>
                                </tbody>
                            </Table>
                        )}
                    </div>

                    <div>
                        <h2 className="mb-3 text-sm font-semibold text-ink-secondary">System roles</h2>
                        <RoleTable roles={systemRoles} onSelect={(role) => setSelectedId(role.id)} selectedId={selectedId} />
                    </div>
                </div>

                <Drawer open={selected !== null} onClose={() => setSelectedId(null)}>
                    {selected && (
                        <div>
                            <div>
                                <div className="text-lg font-bold text-ink">{selected.name}</div>
                                <div className="mt-1 text-sm text-ink-secondary">
                                    {selected.is_system ? 'Platform role' : `${selected.users_count} member${selected.users_count === 1 ? '' : 's'}`}
                                </div>
                            </div>

                            <div className="mt-6 flex gap-2">
                                {selected.is_system ? (
                                    <Button icon={Copy} onClick={() => openDuplicate(selected)}>
                                        Duplicate as custom role
                                    </Button>
                                ) : (
                                    <>
                                        <Button onClick={() => setModal({ mode: 'edit', role: selected })}>Edit</Button>
                                        <button
                                            type="button"
                                            onClick={() => requestDelete(selected)}
                                            className="inline-flex h-control items-center rounded-md border border-danger-fg/30 px-4 text-sm font-medium text-danger-fg transition-colors duration-120 ease-out hover:bg-danger-bg"
                                        >
                                            Delete
                                        </button>
                                    </>
                                )}
                            </div>

                            <div className="mt-6 border-t border-border pt-4">
                                <h3 className="text-sm font-semibold text-ink">Permissions</h3>
                                <ul className="mt-3 space-y-1.5">
                                    {selected.permissions.map((permission) => (
                                        <li key={permission} className="text-sm capitalize text-ink-secondary">
                                            {permission}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </Drawer>
            </div>

            {modal && (
                <RoleFormModal
                    mode={modal.mode}
                    role={modal.role}
                    sourceRole={modal.sourceRole}
                    sourcePermissionNames={modal.sourcePermissionNames}
                    permissions={permissions}
                    onClose={() => setModal(null)}
                />
            )}

            <ConfirmModal
                open={deleting !== null}
                onClose={() => setDeleting(null)}
                onConfirm={confirmDelete}
                title="Delete role"
                description={deleting && `Delete the "${deleting.name}" role?`}
                confirmLabel="Delete"
                danger
            />

            <ConfirmModal
                open={blocked !== null}
                onClose={() => setBlocked(null)}
                title="Role in use"
                description={blocked && `${blocked.users_count} team member(s) are assigned to this role. Reassign them first.`}
                confirmLabel="OK"
                hideCancel
            />
        </ConsoleLayout>
    );
}
