import { describe, it, expect } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { CurrentPlan } from '@/Pages/Billing/Index';

const enterprise = {
    package_name: 'Enterprise',
    is_free: false,
    complimentary: false,
    status: null,
    period_end: null,
    agreed_price: 'GHS 2,500.00 a month',
    first_payment_due: 'GHS 2,500.00',
    can_pay_now: false,
};

describe('the current plan on the Billing page', () => {
    it('shows an Enterprise organisation its agreed price', () => {
        render(<CurrentPlan plan={enterprise} />);

        expect(screen.getByText('Your agreed price: GHS 2,500.00 a month')).toBeTruthy();
    });

    it('starts the plan by paying the first period at the agreed price', () => {
        render(<CurrentPlan plan={enterprise} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pay GHS 2,500.00' }));

        expect(router.post).toHaveBeenCalledWith(expect.stringContaining('billing.checkout'), {
            plan: 'enterprise',
        });
    });

    it('offers no first payment once the plan is paid for', () => {
        render(<CurrentPlan plan={{ ...enterprise, first_payment_due: null }} />);

        expect(screen.queryByRole('button', { name: /Pay/ })).toBeNull();
    });
});
