import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Input from '@/Components/Console/Input';
import PermissionPicker from './PermissionPicker';

const TITLES = {
    create: 'New role',
    edit: 'Edit role',
    duplicate: 'Duplicate role',
};

const SUBMIT_LABELS = {
    create: 'Create role',
    edit: 'Save changes',
    duplicate: 'Create role',
};

export default function RoleFormModal({ mode, onClose, role, sourceRole, permissions, sourcePermissionNames }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: mode === 'edit' ? role.name : mode === 'duplicate' ? `${sourceRole.name} (Custom)` : '',
        permissions: mode === 'edit' ? role.permissions : mode === 'duplicate' ? sourcePermissionNames : [],
        ...(mode === 'duplicate' ? { source_role_id: sourceRole.id } : {}),
    });

    const submit = (event) => {
        event.preventDefault();

        if (mode === 'create') {
            post(route('tenant.roles.store'), { onSuccess: onClose });
        } else if (mode === 'edit') {
            put(route('tenant.roles.update', { role: role.id }), { onSuccess: onClose });
        } else {
            post(route('tenant.roles.duplicate'), { onSuccess: onClose });
        }
    };

    return (
        <Modal open onClose={onClose} title={TITLES[mode]} className="max-w-2xl">
            <form onSubmit={submit} className="space-y-6">
                {mode === 'duplicate' && (
                    <p className="text-sm text-ink-secondary">
                        Creates a custom role you can edit freely, pre-filled from the permissions {sourceRole.name} normally has.
                    </p>
                )}

                <Input
                    label="Role name"
                    type="text"
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    error={errors.name}
                />

                <div>
                    <h3 className="mb-2 text-sm font-medium text-ink">Permissions</h3>
                    <PermissionPicker
                        permissions={permissions}
                        selected={data.permissions}
                        onChange={(value) => setData('permissions', value)}
                    />
                    {errors.permissions && <p className="mt-1 text-sm text-danger-fg">{errors.permissions}</p>}
                </div>

                <Button type="submit" disabled={processing}>{SUBMIT_LABELS[mode]}</Button>
            </form>
        </Modal>
    );
}
