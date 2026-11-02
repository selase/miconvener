import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import InquiryShow from '@/Pages/Tenant/Venue/Inquiries/Show';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/Components/Venue/VenueFinancialPanel', () => ({ default: () => <section>Final quote and payments</section> }));
vi.mock('@/Components/Venue/VenueApprovalPanel', () => ({ default: () => null, VenuePaymentConflictBanner: () => null }));
const inquiry = {
    id: 'booking', booking_reference: 'VRQ-1', status: 'confirmed', event_type: 'Conference',
    planner_name: 'Buyer', listing: { title: 'Hall' }, rental_amount_pesewas: 100000,
    total_amount_pesewas: 100000, amount_paid_pesewas: 105000,
};

it('uses only the final payment panel for approval-first bookings', () => {
    render(<InquiryShow shop={{}} inquiry={{ ...inquiry, approval: { flow: true, effective_status: 'confirmed' } }} can={{}} />);
    expect(screen.getByText('Final quote and payments')).toBeTruthy();
    expect(screen.queryByText('Financial Quote')).toBeNull();
    expect(screen.queryByText('Deposit Captured:')).toBeNull();
});

it('preserves the existing financial summary for legacy bookings', () => {
    render(<InquiryShow shop={{}} inquiry={inquiry} can={{}} />);
    expect(screen.getByText('Financial Quote')).toBeTruthy();
    expect(screen.getByText('Deposit Captured:')).toBeTruthy();
});
