import { expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import Payments from '@/Pages/Tenant/Settings/Payments';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@inertiajs/react', async () => {
    const { createElement } = await import('react');
    return {
        Link: ({ href, children }) => createElement('a', { href }, children),
        router: {},
        useForm: (data) => ({
            data,
            setData: vi.fn(),
            post: vi.fn(),
            processing: false,
            errors: {},
        }),
    };
});
it('dues-only payment settings clearly offers Paystack and no Stripe connection', () => {
    render(
        <Payments
            settlementMode="own_gateway"
            platformSettlementAvailable
            ticketPaymentsAvailable={false}
            gateways={{ paystack: { connected: false } }}
            stripe={{ configured: true }}
            commissionSplit={{ enforced: false, destinations: {} }}
            commissionCredit={[]}
        />
    );
    expect(screen.queryByRole('link', { name: 'Connect with Stripe' })).toBeNull();
    expect(screen.getByText(/Dues payments use your active Paystack account/)).toBeTruthy();
    expect(
        screen.queryByText(/Payments go straight into your own Paystack or Stripe account/)
    ).toBeNull();
});
