import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import StoreCheckout from '@/Components/Marketplace/StoreCheckout';
import StoreOrders from '@/Pages/Public/Marketplace/Orders/Index';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
const order = {
    id: 'order-1',
    seller: 'Teachers',
    total_pesewas: 1450,
    currency: 'GHS',
    payment_state: 'awaiting',
    state: 'quoted',
    fulfilment_state: 'awaiting_preparation',
    expires_at: new Date(Date.now() + 600000).toISOString(),
    collection_terms: 'Collect at the office',
    refund_terms: 'Full uncollected refunds',
    support_contact: 'store@example.test',
    lines: [
        { id: 'l', title: 'Shirt', quantity: 1, options: { Size: 'Medium' }, amount_pesewas: 1450 },
    ],
    checkout_allowed: true,
};
it('shows the server quote and disables repeat payment when initialization is unknown', async () => {
    csrfFetch
        .mockResolvedValueOnce({ ok: true, json: async () => ({ order }) })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ status: 'unknown', checkout_url: null }),
        });
    render(<StoreCheckout shop="teachers" variant={{ id: 'medium', available: true }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review order' }));
    await screen.findByText('GHS 14.50');
    expect(screen.getByText('Collect at the office')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continue to payment' }));
    await screen.findByText(/Do not pay again/);
    expect(screen.queryByRole('button', { name: 'Continue to payment' })).toBeNull();
    expect(csrfFetch).toHaveBeenCalledTimes(2);
});
it('focuses a recoverable quote error without claiming payment', async () => {
    csrfFetch.mockResolvedValue({
        ok: false,
        status: 422,
        json: async () => ({ message: 'Selection unavailable' }),
    });
    render(<StoreCheckout shop="teachers" variant={{ id: 'medium', available: true }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review order' }));
    const alert = await screen.findByRole('alert');
    await waitFor(() => expect(document.activeElement).toBe(alert));
    expect(screen.queryByText('Payment complete')).toBeNull();
});
it('revalidates order history and clears private records when proof expires', async () => {
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            orders: { data: [order], current_page: 1, last_page: 1 },
            verified_until: Date.now() / 1000 + 3600,
        }),
    });
    render(<StoreOrders />);
    await screen.findByText('Teachers');
    csrfFetch.mockResolvedValueOnce({ ok: false, status: 401 });
    await act(async () => window.dispatchEvent(new Event('focus')));
    await screen.findByRole('link', { name: 'Verify your email' });
    expect(screen.queryByText('Teachers')).toBeNull();
});
it('explains a buyer-borne checkout fee on the immutable receipt', async () => {
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            receipt: {
                number: 'R1',
                seller: 'Teachers',
                currency: 'GHS',
                total_pesewas: 10200,
                buyer_fee_pesewas: 200,
                lines: [],
                issued_at: '2026-10-23T12:00:00Z',
                receipt_terms: 'Payment record only',
            },
            verified_until: Date.now() / 1000 + 3600,
        }),
    });
    render(<StoreOrders orderId="order-1" receipt />);
    expect(await screen.findByText('Checkout fee included: GHS 2.00')).toBeTruthy();
});
it('aborts pending checkout initialization when the buyer leaves the item', async () => {
    csrfFetch
        .mockResolvedValueOnce({ ok: true, json: async () => ({ order }) })
        .mockImplementationOnce(() => new Promise(() => {}));
    const view = render(
        <StoreCheckout shop="teachers" variant={{ id: 'medium', available: true }} />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Review order' }));
    await screen.findByText('GHS 14.50');
    fireEvent.click(screen.getByRole('button', { name: 'Continue to payment' }));
    const options = csrfFetch.mock.calls.at(-1)[1];
    view.unmount();
    expect(options.signal?.aborted).toBe(true);
});
