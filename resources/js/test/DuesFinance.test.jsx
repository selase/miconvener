import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import Finance from '@/Pages/Tenant/Finance/Index';
import MoneyDues from '@/Pages/Tenant/Community/MoneyDues';
import Overview from '@/Pages/Tenant/Community/Overview';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
const props = {
    transactions: { data: [] },
    stats: { total_volume: 0, transaction_count: 0, refund_volume: 0 },
    filters: {},
};
const dues = {
    show_ticket_finance: false,
    from: '2026-10-01',
    to: '2026-10-15',
    timezone: 'Africa/Accra',
    currencies: {
        GHS: {
            collected_total: 1500,
            recorded_cash: 500,
            collected_online_gross: 1000,
            gateway_fees: 30,
            commission: 20,
            net_online: 950,
            available_to_pay_out: 750,
            in_flight: 200,
            arrears_total: 900,
            members_in_arrears: 2,
        },
    },
};
it('dues-only finance shows flows separately from current balances and exports filtered dates', () => {
    render(<Finance {...props} dues={dues} />);
    expect(screen.getByText('Recorded cash')).toBeTruthy();
    expect(screen.getByText('Current available to pay out')).toBeTruthy();
    expect(screen.queryByText('Total volume')).toBeNull();
    expect(screen.getByRole('link', { name: 'Export collections CSV' }).href).toContain(
        'from=2026-10-01'
    );
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply dates' }));
    expect(router.get).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({ from: '2026-09-01' }),
        expect.any(Object)
    );
});
it('dues report has an empty state without a made-up currency', () => {
    render(<Finance {...props} dues={{ ...dues, currencies: {} }} />);
    expect(screen.getByText(/No dues collections or current balances/)).toBeTruthy();
});
it('applying dues dates preserves current ticket search and status on mixed finance', () => {
    render(
        <Finance
            {...props}
            filters={{ search: 'Ama', status: 'pending' }}
            dues={{ ...dues, show_ticket_finance: true }}
        />
    );
    fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-09-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply dates' }));
    expect(router.get).toHaveBeenLastCalledWith(
        expect.any(String),
        { search: 'Ama', status: 'pending', from: '2026-09-01', to: '2026-10-15' },
        expect.any(Object)
    );
});
it('ticket-only finance retains its transaction screen', () => {
    render(<Finance {...props} />);
    expect(screen.getByText('Total volume')).toBeTruthy();
    expect(screen.queryByText('Dues collections')).toBeNull();
});
it('community money links reports and payouts only for finance readers', () => {
    const { rerender } = render(<MoneyDues membershipTypes={[]} canRemindArrears />);
    expect(screen.getByRole('link', { name: 'Dues reports' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Payouts' })).toBeTruthy();
    rerender(<MoneyDues membershipTypes={[]} canRemindArrears={false} />);
    expect(screen.queryByRole('link', { name: 'Dues reports' })).toBeNull();
});
it('overview describes collections accurately without assuming an empty currency', () => {
    render(<Overview counts={{}} collectedThisMonth={[]} reminderSettings={{}} canReadFinance />);
    expect(screen.getByText('No online collections')).toBeTruthy();
    expect(screen.queryByText(/Ready to pay out/)).toBeNull();
});
it('overview hides finance totals and reporting links without finance permission', () => {
    render(
        <Overview
            counts={{}}
            collectedThisMonth={[]}
            reminderSettings={{}}
            canReadFinance={false}
        />
    );
    expect(screen.queryByText('Collected online this month')).toBeNull();
});
it('giving-only overview omits member counts and member settings', () => {
    render(
        <Overview
            counts={null}
            collectedThisMonth={[]}
            reminderSettings={null}
            canReadFinance
            givingFundsCount={2}
        />
    );
    expect(screen.queryByText('Members')).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Settings' })).toBeNull();
    expect(screen.getByText('Giving funds')).toBeTruthy();
});
