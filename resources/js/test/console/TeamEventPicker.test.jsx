import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { useState } from 'react';
import TeamFormModal from '@/Pages/Tenant/Team/TeamFormModal';

// The shared setup mocks Inertia without useForm; this form only needs its
// state handling, so a minimal stand-in is enough.
vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal()),
    useForm: (initial) => {
        const [data, setAll] = useState(initial);

        return {
            data,
            setData: (key, value) => setAll((current) => ({ ...current, [key]: value })),
            post: vi.fn(),
            put: vi.fn(),
            processing: false,
            errors: {},
        };
    },
}));

const roles = [
    { id: 1, name: 'Org Admin' },
    { id: 2, name: 'Event Staff' },
];
const events = [
    { id: 'e1', name: 'Health Summit', starts_at: '10 Oct 2026' },
    { id: 'e2', name: 'Awards Gala', starts_at: '12 Oct 2026' },
];
const member = (roleId, eventIds = []) => ({
    uuid: 'u1',
    first_name: 'Kojo',
    last_name: 'Usher',
    email: 'kojo@example.com',
    phone_no: '0241234567',
    role_id: roleId,
    status: 'active',
    event_ids: eventIds,
    can_change_role: true,
});

describe('choosing the events an Event Staff member works', () => {
    it('offers the event list only for the Event Staff role', () => {
        const { unmount } = render(
            <TeamFormModal
                mode="edit"
                user={member(1)}
                roles={roles}
                statuses={['active']}
                events={events}
                onClose={() => {}}
            />
        );
        expect(screen.queryByText('Events they work')).toBeNull();
        unmount();

        render(
            <TeamFormModal
                mode="edit"
                user={member(2)}
                roles={roles}
                statuses={['active']}
                events={events}
                onClose={() => {}}
            />
        );
        expect(screen.getByText('Events they work')).toBeTruthy();
        expect(screen.getByText(/Leave all unticked to let them work every event/)).toBeTruthy();
    });

    it('shows the events already chosen as ticked', () => {
        render(
            <TeamFormModal
                mode="edit"
                user={member(2, ['e2'])}
                roles={roles}
                statuses={['active']}
                events={events}
                onClose={() => {}}
            />
        );

        expect(screen.getByLabelText(/Awards Gala/).checked).toBe(true);
        expect(screen.getByLabelText(/Health Summit/).checked).toBe(false);
    });
});
