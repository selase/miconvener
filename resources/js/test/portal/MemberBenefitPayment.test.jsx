import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import MemberBenefitPayment from '@/Pages/Public/Events/MemberBenefitPayment';
const benefit = {
    eligible: true,
    current_total: 5000,
    currency: 'GHS',
    payment_state: null,
    expires_at: null,
    extra_captures: 0,
};
it('requires acceptance of the authoritative total before starting a payment', () => {
    const onPay = vi.fn();
    render(<MemberBenefitPayment benefit={benefit} paystackAvailable onPay={onPay} />);
    const pay = screen.getByRole('button', { name: 'Pay with Paystack' });
    expect(pay.disabled).toBe(true);
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(pay);
    expect(onPay).toHaveBeenCalledWith('paystack');
});
it.each(['paid_review', 'unknown', 'refund_pending', 'refund_unknown', 'paid_extra'])(
    'blocks a second charge while %s is unresolved',
    (state) => {
        render(
            <MemberBenefitPayment
                benefit={{ ...benefit, payment_state: state }}
                paystackAvailable
                onPay={vi.fn()}
            />
        );
        expect(screen.queryByRole('button', { name: 'Pay with Paystack' })).toBeNull();
        expect(screen.getByText(/do not pay again/i)).toBeTruthy();
    }
);
it('requires fresh acceptance when the quoted total changes', () => {
    const { rerender } = render(
        <MemberBenefitPayment benefit={benefit} paystackAvailable onPay={vi.fn()} />
    );
    fireEvent.click(screen.getByRole('checkbox'));
    rerender(
        <MemberBenefitPayment
            benefit={{ ...benefit, current_total: 6000 }}
            paystackAvailable
            onPay={vi.fn()}
        />
    );
    expect(screen.getByRole('button', { name: 'Pay with Paystack' }).disabled).toBe(true);
});
it('blocks a second submit before the parent processing state arrives', () => {
    const onPay = vi.fn();
    render(<MemberBenefitPayment benefit={benefit} paystackAvailable onPay={onPay} />);
    fireEvent.click(screen.getByRole('checkbox'));
    const pay = screen.getByRole('button', { name: 'Pay with Paystack' });
    fireEvent.click(pay);
    fireEvent.click(pay);
    expect(onPay).toHaveBeenCalledTimes(1);
});
it('holds an expired open quote instead of offering another payment', () => {
    render(
        <MemberBenefitPayment
            benefit={{
                ...benefit,
                payment_state: 'open',
                expires_at: new Date(Date.now() - 1000).toISOString(),
            }}
            paystackAvailable
            onPay={vi.fn()}
        />
    );
    expect(screen.queryByRole('button', { name: 'Pay with Paystack' })).toBeNull();
    expect(screen.getByText(/do not pay again/i)).toBeTruthy();
});
it('shows an issued-ticket receipt without another payment action', () => {
    render(
        <MemberBenefitPayment
            benefit={{ ...benefit, payment_state: 'fulfilled' }}
            paystackAvailable
            onPay={vi.fn()}
        />
    );
    expect(screen.queryByRole('button', { name: 'Pay with Paystack' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Payment confirmed' })).toBeTruthy();
});
