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
