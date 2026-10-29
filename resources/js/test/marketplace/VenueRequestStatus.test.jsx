import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { expect, it, vi } from 'vitest';
import BookingShow from '@/Pages/Public/Marketplace/Bookings/Show';

vi.mock('@/Layouts/MarketplaceLayout', () => ({ default: ({ children }) => children }));

const base = {
    id: 'b1',
    booking_reference: 'VRQ-ABCD1234',
    status: 'pending_host_approval',
    payment_status: 'unpaid',
    planner_name: 'Ama Planner',
    planner_company: null,
    event_type: 'Workshop',
    guest_count: 20,
    layout_style: 'banquet',
    starts_at: '2030-05-01T09:00:00+00:00',
    ends_at: '2030-05-01T12:00:00+00:00',
    duration_units: 3,
    time_slot_type: 'hourly',
    rental_amount_pesewas: 150000,
    security_deposit_pesewas: 0,
    total_amount_pesewas: 150000,
    deposit_required_pesewas: 0,
    amount_paid_pesewas: 0,
    contract_terms_snapshot: {},
    venue: { title: 'Grand Hall', slug: 'grand-hall' },
    shop: { name: 'Host Hotel', address: '1 Road', city: 'Accra', region: 'Greater Accra' },
    approval: {
        flow: true,
        effective_status: 'pending_host_approval',
        hold_expires_at: null,
        timezone: 'Africa/Accra',
        decision_reason: null,
        price_is_indicative: true,
        can_cancel: true,
    },
};
const withApproval = (changes, status = base.status) => ({
    ...base,
    status,
    approval: { ...base.approval, ...changes },
});

it('says the request awaits the host and that nothing is reserved or payable', () => {
    render(<BookingShow booking={base} />);

    expect(screen.getByText('Awaiting host approval')).toBeTruthy();
    expect(screen.getByText(/Your dates are not reserved until the host approves/)).toBeTruthy();
    expect(screen.queryByText(/Pay Deposit/)).toBeNull();
    expect(screen.queryByText(/Add to Calendar/)).toBeNull();
    expect(screen.queryByText(/Paystack/)).toBeNull();
    expect(screen.getByText(/indicative/i)).toBeTruthy();
});

it('shows the exact hold deadline in the venue zone and still does not call it confirmed', () => {
    render(
        <BookingShow
            booking={withApproval(
                {
                    effective_status: 'approved_hold',
                    hold_expires_at: '2030-04-20T15:30:00+00:00',
                    can_cancel: true,
                },
                'approved_hold'
            )}
        />
    );

    expect(screen.getByText(/Dates held until/)).toBeTruthy();
    expect(screen.getByText(/3:30 pm/i)).toBeTruthy();
    expect(screen.getAllByText(/Africa\/Accra/).length).toBeGreaterThan(0);
    expect(screen.getByText(/not a confirmed booking/)).toBeTruthy();
    expect(screen.queryByText(/Confirmed/)).toBeNull();
    expect(screen.queryByText(/Pay Deposit/)).toBeNull();
});

it('shows an expired hold as no longer reserved with no payment action', () => {
    render(
        <BookingShow
            booking={withApproval(
                {
                    effective_status: 'hold_expired',
                    hold_expires_at: '2030-04-20T15:30:00+00:00',
                    can_cancel: false,
                },
                'approved_hold'
            )}
        />
    );

    expect(screen.getByText('Hold expired')).toBeTruthy();
    expect(screen.getByText(/no longer reserved/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Cancel/ })).toBeNull();
});

it.each([
    ['declined', 'Declined', 'Closed for repairs'],
    ['cancelled', 'Cancelled', 'Changed plans'],
])('shows a %s request with the host reason and no actions', (status, label, reason) => {
    render(
        <BookingShow
            booking={withApproval(
                { effective_status: status, decision_reason: reason, can_cancel: false },
                status
            )}
        />
    );

    expect(screen.getByText(label)).toBeTruthy();
    expect(screen.getByText(new RegExp(reason))).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();
});

it('cancels with a stable operation key and keeps it for a retry', () => {
    render(<BookingShow booking={base} />);
    fireEvent.click(screen.getByRole('button', { name: 'Cancel this request' }));
    fireEvent.click(screen.getByRole('button', { name: 'Cancel this request' }));

    const [url, body] = router.post.mock.calls[0];
    expect(url).toContain('marketplace.bookings.cancel');
    expect(body.operation_key).toMatch(/^[0-9a-f-]{36}$/);
    expect(router.post.mock.calls[1][1].operation_key).toBe(body.operation_key);
});

it('leaves a legacy paid booking exactly as it was', () => {
    const legacy = {
        ...base,
        status: 'confirmed',
        payment_status: 'deposit_paid',
        amount_paid_pesewas: 150000,
        paystack_reference: 'pstk_1',
        approval: undefined,
    };
    render(<BookingShow booking={legacy} />);

    expect(screen.getByText(/Confirmed & Calendar Locked/)).toBeTruthy();
    expect(screen.getByText(/Add to Calendar/)).toBeTruthy();
    expect(screen.getByText(/Paystack Secure/)).toBeTruthy();
});
