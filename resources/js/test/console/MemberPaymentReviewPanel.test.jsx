import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import MemberPaymentReviewPanel from '@/Pages/Tenant/Events/MemberPaymentReviewPanel';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
const source = {
    id: 'attempt-1',
    reference: 'member_original',
    registration_name: 'Ama',
    provider: 'paystack',
    currency: 'GHS',
    amount: 5000,
    state: 'paid_review',
    review_reason: 'capture_late',
    can_refund: true,
    refund_reference: null,
};
beforeEach(() => csrfFetch.mockReset());
it('separates captured money from ticket issuance and requires confirmation before a refund', async () => {
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => ({ data: [source], current_page: 1, last_page: 1 }),
    });
    render(<MemberPaymentReviewPanel event={{ id: 'event-1' }} canManage />);
    await screen.findByText('member_original');
    expect(screen.getByText('GHS 50.00')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Refund in full' }));
    expect(csrfFetch.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(
        0
    );
    fireEvent.click(screen.getByRole('button', { name: 'Request full refund' }));
    await waitFor(() =>
        expect(
            csrfFetch.mock.calls.filter(([, options]) => options?.method === 'POST')
        ).toHaveLength(1)
    );
    expect(csrfFetch.mock.calls.find(([, options]) => options?.method === 'POST')[0]).toContain(
        'tenant.events.member-payments.refund'
    );
});
it('keeps recovery actions unavailable for read-only staff', async () => {
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => ({ data: [source], current_page: 1, last_page: 1 }),
    });
    render(<MemberPaymentReviewPanel event={{ id: 'event-1' }} canManage={false} />);
    await screen.findByText('member_original');
    expect(screen.queryByRole('button', { name: 'Refund in full' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Check original payment' })).toBeNull();
});
