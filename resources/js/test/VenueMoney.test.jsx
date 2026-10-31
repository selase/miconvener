import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import VenueQuotePanel from '@/Components/Marketplace/VenueQuotePanel';
import VenueFinancialPanel from '@/Components/Venue/VenueFinancialPanel';
import { formatMoney, pesewasFromInput } from '@/lib/venueMoney';

const post = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { post: (...args) => post(...args), reload: vi.fn() } }));

const quote = (overrides = {}) => ({
    id: 'q1', version: 1, status: 'open', timezone: 'Africa/Accra', terms_hash: 'a'.repeat(64), accepted_terms_hash: null,
    contract_total_pesewas: 105000, initial_pesewas: 30000, balance_pesewas: 75000, security_pesewas: 20000, rental_pesewas: 100000, extras_pesewas: 5000,
    expires_at: '2099-01-01T10:00:00Z', balance_due_at: '2099-01-10T10:00:00Z', security_due_at: '2099-01-12T10:00:00Z',
    cancellation_terms: 'Full refund up to 14 days before.', support_contact: 'bookings@host.example', withdrawn_reason: null, ...overrides,
});
const financial = (overrides = {}, quoteOverrides = {}) => ({
    quote: quote(quoteOverrides), payments_open: true, charges: [], totals: { rental_received_pesewas: 0, security_held_pesewas: 0, refunded_pesewas: 0 }, ...overrides,
});
const booking = (f) => ({ booking_reference: 'VEN-1', financial: f });

globalThis.route = (name) => `/${name}`;

describe('venue money helpers', () => {
    it('turns typed amounts into whole pesewas and refuses anything unclean', () => {
        expect(pesewasFromInput('1,500.5')).toBe(150050);
        expect(pesewasFromInput('300')).toBe(30000);
        expect(pesewasFromInput('0.07')).toBe(7);
        ['', '1.234', '-5', 'abc', '1e3'].forEach((text) => expect(pesewasFromInput(text)).toBeNull());
        expect(formatMoney(30000)).toBe('GHS 300.00');
    });
});

describe('the buyer quote panel', () => {
    it('shows the terms of an open quote and accepts them with the exact hash, without any commission', () => {
        render(<VenueQuotePanel booking={booking(financial())} />);
        expect(screen.getByText('Cancellation and refunds: Full refund up to 14 days before.')).toBeTruthy();
        expect(screen.queryByText(/commission/i)).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /Accept these terms/ }));
        expect(post).toHaveBeenCalledWith('/marketplace.bookings.quote.accept', expect.objectContaining({ accepted_quote_hash: 'a'.repeat(64) }), expect.anything());
    });

    it('offers payment only for a charge that can be paid and explains an expired or withdrawn quote', () => {
        const charges = [
            { id: 'c1', purpose: 'rental_initial', amount_pesewas: 30000, state: 'due', due_at: null, expires_at: '2099-01-01T00:00:00Z', can_pay: true, has_receipt: false },
            { id: 'c2', purpose: 'rental_balance', amount_pesewas: 75000, state: 'due', due_at: null, expires_at: '2099-01-01T00:00:00Z', can_pay: false, has_receipt: false },
        ];
        const { unmount } = render(<VenueQuotePanel booking={booking(financial({ charges }, { status: 'accepted', terms_hash: null, accepted_terms_hash: 'b'.repeat(64) }))} />);
        expect(screen.getAllByRole('button', { name: 'Pay now' })).toHaveLength(1);
        unmount();

        render(<VenueQuotePanel booking={booking(financial({}, { status: 'expired', terms_hash: null }))} />);
        expect(screen.getByText(/This quote expired/)).toBeTruthy();
        expect(screen.queryByRole('button', { name: /Accept/ })).toBeNull();
    });

    it('says plainly when a payment is under review and when online payment is not open', () => {
        const charges = [{ id: 'c1', purpose: 'security', amount_pesewas: 20000, state: 'under_review', due_at: null, expires_at: '2099-01-01T00:00:00Z', can_pay: false, has_receipt: false }];
        render(<VenueQuotePanel booking={booking(financial({ charges, payments_open: false }, { status: 'accepted', terms_hash: null }))} />);
        expect(screen.getByText(/finance team is checking this payment/)).toBeTruthy();
        expect(screen.getByText(/Online payment is not open/)).toBeTruthy();
    });
});

describe('the host financial panel', () => {
    const inquiry = (overrides = {}) => ({
        id: 'b1', approval: { effective_status: 'approved_hold', timezone: 'Africa/Accra' }, financial: null, ...overrides,
    });

    it('refuses to send a quote with an invalid amount or missing terms and names each problem', () => {
        render(<VenueFinancialPanel inquiry={inquiry()} can={{ complete: false }} />);
        fireEvent.change(screen.getByLabelText('Hire price (GHS)'), { target: { value: '12.345' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send final quote' }));
        expect(screen.getByText('Enter the hire price, for example 1500.00.')).toBeTruthy();
        expect(screen.getByText('State your actual cancellation and refund terms.')).toBeTruthy();
        expect(post).not.toHaveBeenCalledWith(expect.stringContaining('financial-quote'), expect.anything(), expect.anything());
    });

    it('shows the host their commission and only offers completion with the settlement permission', () => {
        const f = { ...financial({ settlement: { held_pesewas: 28000, released_pesewas: 0, completed: false } }, { status: 'accepted', commission_pesewas: 3150, accepted_at: '2099-01-02T00:00:00Z' }), charges: [] };
        const { unmount } = render(<VenueFinancialPanel inquiry={inquiry({ financial: f })} can={{ complete: false }} />);
        expect(screen.getByText('GHS 31.50')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Mark event complete' })).toBeNull();
        unmount();

        render(<VenueFinancialPanel inquiry={inquiry({ financial: f })} can={{ complete: true }} />);
        expect(screen.getByRole('button', { name: 'Mark event complete' }).disabled).toBe(true);
    });
});

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => <div>{children}</div> }));

it('finance still offers the valid rental refund after an extra capture was returned', async () => {
    const { default: Finance } = await import('@/Pages/Tenant/Venue/Finance/Index');
    render(<Finance can={{ refunds: true, reconcile: false, settlements: false }} conflicts={[]} payments={[{
        id: 'charge-1', booking_reference: 'VEN-1', purpose: 'rental_initial', amount_pesewas: 30000,
        payment_state: 'paid', held_captures: [], refund: { id: 'extra-refund', status: 'confirmed' },
    }]} />);
    expect(screen.queryByRole('button', { name: 'Refund in full' })).not.toBeNull();
});
