import { useForm } from '@inertiajs/react';
import Modal from '@/Components/Console/Modal';
import Button from '@/Components/Console/Button';
import Checkbox from '@/Components/Console/Checkbox';
import TeamMemberForm from './Form';

export default function TeamFormModal({ mode, onClose, user, roles, statuses, events = [] }) {
    const { data, setData, post, put, processing, errors } = useForm({
        first_name: user?.first_name ?? '',
        last_name: user?.last_name ?? '',
        email: user?.email ?? '',
        phone_no: user?.phone_no ?? '',
        role: user?.role_id ?? '',
        status: user?.status ?? statuses[0] ?? 'active',
        event_ids: user?.event_ids ?? [],
    });

    const chosenRole = roles.find((role) => String(role.id) === String(data.role));
    const isEventStaff = chosenRole?.name === 'Event Staff';

    const toggleEvent = (eventId, checked) =>
        setData(
            'event_ids',
            checked ? [...data.event_ids, eventId] : data.event_ids.filter((id) => id !== eventId)
        );

    const submit = (event) => {
        event.preventDefault();

        if (mode === 'edit') {
            put(route('tenant.users.update', { user: user.uuid }), { onSuccess: onClose });
        } else {
            post(route('tenant.users.store'), { onSuccess: onClose });
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={mode === 'edit' ? 'Edit team member' : 'Add team member'}
            className="max-w-2xl"
        >
            <form onSubmit={submit} className="space-y-6">
                <TeamMemberForm
                    data={data}
                    setData={setData}
                    errors={errors}
                    roles={roles}
                    statuses={statuses}
                    roleLocked={mode === 'edit' && user?.can_change_role === false}
                />

                {isEventStaff && events.length > 0 && (
                    <fieldset>
                        <legend className="text-sm font-medium text-ink">Events they work</legend>
                        <p className="mt-1 text-xs text-ink-secondary">
                            Leave all unticked to let them work every event. Tick events to limit
                            them to just those.
                        </p>
                        <div className="mt-2 max-h-56 space-y-2 overflow-y-auto border border-border p-3">
                            {events.map((event) => (
                                <Checkbox
                                    key={event.id}
                                    label={
                                        <span>
                                            {event.name}
                                            {event.starts_at && (
                                                <span className="text-ink-secondary">
                                                    {' '}
                                                    · {event.starts_at}
                                                </span>
                                            )}
                                        </span>
                                    }
                                    checked={data.event_ids.includes(event.id)}
                                    onChange={(e) => toggleEvent(event.id, e.target.checked)}
                                />
                            ))}
                        </div>
                        {errors['event_ids.0'] && (
                            <p className="mt-1 text-xs text-danger-fg">{errors['event_ids.0']}</p>
                        )}
                    </fieldset>
                )}

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
