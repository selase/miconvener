import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import Payouts from '@/Pages/Tenant/Finance/Payouts';
import { sendJson } from '@/lib/dues';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/Components/Console/Modal', () => ({
    default: ({ title, children }) => (
        <section role="dialog" aria-label={title}>
            {children}
        </section>
    ),
}));
vi.mock('@/lib/dues', async (original) => ({
    ...(await original()),
    sendJson: vi.fn(async () => ({ ok: true, body: {}, errors: {} })),
}));
const props = {
    category: 'giving',
    categories: ['dues', 'giving'],
    balances: [
        { currency: 'GHS', payable: 9700, available: 7720, in_flight: 0, pending_refunds: 1980 },
    ],
    accounts: [
        {
            id: 'bank',
            label: 'Verified account',
            is_verified: true,
            masked_account_number: '••1234',
        },
    ],
    payouts: [],
    canManagePayouts: true,
};

it('Giving payouts distinguish held money refund reservations and amounts owed', () => {
    const { rerender } = render(<Payouts {...props} />);
    expect(screen.getByText(/Held for refunds/)).toBeTruthy();
    expect(screen.getByLabelText('Money category').value).toBe('giving');
    rerender(
        <Payouts
            {...props}
            balances={[
                { currency: 'GHS', payable: -200, available: 0, in_flight: 0, pending_refunds: 0 },
            ]}
        />
    );
    expect(screen.getByText(/Amount owed/)).toBeTruthy();
    expect(screen.queryByText(/Collected GHS -/)).toBeNull();
});
it('changing payout categories requests a separate balance and resets page state', () => {
    render(<Payouts {...props} />);
    fireEvent.change(screen.getByLabelText('Money category'), { target: { value: 'dues' } });
    expect(router.get).toHaveBeenCalledWith(
        expect.any(String),
        { category: 'dues' },
        expect.objectContaining({ preserveState: false })
    );
});
it('a Giving payout submission carries its category and exact minor units', async () => {
    render(<Payouts {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Request payout' }));
    const dialog = within(screen.getByRole('dialog', { name: 'Request payout' }));
    fireEvent.change(dialog.getByLabelText('Amount (GHS)'), { target: { value: '50.01' } });
    fireEvent.click(dialog.getByRole('button', { name: 'Request payout' }));
    await waitFor(() =>
        expect(sendJson).toHaveBeenCalledWith(
            'POST',
            expect.any(String),
            expect.objectContaining({ amount: 5001, category: 'giving' })
        )
    );
});
