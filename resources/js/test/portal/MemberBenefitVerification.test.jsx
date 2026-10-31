import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import MemberBenefitVerification from '@/Pages/Public/Events/MemberBenefitVerification';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
it('does not apply stale member prices after the registration email changes', async () => {
    let finishOld;
    csrfFetch.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finishOld = resolve;
            })
    );
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => ({ eligible: true, member_ticket_price: 3000 }),
    });
    const onEligibility = vi.fn();
    const event = { slug: 'members', member_benefits: { enabled: true, members_only: true } };
    const { rerender } = render(
        <MemberBenefitVerification
            event={event}
            email="old@example.test"
            onEligibility={onEligibility}
        />
    );
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledTimes(1));
    rerender(
        <MemberBenefitVerification
            event={event}
            email="new@example.test"
            onEligibility={onEligibility}
        />
    );
    await waitFor(() =>
        expect(onEligibility).toHaveBeenLastCalledWith(
            expect.objectContaining({ email: 'new@example.test', eligible: true })
        )
    );
    finishOld({ ok: true, json: async () => ({ eligible: true, member_ticket_price: 100 }) });
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(onEligibility).toHaveBeenLastCalledWith(
        expect.objectContaining({ email: 'new@example.test', member_ticket_price: 3000 })
    );
});

it.each([false, true])(
    'verifies an email before recovering an existing registration (paid recovery: %s)',
    async (recoverPayment) => {
        csrfFetch
            .mockResolvedValueOnce({ ok: true, json: async () => ({ message: 'Code sent.' }) })
            .mockResolvedValueOnce({ ok: true, json: async () => ({ email: 'ow••@example.test' }) })
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ resolved: true, message: 'Your ticket is ready.' }),
            });
        const onRefreshed = vi.fn();
        render(
            <MemberBenefitVerification
                event={{ slug: 'members' }}
                email="owner@example.test"
                registration={{ id: 'r1', email: 'owner@example.test' }}
                recoverPayment={recoverPayment}
                onRefreshed={onRefreshed}
            />
        );
        fireEvent.click(screen.getByRole('button', { name: 'Verify my membership' }));
        fireEvent.change(screen.getByLabelText('Your email address'), {
            target: { value: 'owner@example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Email me a code' }));
        fireEvent.change(await screen.findByLabelText('Verification code'), {
            target: { value: '482913' },
        });
        expect(onRefreshed).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        await waitFor(() =>
            expect(onRefreshed).toHaveBeenCalledWith(expect.objectContaining({ resolved: true }))
        );
        expect(csrfFetch.mock.calls[0][0]).toContain('/public.events.member-verify.send?');
        expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({
            email: 'owner@example.test',
            turnstile_token: null,
        });
        expect(csrfFetch.mock.calls[1][0]).toContain('/public.events.member-verify.confirm?');
        expect(JSON.parse(csrfFetch.mock.calls[1][1].body)).toEqual({
            email: 'owner@example.test',
            code: '482913',
        });
        expect(csrfFetch.mock.calls[2][0]).toContain(
            recoverPayment
                ? '/public.events.member-benefits.reassess?'
                : '/public.events.member-benefits.reverify?'
        );
    }
);
