import { beforeEach, expect, test, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { sendJson } from '@/lib/dues';
import Schedules from '@/Pages/Tenant/Finance/Schedules';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    Link: ({ children, href }) => <a data-inertia-link="true" href={href}>{children}</a>,
}));

vi.mock('@/lib/dues', async (original) => ({ ...(await original()), sendJson: vi.fn() }));

beforeEach(() => {
    global.route = (name) => `/${name}`;
});
const props = {
    policies: [],
    proposals: [],
    accounts: [],
    pagination: {},
    canManage: true,
    canPay: { commerce: true, other: true },
    securityReady: true,
    platformReady: false,
};

test('explains human approval and offers a clear empty state', () => {
    render(<Schedules {...props} />);
    expect(
        screen.getByText(/No transfer is sent until an authorised person submits it/)
    ).toBeTruthy();
    expect(screen.getByText(/No payout proposals yet/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Request schedule approval' }).disabled).toBe(true);
});

test('read-only staff cannot approve or submit a payout', () => {
    render(
        <Schedules
            {...props}
            canManage={false}
            proposals={[
                {
                    id: 'proposal',
                    policy_id: 'policy',
                    status: 'proposed',
                    amount: 10000,
                    expires_at: new Date(Date.now() + 100000).toISOString(),
                    snapshot: { fee: 100, net: 9900 },
                },
            ]}
        />
    );
    expect(screen.queryByRole('button', { name: 'Approve payout' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Submit payout' })).toBeNull();
});

test('successful approval shows the actual server response', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: { message: 'Payout reserved. Review before submission.' },
    });
    render(
        <Schedules
            {...props}
            policies={[{ id: 'policy', category: 'dues', status: 'approved' }]}
            proposals={[
                {
                    id: 'proposal',
                    policy_id: 'policy',
                    status: 'proposed',
                    amount: 10000,
                    expires_at: new Date(Date.now() + 100000).toISOString(),
                    snapshot: { fee: 100, net: 9900 },
                },
            ]}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Approve payout' }));
    await waitFor(() =>
        expect(screen.getByRole('status').textContent).toContain(
            'Payout reserved. Review before submission.'
        )
    );
});


test('password confirmation uses full-page navigation for the standalone authentication page', async () => {
    sendJson.mockResolvedValue({ ok: false, status: 423, body: { message: 'Password confirmation required.' } });
    render(<Schedules {...props} policies={[{ id: 'policy', category: 'dues', status: 'approved' }]}
        proposals={[{ id: 'proposal', policy_id: 'policy', status: 'proposed', amount: 10000,
            expires_at: new Date(Date.now() + 100000).toISOString(), snapshot: { fee: 100, net: 9900 } }]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Approve payout' }));
    const link = await screen.findByRole('link', { name: 'Confirm your password, then return to review this payout.' });
    expect(link.getAttribute('href')).toBe('/password.confirm');
    expect(link.getAttribute('data-inertia-link')).toBeNull();
});
