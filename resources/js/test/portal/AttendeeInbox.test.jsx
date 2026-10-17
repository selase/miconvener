import { afterEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import InboxList from '@/Pages/Public/Community/InboxList';
import InboxMessage from '@/Pages/Public/Community/InboxMessage';
import AttendeeApp from '@/Pages/Public/Events/AttendeePortal/AttendeeApp';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
vi.mock('@/lib/registerServiceWorker', () => ({ registerAttendeeServiceWorker: vi.fn() }));
afterEach(() => {
    vi.restoreAllMocks();
    window.history.replaceState({}, '', '/');
});
const entry = {
    id: 'message-one',
    context: 'self',
    kind: 'announcement',
    subject: 'Volunteer gathering',
    available_at: '2026-10-17T09:00:00Z',
    read: false,
    label: null,
};
const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });
it('lists unread copies without a read mutation and opens a safe message before marking it read', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) =>
        response(
            options?.method === 'POST'
                ? { read: true }
                : String(url).includes('message-one')
                  ? {
                        entry: {
                            ...entry,
                            body: 'Hello volunteers. https://example.test/next <script>unsafe()</script>',
                        },
                    }
                  : { entries: { data: [entry], current_page: 1, last_page: 1 } }
        )
    );
    const changed = vi.fn();
    render(
        <InboxList
            tenantSlug="volunteers"
            name="Volunteer group"
            onClose={() => {}}
            onChanged={changed}
        />
    );
    fireEvent.click(await screen.findByRole('button', { name: /Volunteer gathering/ }));
    expect(await screen.findByText(/Hello volunteers/)).toBeTruthy();
    await waitFor(() =>
        expect(fetch).toHaveBeenCalledWith(
            '/my/communities/volunteers/inbox/message-one/read',
            expect.objectContaining({ method: 'POST', cache: 'no-store' })
        )
    );
    expect(
        screen.getByRole('link', { name: 'https://example.test/next' }).getAttribute('rel')
    ).toContain('noopener');
    expect(document.querySelector('script')).toBeNull();
    expect(changed).toHaveBeenCalled();
});
it('shows list failures and retry without pretending the inbox is empty or reading anything', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce(response({}, 500))
        .mockResolvedValue(response({ entries: { data: [], current_page: 1, last_page: 1 } }));
    render(<InboxList tenantSlug="church" name="Church" onClose={() => {}} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retry messages' }));
    expect(await screen.findByText('No messages yet.')).toBeTruthy();
    expect(fetch.mock.calls.some(([, options]) => options?.method === 'POST')).toBe(false);
});
it('marks all visible records through the server and labels representative copies', async () => {
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) =>
        response(
            options?.method === 'POST'
                ? { read: true }
                : {
                      entries: {
                          data: [
                              {
                                  ...entry,
                                  context: 'representative:member',
                                  label: 'For Ama (representative)',
                              },
                          ],
                          current_page: 1,
                          last_page: 1,
                      },
                  }
        )
    );
    render(<InboxList tenantSlug="group" name="Group" onClose={() => {}} />);
    expect(await screen.findByText('For Ama (representative)')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Mark all read' }));
    await waitFor(() =>
        expect(fetch).toHaveBeenCalledWith(
            '/my/communities/group/inbox/read-all',
            expect.objectContaining({ method: 'POST' })
        )
    );
    expect(screen.queryByText('Unread')).toBeNull();
});
it('discards a late previous message body and never marks that stale identity read', async () => {
    let finishOld;
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(async (url, options) =>
            String(url).includes('/old') && !options?.method
                ? response({})
                : response(
                      options?.method === 'POST'
                          ? { read: true }
                          : { entry: { ...entry, id: 'new', body: 'Current private copy' } }
                  )
        );
    fetch.mockImplementation(async (url, options) => {
        if (String(url).endsWith('/old?context=self'))
            return {
                ok: true,
                status: 200,
                json: () =>
                    new Promise((resolve) => {
                        finishOld = resolve;
                    }),
            };
        return response(
            options?.method === 'POST'
                ? { read: true }
                : { entry: { ...entry, id: 'new', body: 'Current private copy' } }
        );
    });
    const view = render(
        <InboxMessage tenantSlug="first" itemId="old" context="self" onBack={() => {}} />
    );
    await waitFor(() => expect(finishOld).toBeTypeOf('function'));
    view.rerender(
        <InboxMessage tenantSlug="second" itemId="new" context="self" onBack={() => {}} />
    );
    await screen.findByText('Current private copy');
    await act(async () => finishOld({ entry: { ...entry, id: 'old', body: 'OLD PRIVATE BODY' } }));
    expect(screen.queryByText('OLD PRIVATE BODY')).toBeNull();
    expect(fetch.mock.calls.some(([url]) => String(url).includes('/old/read'))).toBe(false);
});
it('removes an unavailable detail and keeps private content out of browser storage', async () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem');
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(response({}, 404));
    render(<InboxMessage tenantSlug="group" itemId="gone" context="self" onBack={() => {}} />);
    expect(await screen.findByText(/This message is no longer available/)).toBeTruthy();
    expect(storage).not.toHaveBeenCalled();
});
it('shows phone-credential Home messages without requiring event-email access', async () => {
    window.history.replaceState({}, '', '/my?section=home');
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(async (url) =>
            response(
                String(url) === '/my/inbox'
                    ? { organisations: [{ slug: 'church', name: 'Community Church', unread: 123 }] }
                    : String(url).includes('/money')
                      ? { totals_by_currency: [] }
                      : { passes: { data: [] } }
            )
        );
    render(
        <AttendeeApp
            community_inbox_enabled
            communityPhoneVerified
            offline_identity={{
                state: 'verified',
                owner_key: 'p'.repeat(64),
                proof_expires_at: Date.now() + 3600000,
            }}
        />
    );
    expect(await screen.findByText('New messages')).toBeTruthy();
    expect(screen.getByText('99+')).toBeTruthy();
    expect(fetch.mock.calls.some(([url]) => String(url).includes('/app/events'))).toBe(false);
    expect(screen.queryByRole('button', { name: 'Inbox' })).toBeNull();
});
it('clears delayed inbox discovery and bodies on sign-out', async () => {
    window.history.replaceState({}, '', '/my?section=home');
    let finish;
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) =>
        String(url) === '/my/inbox'
            ? {
                  ok: true,
                  status: 200,
                  json: () =>
                      new Promise((resolve) => {
                          finish = resolve;
                      }),
              }
            : response(String(url).includes('/app/events') ? { passes: { data: [] } } : {})
    );
    render(
        <AttendeeApp
            community_inbox_enabled
            verifiedEmail="a••@example.test"
            offline_identity={{
                state: 'verified',
                owner_key: 'q'.repeat(64),
                proof_expires_at: Date.now() + 3600000,
            }}
        />
    );
    await waitFor(() => expect(finish).toBeTypeOf('function'));
    act(() =>
        window.dispatchEvent(
            new CustomEvent('miconvener:attendee-identity', { detail: { state: 'signed_out' } })
        )
    );
    await act(async () =>
        finish({ organisations: [{ slug: 'old', name: 'OLD ORGANISATION', unread: 1 }] })
    );
    expect(screen.queryByText('OLD ORGANISATION')).toBeNull();
    expect(fetch).toHaveBeenCalled();
});

it('reloads discovery when the owner changes even if masked contacts look the same', async () => {
    window.history.replaceState({}, '', '/my?section=home');
    let count = 0;
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url) =>
        response(
            String(url) === '/my/inbox'
                ? {
                      organisations: [
                          {
                              slug: 'org',
                              name:
                                  ++count === 1
                                      ? 'First owner community'
                                      : 'Second owner community',
                              unread: 1,
                          },
                      ],
                  }
                : String(url).includes('/app/events')
                  ? { passes: { data: [] } }
                  : {}
        )
    );
    const props = {
        community_inbox_enabled: true,
        verifiedEmail: 'a••@example.test',
        offline_identity: {
            state: 'verified',
            owner_key: 'a'.repeat(64),
            proof_expires_at: Date.now() + 3600000,
        },
    };
    const view = render(<AttendeeApp {...props} />);
    await screen.findByText('First owner community');
    view.rerender(
        <AttendeeApp
            {...props}
            offline_identity={{ ...props.offline_identity, owner_key: 'b'.repeat(64) }}
        />
    );
    expect(await screen.findByText('Second owner community')).toBeTruthy();
    expect(screen.queryByText('First owner community')).toBeNull();
});
it('does not let a slow list response make an opened copy unread again', async () => {
    let finishList;
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) => {
        if (String(url).endsWith('?page=1'))
            return {
                ok: true,
                status: 200,
                json: () =>
                    new Promise((resolve) => {
                        finishList = resolve;
                    }),
            };
        return response(
            options?.method === 'POST'
                ? { read: true }
                : { entry: { ...entry, body: 'Already opened copy' } }
        );
    });
    const changed = vi.fn();
    render(
        <InboxList
            tenantSlug="group"
            itemId="message-one"
            context="self"
            onClose={() => {}}
            onChanged={changed}
        />
    );
    await screen.findByText('Already opened copy');
    await waitFor(() => expect(changed).toHaveBeenCalled());
    await act(async () =>
        finishList({ entries: { data: [entry], current_page: 1, last_page: 1 } })
    );
    expect(screen.queryByText('Unread')).toBeNull();
});

it('moves keyboard focus into a message and returns it to the originating list entry', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) =>
        response(
            options?.method === 'POST'
                ? { read: true }
                : String(url).includes('/message-one')
                  ? { entry: { ...entry, body: 'Keyboard accessible copy' } }
                  : { entries: { data: [entry], current_page: 1, last_page: 1 } }
        )
    );
    render(<InboxList tenantSlug="group" name="Group" onClose={() => {}} />);
    const open = await screen.findByRole('button', { name: /Volunteer gathering/ });
    open.focus();
    fireEvent.click(open);
    const back = await screen.findByRole('button', { name: 'Back to messages' });
    await waitFor(() => expect(document.activeElement).toBe(back));
    fireEvent.click(back);
    await waitFor(() =>
        expect(document.activeElement).toBe(
            screen.getByRole('button', { name: /Volunteer gathering/ })
        )
    );
});


it('holds pagination while mark-all commits so a later page cannot restore stale unread rows', async () => {
    let finishMark;
    const fetch = vi.spyOn(globalThis, 'fetch').mockImplementation(async (url, options) => {
        if (options?.method === 'POST') return {
            ok: true, status: 200,
            json: () => new Promise((resolve) => { finishMark = resolve; }),
        };
        return response({ entries: { data: [entry], current_page: 1, last_page: 2 } });
    });
    render(<InboxList tenantSlug="group" name="Group" onClose={() => {}} />);
    await screen.findByText('Unread');
    fireEvent.click(screen.getByRole('button', { name: 'Mark all read' }));
    await waitFor(() => expect(finishMark).toBeTypeOf('function'));
    const more = screen.getByRole('button', { name: 'More messages' });
    expect(more.disabled).toBe(true);
    fireEvent.click(more);
    expect(fetch.mock.calls.some(([url]) => String(url).includes('page=2'))).toBe(false);
    await act(async () => finishMark({ read: true }));
    expect(screen.queryByText('Unread')).toBeNull();
    expect(screen.getByRole('button', { name: 'More messages' }).disabled).toBe(false);
});
