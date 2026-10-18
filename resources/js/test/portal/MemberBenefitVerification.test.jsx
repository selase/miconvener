import { expect, it, vi } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import MemberBenefitVerification from '@/Pages/Public/Events/MemberBenefitVerification';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
vi.mock('@/Pages/Public/Events/AttendeePortal/VerifyPrompt', () => ({
    default: () => <p>Email verification</p>,
}));
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
