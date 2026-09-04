import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import TeamMemberForm from './Form';

export default function TeamFormModal({ mode, onClose, user, roles, statuses }) {
    const { data, setData, post, put, processing, errors } = useForm({
        first_name: user?.first_name ?? '',
        last_name: user?.last_name ?? '',
        email: user?.email ?? '',
        phone_no: user?.phone_no ?? '',
        role: user?.role_id ?? '',
        status: user?.status ?? statuses[0] ?? 'active',
    });

    const submit = (event) => {
        event.preventDefault();

        if (mode === 'edit') {
            put(route('tenant.users.update', { user: user.uuid }), { onSuccess: onClose });
        } else {
            post(route('tenant.users.store'), { onSuccess: onClose });
        }
    };

    return (
        <Modal open onClose={onClose} title={mode === 'edit' ? 'Edit team member' : 'Add team member'} className="max-w-2xl">
            <form onSubmit={submit} className="space-y-6">
                <TeamMemberForm data={data} setData={setData} errors={errors} roles={roles} statuses={statuses} />

                {mode === 'create' && (
                    <p className="text-sm text-ink-secondary">
                        We&apos;ll email this person a temporary password and sign-in instructions.
                    </p>
                )}

                <Button type="submit" disabled={processing}>
                    {mode === 'edit' ? 'Save changes' : 'Add team member'}
                </Button>
            </form>
        </Modal>
    );
}
