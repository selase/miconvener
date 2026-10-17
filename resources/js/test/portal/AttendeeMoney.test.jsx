import { render, screen, fireEvent } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeMoney from '@/Pages/Public/Events/AttendeePortal/components/AttendeeMoney';
it('separates currencies, names representative access and preserves real payment links', () => {
    const row = {
        member_id: 'member',
        member_name: 'Someone',
        access_role: 'representative',
        organisation: 'Org',
        totals_by_currency: [
            { currency: 'GHS', open_total: 12000 },
            { currency: 'USD', open_total: 500 },
        ],
        pay_url: '/dues/real',
        charges_url: '/my/charges',
        statement_url: '/my/statement',
    };
    render(
        <AttendeeMoney
            data={{
                dues: { data: [row] },
                totals_by_currency: row.totals_by_currency,
                contributions: null,
            }}
            onPage={vi.fn()}
            onRefresh={vi.fn()}
        />
    );
    expect(screen.getByText(/Representative access/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Review & pay dues' }).getAttribute('href')).toBe(
        '/dues/real'
    );
    expect(screen.queryByText(/available balance/i)).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Contributions & receipts' }));
    expect(screen.getByText(/Verify your email/)).toBeTruthy();
});
