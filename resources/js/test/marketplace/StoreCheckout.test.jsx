import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import StoreCheckout from '@/Components/Marketplace/StoreCheckout';
import StoreOrders, { OrderSummary } from '@/Pages/Public/Marketplace/Orders/Index';
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
    fulfilment_method: 'collection',
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
it('lets the buyer try again when the provider definitively refused to start the payment', async () => {
    csrfFetch
        .mockResolvedValueOnce({ ok: true, json: async () => ({ order }) })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ status: 'failed', checkout_url: null }),
        });
    render(<StoreCheckout shop="teachers" variant={{ id: 'medium', available: true }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review order' }));
    await screen.findByText('GHS 14.50');
    fireEvent.click(screen.getByRole('button', { name: 'Continue to payment' }));
    await screen.findByText(/could not start this payment/);
    expect(screen.queryByText(/Do not pay again/)).toBeNull();
    expect(screen.getByRole('button', { name: 'Continue to payment' })).toBeTruthy();
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

it('shows the method-specific handover progress without calling dispatch delivered', () => {
    render(
        <OrderSummary
            order={{
                ...order,
                state: 'confirmed',
                payment_state: 'paid',
                fulfilment_method: 'delivery',
                fulfilment_state: 'dispatched',
            }}
        />
    );
    const progress = screen.getByRole('list', { name: 'Order handover progress' });
    expect(progress.textContent).toContain('Confirmed');
    expect(progress.textContent).toContain('Ready');
    expect(progress.textContent).toContain('Dispatched');
    expect(progress.textContent).toContain('Delivered');
    expect(progress.querySelector('[aria-current="step"]').textContent).toBe('Dispatched');
});

it('blocks payment while changing or saving a replacement fulfilment option in the storefront', async () => {
    csrfFetch.mockReset();
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            order: {
                ...order,
                fulfilment_options: {
                    policy_id: 'p1',
                    methods: ['collection', 'delivery'],
                    delivery_countries: ['GH'],
                },
            },
        }),
    });
    render(<StoreCheckout shop="teachers" variant={{ id: 'medium', available: true }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review order' }));
    await screen.findByRole('button', { name: 'Change collection or delivery' });
    fireEvent.click(screen.getByRole('button', { name: 'Change collection or delivery' }));
    expect(screen.getByRole('button', { name: 'Continue to payment' }).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText('Collect from the seller'));
    let resolve;
    csrfFetch.mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            })
    );
    fireEvent.click(screen.getByRole('button', { name: 'Accept fulfilment option' }));
    expect(screen.getByRole('button', { name: 'Continue to payment' }).disabled).toBe(true);
    let refresh;
    csrfFetch.mockImplementationOnce(
        () =>
            new Promise((done) => {
                refresh = done;
            })
    );
    await act(async () => resolve({ ok: true, json: async () => ({ order_id: 'replacement' }) }));
    expect(screen.getByRole('button', { name: 'Continue to payment' }).disabled).toBe(true);
    await act(async () =>
        refresh({ ok: true, json: async () => ({ order: { ...order, id: 'replacement' } }) })
    );
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Continue to payment' }).disabled).toBe(false)
    );
});

it('blocks the My Orders payment action while a fulfilment edit is open', async () => {
    csrfFetch.mockReset();
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            order: {
                ...order,
                fulfilment_options: {
                    policy_id: 'p1',
                    methods: ['collection'],
                    collection_location: 'Office',
                },
            },
            verified_until: Date.now() / 1000 + 3600,
        }),
    });
    render(<StoreOrders orderId={order.id} />);
    await screen.findByRole('button', { name: 'Change collection or delivery' });
    fireEvent.click(screen.getByRole('button', { name: 'Change collection or delivery' }));
    expect(screen.getByRole('button', { name: 'Continue to payment' }).disabled).toBe(true);
});

it('requires explicit acceptance before paying a seller-issued quote', async () => {
    const quoted = {
        ...order,
        cancel_allowed: true,
        seller_quote: {
            acceptance_hash: 'a'.repeat(64),
            customisation: 'Red printing',
            production_terms: 'Seven working days',
        },
        production_terms: 'Seven working days',
        customisation: 'Red printing',
        charges: [
            { label: 'Custom printing', amount_pesewas: 5000 },
            { label: 'Delivery', amount_pesewas: 2500 },
        ],
    };
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({ order: quoted, verified_until: Date.now() / 1000 + 3600 }),
    });
    render(<StoreOrders orderId={order.id} />);
    const pay = await screen.findByRole('button', { name: 'Continue to payment' });
    expect(pay.disabled).toBe(true);
    expect(screen.getByText('Seven working days')).toBeTruthy();
    expect(screen.getByText('Custom printing')).toBeTruthy();
    expect(screen.queryByText(/Price and stock held/)).toBeNull();
    expect(screen.getByRole('button', { name: 'Decline unpaid quote' })).toBeTruthy();
    expect(screen.queryByText(/Release unpaid stock hold/)).toBeNull();
    expect(screen.queryByText(/Receipt email:/)).toBeNull();
    fireEvent.click(screen.getByLabelText(/I accept the quoted specification/));
    expect(pay.disabled).toBe(false);
    csrfFetch.mockResolvedValueOnce({
        ok: false,
        json: async () => ({ message: 'Payment paused' }),
    });
    fireEvent.click(pay);
    await screen.findByRole('alert');
    expect(JSON.parse(csrfFetch.mock.calls[1][1].body).accepted_quote_hash).toBe('a'.repeat(64));
});
it('shows immutable quote extras and production terms on the web receipt', async () => {
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            receipt: {
                number: 'QUOTED-R1',
                seller: 'Teachers',
                currency: 'GHS',
                total_pesewas: 47500,
                lines: [{ id: 'line-1', title: 'Tote', quantity: 2, amount_pesewas: 40000 }],
                charges: [
                    { label: 'Custom printing', amount_pesewas: 5000 },
                    { label: 'Delivery', amount_pesewas: 2500 },
                ],
                customisation: 'Red association name on each tote.',
                production_terms: 'Seven working days after artwork approval.',
                issued_at: '2026-10-29T03:00:00Z',
                receipt_terms: 'Agreed quote paid in full.',
            },
            verified_until: Date.now() / 1000 + 3600,
        }),
    });
    render(<StoreOrders orderId="quoted-order" receipt />);
    await screen.findByText('Receipt QUOTED-R1');
    expect(screen.getByText('Custom printing')).toBeTruthy();
    expect(screen.getByText('GHS 50.00')).toBeTruthy();
    expect(screen.getByText('Delivery')).toBeTruthy();
    expect(screen.getByText('GHS 25.00')).toBeTruthy();
    expect(screen.getByText('Red association name on each tote.')).toBeTruthy();
    expect(screen.getByText('Seven working days after artwork approval.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Print / save receipt' })).toBeTruthy();
});
