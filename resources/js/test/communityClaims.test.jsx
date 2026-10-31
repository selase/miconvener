import ClaimsPanel from '@/Pages/Tenant/Community/ClaimsPanel';
import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import MemberClaim from '@/Pages/Public/Community/MemberClaim';
import RecoveryCredential from '@/Pages/Public/Community/RecoveryCredential';
afterEach(() => vi.restoreAllMocks());
it('asks for independent verification without listing phone-matched family members', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce({
        ok: true,
        status: 201,
        json: async () => ({
            claim: {
                id: 'claim',
                status: 'pending',
                claimant_name: 'Ama',
                role: 'representative',
            },
        }),
    });
    render(
        <MemberClaim
            organisationSlug="pilot"
            identity={{ phone: { masked: '••••4567' } }}
            claimUrl="/claims"
        />
    );
    expect(screen.getByText(/Verify your membership/)).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Your name'), { target: { value: 'Ama' } });
    fireEvent.change(screen.getByLabelText('Access requested'), {
        target: { value: 'representative' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Request access review' }));
    await screen.findByText(/awaiting.*independent review/i);
    expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({
        name: 'Ama',
        role: 'representative',
    });
    expect(screen.queryByText(/matching members/i)).toBeNull();
});
it('provides durable copy print and download controls and clears the key when leaving', () => {
    const clear = vi.fn();
    render(
        <RecoveryCredential
            credential={{ selector: 'selector', secret: 'private-key' }}
            onClear={clear}
        />
    );
    expect(screen.getByText(/private-key/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Copy recovery key' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Print recovery key' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Download recovery key' })).toBeTruthy();
    fireEvent(window, new Event('pagehide'));
    expect(clear).toHaveBeenCalledOnce();
    expect(screen.queryByText(/private-key/)).toBeNull();
});

it('requires an independent staff check and retains unsaved review notes', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).includes('member-options')
                ? {
                      members: {
                          data: [
                              {
                                  id: 'member',
                                  name: 'Existing Member',
                                  email: 'recorded@example.test',
                              },
                          ],
                      },
                  }
                : {
                      claims: {
                          data: [
                              {
                                  id: 'claim',
                                  claimant_name: 'Applicant',
                                  phone: '+233241234567',
                                  role: 'representative',
                                  status: 'pending',
                              },
                          ],
                      },
                  },
    }));
    render(<ClaimsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Review access request' }));
    expect(screen.getByLabelText('Independent check')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Save review' }).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('Review notes'), {
        target: { value: 'Independent identity check was performed.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(screen.getByText('Discard unsaved review notes?')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Continue editing' }));
    expect(screen.getByLabelText('Review notes').value).toBe(
        'Independent identity check was performed.'
    );
});

it('lets the current phone holder report another persons messages without selecting or revealing a membership', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue({ ok: true, status: 200, json: async () => ({ accepted: true }) });
    render(<MemberClaim organisationSlug="pilot" identity={{ phone: { masked: '••••4567' } }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Report messages for someone else' }));
    await screen.findByRole('status');
    expect(fetch.mock.calls[0][0]).toBe('/my/communities/pilot/wrong-number');
    expect(JSON.parse(fetch.mock.calls[0][1].body)).not.toHaveProperty('member_id');
});
