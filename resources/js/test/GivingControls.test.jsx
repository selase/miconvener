import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import MoneyGiving from '@/Pages/Tenant/Community/MoneyGiving';
import Fund from '@/Pages/Public/Giving/Fund';
import { sendJson } from '@/lib/dues';
vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/dues', async (original) => ({
    ...(await original()),
    sendJson: vi.fn(async () => ({
        ok: false,
        errors: { amount: 'Enter a valid amount.' },
        message: 'Check the amount.',
    })),
}));
const fund = {
    id: 'fund',
    name: 'Offering',
    slug: 'offering',
    currency: 'GHS',
    state: 'open',
    kind: 'open',
    visibility: 'public',
    progress: 'hidden',
    minimum_amount: 100,
    button_label: 'Contribute',
};
it('empty giving screens guide communities without requiring dues or members', () => {
    render(<MoneyGiving funds={[]} canManageGiving canReadFinance={false} enabled />);
    expect(screen.getByRole('button', { name: 'Create fund' })).toBeTruthy();
    expect(screen.getByText(/No funds yet/)).toBeTruthy();
    expect(screen.queryByText(/arrears/i)).toBeNull();
});
it('fund creation defaults to a private draft', () => {
    render(<MoneyGiving funds={[]} canManageGiving enabled />);
    fireEvent.click(screen.getByRole('button', { name: 'Create fund' }));
    expect(screen.getByLabelText('Visibility').value).toBe('private');
    expect(screen.getByLabelText('State').value).toBe('draft');
});
it('record offering batch omits all individual contact fields', () => {
    render(<MoneyGiving funds={[fund]} canManageGiving canReadFinance enabled />);
    fireEvent.click(screen.getByRole('button', { name: 'Record offering/cash batch' }));
    expect(screen.queryByLabelText('Giver name (optional)')).toBeNull();
});
it('online unavailable state does not expose a checkout form', () => {
    render(<Fund organisation={{ name: 'Church' }} fund={fund} providers={[]} accepting />);
    expect(screen.getByRole('status').textContent).toMatch(/not available/);
    expect(screen.queryByLabelText('Amount (GHS)')).toBeNull();
});
it('checkout preserves operation identity when validation fails', async () => {
    render(
        <Fund organisation={{ name: 'Church' }} fund={fund} providers={['paystack']} accepting />
    );
    fireEvent.change(screen.getByLabelText('Amount (GHS)'), { target: { value: '1.001' } });
    fireEvent.click(screen.getByRole('button', { name: 'Contribute' }));
    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    const first = sendJson.mock.calls.at(-1)[2];
    fireEvent.click(screen.getByRole('button', { name: 'Contribute' }));
    await waitFor(() =>
        expect(sendJson.mock.calls.at(-1)[2].submission_key).toBe(first.submission_key)
    );
});
it('refunds show the recorded fee impact before sending', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: {
            principal: 2000,
            currency: 'GHS',
            commission_reversal: 40,
            original_gateway_fee: 200,
            payable_impact: 1960,
            amount_owed: 0,
        },
    });
    render(
        <MoneyGiving
            funds={[fund]}
            gifts={[
                {
                    id: 'gift',
                    fund_name: 'Offering',
                    amount: 10000,
                    currency: 'GHS',
                    source: 'online',
                    status: 'completed',
                },
            ]}
            canReadFinance
            canRefund
            enabled
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Refund' }));
    fireEvent.change(screen.getByLabelText('Amount (GHS)'), { target: { value: '20' } });
    fireEvent.change(screen.getByLabelText('Reason'), { target: { value: 'Requested' } });
    fireEvent.click(screen.getByRole('button', { name: 'Preview refund' }));
    await waitFor(() => expect(screen.getByText(/Commission reversed/)).toBeTruthy());
    expect(sendJson.mock.calls.at(-1)[0]).toBe('GET');
});

it('event checkout retries retain one operation key and exact decimal input', async () => {
    const { default: ContributionWidget } = await import('@/Components/Events/ContributionWidget');
    const submissions = [];
    const submit = vi.spyOn(HTMLFormElement.prototype, 'submit').mockImplementation(function () {
        submissions.push(Object.fromEntries(new FormData(this)));
        throw new Error('Synthetic navigation failure');
    });
    try {
        const view = render(
            <ContributionWidget
                event={{
                    slug: 'fundraiser',
                    currency: 'GHS',
                    contribution_providers: ['paystack'],
                    allow_contributions: true,
                }}
            />
        );
        fireEvent.change(screen.getByPlaceholderText('e.g. Kwame Mensah'), {
            target: { value: 'Synthetic giver' },
        });
        fireEvent.change(screen.getByPlaceholderText(/Other amount/), {
            target: { value: '100.01' },
        });
        fireEvent.submit(view.container.querySelector('form'));
        await waitFor(() => expect(submissions.length).toBe(1));
        fireEvent.submit(view.container.querySelector('form'));
        await waitFor(() => expect(submissions.length).toBe(2));
        expect(submissions[0].submission_key).toBe(submissions[1].submission_key);
        expect(submissions[0].amount).toBe('100.01');
    } finally {
        submit.mockRestore();
        document.querySelectorAll('body > form').forEach((form) => form.remove());
    }
});

it('member attribution is optional separate from payer identity and restricted to authorised staff', async () => {
    sendJson
        .mockResolvedValueOnce({
            ok: true,
            body: { members: [{ id: 'member-1', name: 'Volunteer Pilot' }] },
        })
        .mockResolvedValue({ ok: false, message: 'Preview only' });
    render(
        <MoneyGiving funds={[fund]} canManageGiving canReadFinance canAttributeMember enabled />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record contribution' }));
    fireEvent.change(screen.getByLabelText('Find member by name'), {
        target: { value: 'Volunteer' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search members' }));
    fireEvent.click(await screen.findByRole('button', { name: /Volunteer Pilot/ }));
    expect(
        screen.getByLabelText('I have confirmed this member is the person who gave').checked
    ).toBe(false);
    fireEvent.change(screen.getByLabelText('Amount collected'), { target: { value: '50' } });
    fireEvent.change(screen.getByLabelText('Collected at (optional)'), {
        target: { value: '2026-10-15T09:00' },
    });
    fireEvent.click(screen.getAllByRole('button', { name: 'Record contribution' }).at(-1));
    await waitFor(() =>
        expect(sendJson).toHaveBeenLastCalledWith(
            'POST',
            expect.any(String),
            expect.objectContaining({
                collected_at: '2026-10-15 09:00',
                member_id: 'member-1',
                payer_is_member: false,
                identity_confirmed: false,
            })
        )
    );
});
it('the optional reminder form does not steal initial focus from the fund page', () => {
    render(<Fund organisation={{ name: 'Church' }} fund={fund} providers={[]} accepting />);
    expect(screen.getByLabelText('Your email address')).not.toBe(document.activeElement);
});
