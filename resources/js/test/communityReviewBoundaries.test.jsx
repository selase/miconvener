import { afterEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import MemberClaim from '@/Pages/Public/Community/MemberClaim';
import PhoneVerify from '@/Pages/Public/Community/PhoneVerify';
import CommunitiesPanel from '@/Pages/Public/Community/CommunitiesPanel';
import MyPortal from '@/Pages/Public/Events/AttendeePortal/MyPortal';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/registerServiceWorker', () => ({ registerAttendeeServiceWorker: vi.fn() }));
vi.mock('@/lib/offlineTicketStore', () => ({
    clearAllOfflineTickets: vi.fn(),
    purgeExpiredOfflineTickets: vi.fn(),
}));
afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    delete window.turnstile;
});
it('keeps live event entry email-first without offering phone verification as a ticket credential', () => {
    const previous = window.location.href;
    window.history.replaceState({}, '', '/my?return_to=%2Fmy%2Fjoin%2Fevent-id%3Ftab%3Dforms');
    try {
        render(<MyPortal />);
        expect(screen.queryByRole('button', { name: 'Use a phone number instead' })).toBeNull();
        expect(screen.getByText(/Verify your registration email/)).toBeTruthy();
    } finally {
        window.history.replaceState({}, '', previous);
    }
});
it('offers registration to someone opening the attendee area of an upcoming event who has not registered', () => {
    const previous = window.location.href;
    window.history.replaceState({}, '', '/my?return_to=%2Fmy%2Fjoin%2Fevent-id');
    try {
        render(<MyPortal joinEvent={{ name: 'Sorkor at 70', register_url: 'https://acme.test/e/sorkor?attendee_area=register' }} />);
        expect(screen.getByText(/Not registered for Sorkor at 70 yet/)).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Register for this event' }).getAttribute('href')).toBe('https://acme.test/e/sorkor?attendee_area=register');
    } finally {
        window.history.replaceState({}, '', previous);
    }
});
it('offers no registration link without an upcoming event to join', () => {
    const previous = window.location.href;
    window.history.replaceState({}, '', '/my?return_to=%2Fmy%2Fjoin%2Fevent-id');
    try {
        render(<MyPortal />);
        expect(screen.queryByRole('link', { name: 'Register for this event' })).toBeNull();
    } finally {
        window.history.replaceState({}, '', previous);
    }
});
it('offers additive phone proof after email sign-in without discarding the email identity', () => {
    render(
        <MyPortal
            organiser={{ slug: 'pilot', name: 'Pilot' }}
            verifiedEmail="a•@example.test"
            initialHistory={{ needs_attention: [], live_now: [], organisers: [] }}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Verify a phone number' }));
    expect(screen.getByLabelText('Phone number')).toBeTruthy();
    expect(screen.getByText('a•@example.test')).toBeTruthy();
});
it('keeps private recovery and wrong-number reporting reachable when Community is paused', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).endsWith('/identity')
                ? { phone: { masked: '•••4567' }, email: null }
                : String(url).includes('/options')
                  ? {
                        communityEnabled: false,
                        followingEnabled: true,
                        requests: { claims: [], joins: [] },
                        organisation: { slug: 'pilot', name: 'Pilot' },
                        following: { sms: false },
                        joinOptions: { enabled: false },
                    }
                  : { communities: { data: [] } },
    }));
    render(<CommunitiesPanel organiser={{ slug: 'pilot' }} initiallyOpen />);
    expect(await screen.findByRole('button', { name: 'Use my private recovery key' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Report messages for someone else' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Request access review' })).toBeNull();
});
it('can select either independently approved family claim after a refresh', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ selector: 'old-key', secret: 'secret' }),
    });
    const credential = vi.fn();
    render(
        <MemberClaim
            organisationSlug="pilot"
            identity={{ phone: { masked: '•••4567' } }}
            initialClaims={[
                {
                    id: 'newer',
                    claimant_name: 'Newest request',
                    role: 'representative',
                    status: 'pending',
                },
                {
                    id: 'older',
                    claimant_name: 'Earlier family request',
                    role: 'representative',
                    status: 'approved',
                },
            ]}
            onCredential={credential}
        />
    );
    fireEvent.change(screen.getByLabelText('Your access requests'), { target: { value: 'older' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save approved access' }));
    await vi.waitFor(() =>
        expect(credential).toHaveBeenCalledWith({ selector: 'old-key', secret: 'secret' })
    );
    expect(screen.getByRole('option', { name: /Newest request/ })).toBeTruthy();
});
it('renders a fresh challenge while resending a phone code and never reuses a consumed token', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const renderWidget = vi.fn((element, options) => {
        options.callback('fresh-token');
        return 7;
    });
    window.turnstile = { render: renderWidget, remove: vi.fn(), reset: vi.fn() };
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({}) })
        .mockResolvedValueOnce({
            ok: false,
            status: 428,
            json: async () => ({ site_key: 'key', action: 'phone-proof' }),
        })
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({}) });
    render(<PhoneVerify organiserSlug="pilot" onVerified={vi.fn()} onUseEmail={vi.fn()} />);
    fireEvent.change(screen.getByLabelText('Phone number'), { target: { value: '0241234567' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send verification code' }));
    await screen.findByLabelText('Verification code');
    for (let second = 0; second < 61; second++) {
        await act(async () => {
            vi.advanceTimersByTime(1000);
        });
    }
    fireEvent.click(screen.getByRole('button', { name: 'Resend code', exact: true }));
    await vi.waitFor(() => expect(renderWidget).toHaveBeenCalledOnce());
    fireEvent.click(screen.getByRole('button', { name: 'Resend code', exact: true }));
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
    expect(JSON.parse(fetch.mock.calls[2][1].body).turnstile_token).toBe('fresh-token');
    await vi.waitFor(() => expect(window.turnstile.remove).toHaveBeenCalledWith(7));
    fetch.mockResolvedValue({ ok: true, status: 200, json: async () => ({}) });
    for (let second = 0; second < 61; second++) {
        await act(async () => {
            vi.advanceTimersByTime(1000);
        });
    }
    fireEvent.click(screen.getByRole('button', { name: 'Resend code', exact: true }));
    await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(4));
    expect(JSON.parse(fetch.mock.calls[3][1].body).turnstile_token).toBeNull();
});

it('preserves the email sign-in while independently confirming an added phone', async () => {
    vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({}) })
        .mockResolvedValueOnce({ ok: true, status: 200, json: async () => ({ verified: true }) });
    render(
        <MyPortal
            organiser={{ slug: 'pilot', name: 'Pilot' }}
            verifiedEmail="a•@example.test"
            initialHistory={{ needs_attention: [], live_now: [], organisers: [] }}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Verify a phone number' }));
    fireEvent.change(screen.getByLabelText('Phone number'), { target: { value: '0241234567' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send verification code' }));
    fireEvent.change(await screen.findByLabelText('Verification code'), {
        target: { value: '123456' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Verify code' }));
    await vi.waitFor(() =>
        expect(screen.queryByRole('button', { name: 'Verify a phone number' })).toBeNull()
    );
    expect(screen.getByText('a•@example.test')).toBeTruthy();
});
it('enrols phone recovery only for the selected email-owned membership after both proofs', async () => {
    const credential = vi.fn();
    const identity = { email: { masked: 'a•@example.test' }, phone: { masked: '•••4567' } };
    const member = {
        id: 'owned-member',
        name: 'Owned member',
        access_role: 'self',
        status: 'active',
        membership_type: { name: 'Regular', currency: 'GHS' },
        open_total: 12500,
    };
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).endsWith('/phone-access')
                ? { selector: 'scoped-key', secret: 'secret' }
                : String(url).endsWith('/identity')
                  ? identity
                  : String(url).includes('/options')
                    ? {
                          communityEnabled: true,
                          followingEnabled: false,
                          requests: { claims: [], joins: [] },
                          organisation: { slug: 'pilot', name: 'Pilot' },
                          joinOptions: { enabled: false },
                      }
                    : String(url).endsWith('/pilot')
                      ? {
                            community: { slug: 'pilot', name: 'Pilot' },
                            members: { data: [member] },
                        }
                      : { communities: { data: [] } },
    }));
    render(
        <CommunitiesPanel organiser={{ slug: 'pilot' }} initiallyOpen onCredential={credential} />
    );
    await screen.findByRole('button', { name: 'Set up phone recovery' });
    expect(screen.queryByText(/Open dues GHS 125/)).toBeNull();
    expect(screen.getByRole('button', { name: 'Charges and receipts' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Set up phone recovery' }));
    await vi.waitFor(() =>
        expect(credential).toHaveBeenCalledWith({ selector: 'scoped-key', secret: 'secret' })
    );
    expect(
        fetch.mock.calls.some(
            ([url, options]) =>
                url === '/my/communities/pilot/members/owned-member/phone-access' &&
                options.method === 'POST'
        )
    ).toBe(true);
});

it('requires an explicit separate-membership decision for a reserved phone application', async () => {
    const { default: ClaimsPanel } = await import('@/Pages/Tenant/Community/ClaimsPanel');
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) => ({
        ok: true,
        status: 200,
        json: async () =>
            options?.method === 'POST'
                ? { approved: true }
                : String(url).includes('joins')
                  ? {
                        requests: {
                            data: [
                                {
                                    id: 'phone-request',
                                    name: 'Phone Applicant',
                                    channel: 'sms',
                                    status: 'pending',
                                    member_id: null,
                                    membership_type_id: 'regular',
                                },
                            ],
                        },
                    }
                  : { claims: { data: [] } },
    }));
    render(<ClaimsPanel membershipTypes={[{ id: 'regular', name: 'Regular' }]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Membership requests' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Review membership request' }));
    fireEvent.change(screen.getByLabelText('Review notes'), {
        target: { value: 'Independently checked a new applicant.' },
    });
    expect(screen.getByRole('button', { name: 'Save review' }).disabled).toBe(true);
    fireEvent.click(screen.getByLabelText(/I independently checked this is a distinct new person/));
    fireEvent.click(screen.getByRole('button', { name: 'Save review' }));
    await vi.waitFor(() =>
        expect(
            fetch.mock.calls.some(
                ([, options]) =>
                    options?.method === 'POST' && JSON.parse(options.body).create_distinct === true
            )
        ).toBe(true)
    );
});

it('does not link retained private history to a disabled public organisation page', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).endsWith('/identity')
                ? { email: { masked: 'a•@example.test' }, phone: null }
                : String(url).includes('/options')
                  ? {
                        communityEnabled: false,
                        followingEnabled: false,
                        requests: { claims: [], joins: [] },
                        organisation: {
                            slug: 'pilot',
                            name: 'Paused organisation',
                            about_url: 'https://pilot.example.test/community/about',
                        },
                        joinOptions: { enabled: false },
                    }
                  : { communities: { data: [] } },
    }));
    render(<CommunitiesPanel organiser={{ slug: 'pilot' }} initiallyOpen />);
    await screen.findByRole('heading', { name: 'Paused organisation' });
    expect(screen.queryByRole('link', { name: 'Public organisation page' })).toBeNull();
});
it('explains store quote email verification when returning to a private order', () => {
    const previous = window.location.href;
    window.history.replaceState({}, '', '/my?return_to=%2Fmy%2Fstore-orders%2Fquote-id');
    try {
        render(<MyPortal />);
        expect(screen.getByText('Your MiConvener store orders')).toBeTruthy();
        expect(screen.getByText(/Verify the email used for your quote or order/)).toBeTruthy();
    } finally {
        window.history.replaceState({}, '', previous);
    }
});
