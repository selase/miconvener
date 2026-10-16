import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import MoneyDues from '@/Pages/Tenant/Community/MoneyDues';
import People, { canCreateCharge } from '@/Pages/Tenant/Community/People';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));

it('identifies members on inactive types and links to the member register', () => {
    render(
        <MoneyDues
            membershipTypes={[
                {
                    id: 'type-1',
                    name: 'Retired type',
                    amount: 5000,
                    currency: 'GHS',
                    period: 'monthly',
                    is_active: false,
                    member_count: 2,
                },
            ]}
        />
    );
    expect(screen.getByRole('status').textContent).toContain('2 members');
    expect(screen.getByRole('link', { name: 'Review members' })).toBeTruthy();
});

it('does not offer manual charges when the membership type is inactive', () => {
    expect(
        canCreateCharge({ status: 'active', effective_amount: 5000, membership_type_active: false })
    ).toBe(false);
});

describe('rejoining an exited member', () => {
    it('opens an explicit rejoin confirmation from the member drawer', () => {
        render(
            <People
                members={{
                    data: [
                        {
                            id: 'member-1',
                            name: 'Ama Mensah',
                            email: 'ama@example.test',
                            status: 'exited',
                            class_name: 'Regular',
                            effective_amount: 5000,
                            amount_override: null,
                            currency: 'GHS',
                            open_total: 0,
                            charges: [],
                            pay_url: '/pay/example',
                        },
                    ],
                    links: [],
                }}
                membershipTypes={[]}
            />
        );
        fireEvent.click(screen.getByText('Ama Mensah'));
        fireEvent.click(screen.getByRole('button', { name: 'Rejoin member' }));
        expect(
            screen.getByText(/Automatic dues resume from the next eligible period/)
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Rejoin' })).toBeTruthy();
    });
});

it('shows member usage and explains preservation when over the plan cap', () => {
    render(
        <People
            members={{ data: [], links: [] }}
            membershipTypes={[]}
            memberUsage={{ count: 312, limit: 50, plan: 'Free', over_cap: true }}
        />
    );
    expect(screen.getByText('312 of 50 member slots (including pending requests)')).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toContain('Free plan includes 50 members');
    expect(screen.getByRole('alert').textContent).toContain('Existing members can still pay');
    expect(screen.getByRole('button', { name: 'Add member' }).disabled).toBe(true);
});

it('shows unlimited membership without a cap warning', () => {
    render(
        <People
            members={{ data: [], links: [] }}
            membershipTypes={[]}
            memberUsage={{ count: 7000, limit: null, plan: 'Enterprise', over_cap: false }}
        />
    );
    expect(screen.getByText('7,000 member slots · Unlimited')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByRole('button', { name: 'Add member' }).disabled).toBe(false);
});
