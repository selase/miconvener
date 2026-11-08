import { expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import EventFormModal from '@/Pages/Tenant/Events/EventFormModal';
import EventsIndex from '@/Pages/Tenant/Events/Index';
import { GuestsTab } from '@/Pages/Tenant/Events/Show';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => <>{children}</> }));
vi.mock('@inertiajs/react', async (original) => ({
    ...(await original()),
    usePage: () => ({ props: { canManageEventMoney: false } }),
    useForm: (data) => ({
        data,
        setData: vi.fn(),
        transform: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        processing: false,
        errors: {},
    }),
}));
it('keeps organiser setup controls separate from payment management', () => {
    render(
        <EventFormModal
            mode="edit"
            event={{ id: 'event', name: 'Summit', ticket_price: 1200, currency: 'GHS' }}
            onClose={vi.fn()}
        />
    );
    expect(screen.getByLabelText('Default price').disabled).toBe(true);
    expect(screen.getByLabelText('Currency').disabled).toBe(true);
    expect(screen.queryByLabelText('Who pays the platform fee')).toBeNull();
    expect(screen.queryByLabelText('Members-only registration')).toBeNull();
    expect(screen.queryByText('Bank name')).toBeNull();
});

it('hides financial review and paid cancellation controls from organisers', () => {
    render(
        <GuestsTab
            canManageEventMoney={false}
            event={{ id: 'event' }}
            registrations={[
                {
                    id: 'paid',
                    full_name: 'Paid guest',
                    email: 'paid@example.test',
                    status: 'confirmed',
                    amount: 1200,
                    can_cancel_without_finance: false,
                },
                {
                    id: 'pending',
                    full_name: 'Offline guest',
                    email: 'offline@example.test',
                    status: 'pending_payment',
                    offline_payment_status: 'pending_verification',
                    amount: 1200,
                },
            ]}
        />
    );
    expect(within(screen.getByText('Paid guest').closest('tr')).queryByText('Cancel')).toBeNull();
    expect(screen.queryByText('Offline Verification')).toBeNull();
    expect(screen.queryByText('Verify')).toBeNull();
});

it('does not offer event creation or deletion to scoped organisers', () => {
    render(
        <EventsIndex
            events={[]}
            stats={{ events_this_year: 0, running_now: 0, total_registered: 0 }}
            canManageEventMoney={false}
        />
    );
    expect(screen.queryByRole('button', { name: 'New event' })).toBeNull();
    expect(
        screen.getByText('Ask an organisation administrator to assign an event to you.')
    ).toBeTruthy();
});
