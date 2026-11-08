import { it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import OperationsPanel from '@/Pages/Tenant/Events/panels/OperationsPanel';
import NotificationsPanel from '@/Pages/Tenant/Events/panels/NotificationsPanel';
import csrfFetch from '@/lib/csrfFetch';
import SponsorsPanel from '@/Pages/Tenant/Events/panels/SponsorsPanel';

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal()),
    usePage: () => ({ props: { auth: { eventOrganiser: true } } }),
}));

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

it('hides organiser operation budgets and task budget inputs', async () => {
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => ({
            can_manage_budgets: false,
            pillars: [{ id: 'p1', name: 'Programme', tasks: [], can_delete: false }],
            summary: { total_tasks: 0, done_tasks: 0, pillars_count: 1 },
            team_members: [],
        }),
    });
    render(<OperationsPanel event={{ id: 'e1' }} />);
    await screen.findByText('Operational Pillars');
    expect(screen.queryByText('Budget Variance')).toBeNull();
    expect(screen.queryByText(/Spend:/)).toBeNull();
    expect(
        screen.getByTitle(
            'Ask an organisation administrator to remove a record with financial values.'
        ).disabled
    ).toBe(true);
    fireEvent.click(screen.getByText('New Task'));
    expect(screen.queryByText(/Estimated Budget/)).toBeNull();
    expect(screen.queryByText(/Actual Budget Spend/)).toBeNull();
});

it('hides tenant notification quota and settings when settings are withheld', async () => {
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => ({
            settings: null,
            rules: [],
            audiences: [],
            presets: [],
            recent_logs: [],
        }),
    });
    render(<NotificationsPanel event={{ id: 'e1' }} />);
    await screen.findByText('Send Test');
    expect(screen.queryByText('Direct Email Quota')).toBeNull();
    expect(screen.queryByTitle('Notification & Billing Settings')).toBeNull();
});

it('hides sponsorship money while retaining sponsor details and deliverables', async () => {
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => [
            {
                id: 's1',
                name: 'Partner organisation',
                tier: 'partner',
                deliverables: [],
                amount: undefined,
                can_delete: false,
            },
        ],
    });
    render(<SponsorsPanel event={{ id: 'e1', currency: 'GHS' }} />);
    await screen.findByText('Partner organisation');
    expect(screen.queryByText('Sponsorship income')).toBeNull();
    expect(screen.queryByLabelText('Sponsorship amount')).toBeNull();
    expect(screen.getByLabelText('Name')).toBeTruthy();
    expect(
        screen.getByTitle(
            'Ask an organisation administrator to remove a record with financial values.'
        ).disabled
    ).toBe(true);
});
