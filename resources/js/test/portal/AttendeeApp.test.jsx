import { render, screen, fireEvent, within, waitFor } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeApp from '@/Pages/Public/Events/AttendeePortal/AttendeeApp';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
it('keeps certificate, abstract and attendance readers behind the Tickets section', async () => {
    window.history.replaceState({}, '', '/my?section=tickets');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).includes('/app/events')
                ? { passes: { data: [], current_page: 1, last_page: 1 } }
                : { certificates: [] },
    }));
    try {
        render(<AttendeeApp verifiedEmail="o•••@example.test" />);
        fireEvent.click(await screen.findByRole('button', { name: 'Certificates' }));
        await waitFor(() =>
            expect(fetcher).toHaveBeenCalledWith('/my/certificates?', expect.anything())
        );
        expect(screen.getByRole('button', { name: 'Back to tickets' })).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Back to tickets' }));
        expect(screen.getByRole('button', { name: 'Abstracts' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Attendance history' })).toBeTruthy();
        fireEvent.click(
            within(screen.getByRole('navigation', { name: 'Personal app navigation' })).getByRole(
                'button',
                { name: 'Home' }
            )
        );
        expect(window.location.search).toBe('?section=home');
    } finally {
        fetcher.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});

it('does not render a slow previous identity response after the proof changes', async () => {
    let resolveOld;
    window.history.replaceState({}, '', '/my?section=tickets');
    const a = {
        state: 'verified',
        owner_key: 'a'.repeat(64),
        proof_expires_at: Date.now() + 3600000,
    };
    const b = { ...a, owner_key: 'b'.repeat(64) };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: () =>
            String(url).includes('/app/events') && !resolveOld
                ? new Promise((resolve) => {
                      resolveOld = resolve;
                  })
                : Promise.resolve({ passes: { data: [] } }),
    }));
    try {
        const view = render(<AttendeeApp verifiedEmail="a•••@example.test" offline_identity={a} />);
        await waitFor(() => expect(resolveOld).toBeTypeOf('function'));
        view.rerender(<AttendeeApp verifiedEmail="b•••@example.test" offline_identity={b} />);
        await waitFor(() => expect(screen.getByText(/No upcoming tickets/)).toBeTruthy());
        resolveOld({
            passes: {
                data: [
                    {
                        registration_id: 'old',
                        registration_status: 'confirmed',
                        event: { name: 'PRIVATE A EVENT', starts_at: new Date().toISOString() },
                        organiser: { name: 'A' },
                        event_workspace_url: '/my/events/old',
                    },
                ],
            },
        });
        await waitFor(() => expect(screen.queryByText('PRIVATE A EVENT')).toBeNull());
    } finally {
        fetcher.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});

it('offers retry for a failed reader and does not mislabel phone-only access as event ownership', async () => {
    window.history.replaceState({}, '', '/my?section=tickets');
    const fetcher = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });
    try {
        const view = render(<AttendeeApp verifiedEmail="a•••@example.test" />);
        expect(await screen.findByRole('alert')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        await waitFor(() => expect(fetcher.mock.calls.length).toBeGreaterThan(1));
        view.rerender(<AttendeeApp verifiedEmail={null} communityPhoneVerified />);
        expect(await screen.findByText(/Your phone remains independently verified/)).toBeTruthy();
        expect(screen.queryByText(/No upcoming tickets/)).toBeNull();
    } finally {
        fetcher.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});

it('discards displayed Me contacts and organisation preferences after cross-tab sign-out', async () => {
    window.history.replaceState({}, '', '/my?section=me');
    const proof = {
        state: 'verified',
        owner_key: 'c'.repeat(64),
        proof_expires_at: Date.now() + 3600000,
    };
    const fetcher = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).startsWith('/my/identity')
                ? { email: { masked: 'OLD PRIVATE CONTACT' }, phone: null }
                : {
                      preferences: {
                          data: [
                              {
                                  tenant_slug: 'old',
                                  name: 'PRIVATE OLD ORGANISATION',
                                  channels: { email: { marketing_enabled: false } },
                              },
                          ],
                      },
                  },
    }));
    try {
        render(<AttendeeApp verifiedEmail="OLD PRIVATE CONTACT" offline_identity={proof} />);
        expect(await screen.findByText('PRIVATE OLD ORGANISATION')).toBeTruthy();
        window.dispatchEvent(
            new StorageEvent('storage', { key: 'miconvener_attendee_identity_v3', newValue: null })
        );
        await waitFor(() => expect(screen.queryByText('OLD PRIVATE CONTACT')).toBeNull());
        expect(screen.queryByText('PRIVATE OLD ORGANISATION')).toBeNull();
    } finally {
        fetcher.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});
it('refreshes upcoming Home independently of a previously selected saved-ticket filter', async () => {
    window.history.replaceState({}, '', '/my?section=tickets');
    const fetcher = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue({ ok: true, status: 200, json: async () => ({ passes: { data: [] } }) });
    try {
        render(<AttendeeApp verifiedEmail="a••@example.test" />);
        fireEvent.click(await screen.findByRole('button', { name: 'Saved offline' }));
        const before = fetcher.mock.calls.length;
        fireEvent.click(
            within(screen.getByRole('navigation', { name: 'Personal app navigation' })).getByRole(
                'button',
                { name: 'Home' }
            )
        );
        await waitFor(() =>
            expect(
                fetcher.mock.calls
                    .slice(before)
                    .some(([url]) => String(url).includes('/my/app/events?period=upcoming'))
            ).toBe(true)
        );
    } finally {
        fetcher.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});
it('shows authorised Community dues on Home without treating phone proof as ticket ownership', async () => {
    window.history.replaceState({}, '', '/my?section=home');
    const fetcher = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({
            totals_by_currency: [{ currency: 'GHS', open_total: 12000 }],
            dues: { data: [] },
            contributions: null,
        }),
    });
    try {
        render(<AttendeeApp communityPhoneVerified />);
        expect(await screen.findByText('GHS 120.00 due')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Open ticket' })).toBeNull();
    } finally {
        fetcher.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});

it('keeps Giving statements and history filters scoped to the selected organisation', async () => {
    window.history.replaceState({}, '', '/my?organiser=church&section=money&tab=giving');
    const fetcher = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                dues: { data: [] },
                giving: { data: [] },
                contributions: { data: [] },
            }),
        });
    const routeMock = vi.spyOn(globalThis, 'route');
    try {
        render(
            <AttendeeApp
                verifiedEmail="g••@example.test"
                organiser={{ slug: 'church', name: 'Church' }}
            />
        );
        await screen.findByText('Your contributions and payments');
        await waitFor(() =>
            expect(screen.getByRole('link', { name: /Filter contribution records/ })).toBeTruthy()
        );
        expect(routeMock).toHaveBeenCalledWith(
            'attendee.my.giving.index',
            expect.objectContaining({ tenant_slug: 'church' })
        );
        const from = screen.getByLabelText('From');
        const to = screen.getByLabelText('To');
        fireEvent.change(from, { target: { value: '2026-10-01' } });
        fireEvent.change(to, { target: { value: '2026-10-17' } });
        fireEvent.click(screen.getByRole('button', { name: 'Download statement' }));
        expect(routeMock).toHaveBeenCalledWith(
            'attendee.my.giving.statement',
            expect.objectContaining({ tenant_slug: 'church' })
        );
        routeMock.mockClear();
        fireEvent.click(screen.getByRole('button', { name: 'Statements' }));
        fireEvent.change(screen.getByLabelText('From'), { target: { value: '2026-10-01' } });
        fireEvent.change(screen.getByLabelText('To'), { target: { value: '2026-10-17' } });
        fireEvent.click(screen.getByRole('button', { name: 'Download statement' }));
        expect(routeMock).toHaveBeenCalledWith(
            'attendee.my.giving.statement',
            expect.objectContaining({ tenant_slug: 'church' })
        );
    } finally {
        fetcher.mockRestore();
        routeMock.mockRestore();
        window.history.replaceState({}, '', '/');
    }
});
