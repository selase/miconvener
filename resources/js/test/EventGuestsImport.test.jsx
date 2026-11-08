import { expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { GuestsTab } from '@/Pages/Tenant/Events/Show';

vi.mock('@inertiajs/react', async (original) => ({
    ...(await original()),
    usePage: () => ({ props: { auth: { can: {} } } }),
}));

it('directs only paid-elsewhere guests to the original provider for refunds', () => {
    render(
        <GuestsTab
            event={{ id: 'event' }}
            registrations={[
                {
                    id: 'free',
                    full_name: 'Free guest',
                    email: 'free@example.test',
                    status: 'confirmed',
                    registration_source: 'external_import',
                    admission_payment_label: 'Imported free admission',
                    amount: 0,
                },
                {
                    id: 'paid',
                    full_name: 'Paid guest',
                    email: 'paid@example.test',
                    status: 'confirmed',
                    registration_source: 'external_import',
                    admission_payment_label: 'Paid elsewhere',
                    external_amount: 1234,
                    external_currency: 'GHS',
                    amount: 0,
                },
            ]}
        />
    );
    const free = screen.getByText('Free guest').closest('tr');
    const paid = screen.getByText('Paid guest').closest('tr');
    expect(within(free).queryByText(/Refund external money/)).toBeNull();
    expect(within(paid).getByText(/Refund external money/)).toBeTruthy();
});
