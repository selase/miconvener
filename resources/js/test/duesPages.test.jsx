import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import Payouts from '@/Pages/Tenant/Finance/Payouts';
import MemberPay from '@/Pages/Public/MemberPay';
import { canCreateCharge, memberPill } from '@/Pages/Tenant/Community/People';

const page = vi.hoisted(() => ({ props: {} }));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: page.props }),
    router: { post: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/Components/Console/ThemeToggle', () => ({ default: () => null }));

const payoutsProps = {
    balances: [{ currency: 'GHS', payable: 9900, in_flight: 0, available: 9900 }],
    accounts: [
        { id: 'acc-1', label: 'Main', account_name: 'Acme', masked_account_number: '***789' },
    ],
    payouts: [
        { id: 'p-1', amount: 1000, currency: 'GHS', status: 'scheduled', created_at: null },
        { id: 'p-2', amount: 2000, currency: 'GHS', status: 'awaiting_otp', created_at: null },
    ],
};

describe('the dues payouts page', () => {
    it('offers request, send and code entry to a user who may manage payouts', () => {
        render(<Payouts {...payoutsProps} canManagePayouts />);

        expect(screen.getByRole('button', { name: /Request payout/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Send' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Enter code' })).toBeTruthy();
    });

    it('shows a finance reader the balances and payouts with no actions', () => {
        render(<Payouts {...payoutsProps} canManagePayouts={false} />);

        expect(screen.queryByRole('button', { name: /Request payout/ })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Send' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Enter code' })).toBeNull();
    });
});

describe('the member status pill', () => {
    it('labels a lapsed member as lapsed, not active', () => {
        render(memberPill({ status: 'lapsed', overdue: false }));

        expect(screen.getByText('Lapsed')).toBeTruthy();
        expect(screen.queryByText('Active')).toBeNull();
    });

    it('keeps exited, overdue and active labels', () => {
        render(
            <>
                {memberPill({ status: 'exited', overdue: true })}
                {memberPill({ status: 'active', overdue: true })}
                {memberPill({ status: 'active', overdue: false })}
            </>
        );

        expect(screen.getByText('Exited')).toBeTruthy();
        expect(screen.getByText('Overdue')).toBeTruthy();
        expect(screen.getByText('Active')).toBeTruthy();
    });
});

describe('the member pay page', () => {
    it('shows the reason a checkout was refused by validation', () => {
        page.props = {
            errors: {
                charge_ids: 'Pay at most 60 charges at a time.',
                'charge_ids.0': 'One of the chosen charges is not valid.',
                'charge_ids.1': 'One of the chosen charges is not valid.',
            },
        };

        render(
            <MemberPay
                mode="pay"
                organisation={{ name: 'Acme', logo_url: null }}
                member={{ name: 'Ama Mensah', first_name: 'Ama' }}
                charges={[]}
                canPayOnline
                checkoutUrl="/pay/t/checkout"
            />
        );

        expect(screen.getByText('Pay at most 60 charges at a time.')).toBeTruthy();
        expect(screen.getAllByText('One of the chosen charges is not valid.')).toHaveLength(1);
        page.props = {};
    });
});

describe('creating a charge from the member drawer', () => {
    it('is offered to an active member whose type has dues', () => {
        expect(canCreateCharge({ status: 'active', effective_amount: 5000 })).toBe(true);
    });

    it('is not offered when the membership type has no dues', () => {
        expect(canCreateCharge({ status: 'active', effective_amount: null })).toBe(false);
    });

    it('is not offered to a member who has left', () => {
        expect(canCreateCharge({ status: 'exited', effective_amount: 5000 })).toBe(false);
    });
});

describe('member charge eligibility', () => {
    it.each(['pending', 'lapsed'])('does not offer a charge for a %s member', (status) => {
        expect(canCreateCharge({ status, effective_amount: 5000 })).toBe(false);
    });
});
