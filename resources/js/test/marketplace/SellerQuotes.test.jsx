import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import SellerQuoteForm from '@/Components/Marketplace/SellerQuoteForm';
import SellerQuotes from '@/Pages/Tenant/Store/Quotes/Index';
import { sendJson } from '@/lib/dues';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/dues', async (original) => ({ ...(await original()), sendJson: vi.fn() }));
const variants = [
    { id: 'bag', label: 'Small white tote', price_pesewas: 20000, visibility: 'public' },
];
const policy = {
    policy_id: 'policy',
    methods: ['collection', 'delivery'],
    collection_location: 'Office by appointment',
    delivery_countries: ['GH'],
};

it('issues integer goods and printing amounts without inventing inventory or sending email', async () => {
    sendJson.mockResolvedValueOnce({ ok: true, errors: {}, body: { quote: { id: 'issued' } } });
    const issued = vi.fn();
    render(<SellerQuoteForm variants={variants} policy={policy} canIssue onIssued={issued} />);
    fireEvent.change(screen.getByLabelText('Buyer email'), {
        target: { value: 'buyer@example.test' },
    });
    fireEvent.change(screen.getByLabelText('Product 1'), { target: { value: 'bag' } });
    fireEvent.change(screen.getByLabelText('Quantity 1'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Customisation specification'), {
        target: { value: 'Red association name' },
    });
    fireEvent.change(screen.getByLabelText('Production timeframe'), {
        target: { value: 'Seven working days after payment' },
    });
    fireEvent.change(screen.getByLabelText('Printing charge (GHS)'), { target: { value: '50' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Collect from the seller' }));
    fireEvent.click(screen.getByRole('button', { name: 'Issue quote & create payment link' }));
    await waitFor(() => expect(issued).toHaveBeenCalledWith({ id: 'issued' }));
    const body = sendJson.mock.calls[0][2];
    expect(body.lines).toEqual([{ variant_id: 'bag', quantity: 2, unit_price_pesewas: 20000 }]);
    expect(body.printing_pesewas).toBe(5000);
    expect(body.delivery_pesewas).toBe(0);
    expect(body.choice.method).toBe('collection');
    expect(body.send_email).toBeUndefined();
    expect(body.initial_stock).toBeUndefined();
});

it('keeps quote issuance disabled when payment activation is paused', () => {
    render(
        <SellerQuoteForm variants={variants} policy={policy} canIssue={false} onIssued={vi.fn()} />
    );
    expect(screen.getByRole('button', { name: 'Issue quote & create payment link' }).disabled).toBe(
        true
    );
});

it('retains the operation key across a failed request and displays the server error', async () => {
    sendJson.mockResolvedValue({
        ok: false,
        errors: { quote: 'Payment setup needs review.' },
        message: 'Check the quote.',
    });
    render(<SellerQuoteForm variants={variants} policy={policy} canIssue onIssued={vi.fn()} />);
    fireEvent.change(screen.getByLabelText('Buyer email'), {
        target: { value: 'buyer@example.test' },
    });
    fireEvent.change(screen.getByLabelText('Product 1'), { target: { value: 'bag' } });
    fireEvent.change(screen.getByLabelText('Production timeframe'), {
        target: { value: 'One week' },
    });
    fireEvent.click(screen.getByRole('radio', { name: 'Collect from the seller' }));
    fireEvent.click(screen.getByRole('button', { name: 'Issue quote & create payment link' }));
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: 'Issue quote & create payment link' }));
    await waitFor(() => expect(sendJson).toHaveBeenCalledTimes(2));
    expect(sendJson.mock.calls[0][2].operation_key).toBe(sendJson.mock.calls[1][2].operation_key);
    expect(screen.getByRole('alert').textContent).toContain('Payment setup needs review.');
});

it('shows existing private payment links and explains how they are protected', () => {
    render(
        <SellerQuotes
            variants={[]}
            can_issue={false}
            quotes={{
                data: [
                    {
                        id: 'q1',
                        buyer_email: 'buyer@example.test',
                        payment_link: 'https://miconvener.com/my/store-orders/private-order',
                        order: {
                            id: 'private-order',
                            number: 'Q1',
                            seller: 'Purpledot',
                            currency: 'GHS',
                            total_pesewas: 20000,
                            payment_label: 'Quote ready',
                            lines: [],
                            expires_at: new Date(Date.now() + 600000).toISOString(),
                        },
                    },
                ],
                links: [],
            }}
        />
    );
    expect(
        screen.getByDisplayValue('https://miconvener.com/my/store-orders/private-order')
    ).toBeTruthy();
    expect(screen.getByText(/verify the buyer email/i)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Copy payment link for Q1' })).toBeTruthy();
});

it('shows a rejected withdrawal inside the open dialog when payment has already started', async () => {
    sendJson.mockResolvedValueOnce({
        ok: false,
        errors: { quote: 'Reconcile the existing payment before withdrawing.' },
        message: 'Withdrawal blocked.',
    });
    const quote = {
        id: 'q1',
        buyer_email: 'buyer@example.test',
        can_withdraw: true,
        payment_link: 'https://miconvener.com/my/store-orders/private-order',
        order: {
            id: 'private-order',
            number: 'Q1',
            seller: 'Purpledot',
            currency: 'GHS',
            total_pesewas: 20000,
            payment_label: 'Quote ready',
            lines: [],
            expires_at: new Date(Date.now() + 600000).toISOString(),
        },
    };
    render(<SellerQuotes variants={[]} can_issue={false} quotes={{ data: [quote], links: [] }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Withdraw quote' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText('Reason for withdrawal'), {
        target: { value: 'Buyer changed requirements.' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm withdrawal' }));
    expect((await within(dialog).findByRole('alert')).textContent).toContain(
        'Reconcile the existing payment'
    );
});
