import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import MessageComposer from '@/Pages/Tenant/Community/MessageComposer';
import GroupsPanel from '@/Pages/Tenant/Community/GroupsPanel';
import MessageDetail from '@/Pages/Tenant/Community/MessageDetail';
import { sendJson } from '@/lib/dues';
vi.mock('@/lib/dues', () => ({ sendJson: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }) => <a {...props}>{children}</a>,
    usePage: () => ({ props: { auth: { can: {} } } }),
}));
beforeEach(() => {
    global.route = (name) => name;
    sendJson.mockReset();
});
const props = {
    onClose: vi.fn(),
    onDone: vi.fn(),
    capabilities: { schedule: false, groups: false, finance: false },
    timezone: 'Africa/Accra',
    offsets: ['+00:00'],
};
it('requires a fresh estimate and authority after content changes', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: {
            counts: { email: 2 },
            email_credits_remaining: 50,
            sms_monthly_remaining: null,
            timezone: 'Africa/Accra',
        },
    });
    render(<MessageComposer {...props} />);
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: 'News' } });
    fireEvent.change(screen.getByLabelText('Announcement'), {
        target: { value: 'Friday gathering' },
    });
    expect(screen.getByRole('button', { name: 'Send announcement' }).disabled).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Preview audience' }));
    await screen.findByText(/2 email/);
    fireEvent.click(screen.getByLabelText(/I am authorised/));
    expect(screen.getByRole('button', { name: 'Send announcement' }).disabled).toBe(false);
    fireEvent.change(screen.getByLabelText('Announcement'), {
        target: { value: 'Saturday instead' },
    });
    expect(screen.getByRole('button', { name: 'Send announcement' }).disabled).toBe(true);
    expect(screen.queryByText(/2 email/)).toBeNull();
    expect(screen.queryByRole('option', { name: 'Members with overdue dues' })).toBeNull();
    expect(screen.getByText(/Scheduling is available on Starter/)).toBeTruthy();
});
it('does not let a late preview response approve changed content', async () => {
    let finish;
    sendJson.mockImplementation(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            })
    );
    render(<MessageComposer {...props} />);
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: 'News' } });
    fireEvent.change(screen.getByLabelText('Announcement'), { target: { value: 'First' } });
    fireEvent.click(screen.getByRole('button', { name: 'Preview audience' }));
    fireEvent.change(screen.getByLabelText('Announcement'), { target: { value: 'Second' } });
    finish({ ok: true, body: { counts: { email: 2 } } });
    await waitFor(() =>
        expect(screen.getByRole('button', { name: 'Preview audience' }).disabled).toBe(false)
    );
    expect(screen.queryByText(/2 email/)).toBeNull();
});
it('shows a recoverable groups empty state and dismisses the editor with Escape', async () => {
    sendJson.mockResolvedValue({ ok: true, body: { groups: [], next_page_url: null } });
    render(<GroupsPanel />);
    await screen.findByText('No groups yet');
    fireEvent.click(screen.getByRole('button', { name: 'Create group' }));
    expect(screen.getByLabelText('Group name')).toBeTruthy();
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
});
it('explains uncertain outcomes without offering resend', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: {
            message: {
                id: '1',
                subject: 'News',
                body: 'Hello',
                status: 'partial',
                channels: ['email'],
                audience: { kind: 'all_members' },
            },
            deliveries: {
                data: [{ id: 'd', channel: 'email', status: 'unknown', reason: 'outcome_unknown' }],
            },
        },
    });
    render(
        <MessageDetail
            id="1"
            onClose={vi.fn()}
            onEdit={vi.fn()}
            onChanged={vi.fn()}
            timezone="Africa/Accra"
        />
    );
    await screen.findByText(/Credits remain held/);
    expect(screen.queryByText(/Resend/)).toBeNull();
    expect(screen.queryByRole('link', { name: 'Review uncertain outcomes' })).toBeNull();
});

it('reports preview errors and restores the retry action', async () => {
    sendJson.mockResolvedValue({
        ok: false,
        errors: { channels: 'Not enough SMS credits.' },
        message: 'Validation failed',
    });
    render(<MessageComposer {...props} />);
    fireEvent.change(screen.getByLabelText('Subject'), { target: { value: 'News' } });
    fireEvent.change(screen.getByLabelText('Announcement'), { target: { value: 'Friday' } });
    fireEvent.click(screen.getByRole('button', { name: 'Preview audience' }));
    await screen.findByRole('alert');
    expect(screen.getByText('Not enough SMS credits.')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Send announcement' }).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Preview audience' }).disabled).toBe(false);
});
it('edits a draft and explicitly confirms the organisation timezone before scheduling', async () => {
    sendJson
        .mockResolvedValueOnce({
            ok: true,
            body: {
                counts: { email: 1 },
                scheduled_at: '2027-01-10T10:00:00Z',
                email_credits_remaining: 10,
                timezone: 'Africa/Accra',
            },
        })
        .mockResolvedValueOnce({ ok: true, body: { message: { id: 'draft' } } });
    render(
        <MessageComposer
            {...props}
            capabilities={{ schedule: true, groups: true }}
            draft={{
                id: 'draft',
                subject: 'News',
                body: 'Hello',
                channels: ['email'],
                audience: { kind: 'all_members' },
            }}
        />
    );
    fireEvent.change(screen.getByLabelText('When to send'), { target: { value: 'schedule' } });
    fireEvent.change(screen.getByLabelText('Send date and time · Africa/Accra'), {
        target: { value: '2027-01-10T10:00' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview audience' }));
    await screen.findByText(/1 email/);
    fireEvent.click(screen.getByLabelText(/I am authorised/));
    fireEvent.click(screen.getByRole('button', { name: 'Schedule announcement' }));
    await waitFor(() => expect(props.onDone).toHaveBeenCalled());
    expect(sendJson.mock.calls.at(-1)[0]).toBe('PATCH');
    expect(sendJson.mock.calls.at(-1)[2]).toMatchObject({
        mode: 'send',
        scheduled_at: '2027-01-10T10:00:00+00:00',
        confirm_authority: true,
    });
});
it('adds and removes only the chosen member', async () => {
    sendJson.mockImplementation(async (method, url) => ({
        ok: true,
        body: url.includes('member-options')
            ? { members: { data: [{ id: 'ama', name: 'Ama', status: 'active', selected: false }] } }
            : { groups: [{ id: 'choir', name: 'Choir', members_count: 0 }], members_count: 1 },
    }));
    render(<GroupsPanel />);
    fireEvent.click(await screen.findByRole('button', { name: 'Manage members' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Add', exact: true }));
    await screen.findByRole('button', { name: 'Remove', exact: true });
    expect(sendJson.mock.calls.find(([method]) => method === 'PATCH')[2]).toEqual({
        action: 'attach',
        member_ids: ['ama'],
    });
    fireEvent.click(screen.getByRole('button', { name: 'Remove', exact: true }));
    await screen.findByRole('button', { name: 'Add', exact: true });
    expect(sendJson.mock.calls.filter(([method]) => method === 'PATCH').at(-1)[2]).toEqual({
        action: 'detach',
        member_ids: ['ama'],
    });
});
it('requires confirmation before cancelling a scheduled announcement', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: {
            message: {
                id: 'scheduled',
                subject: 'News',
                body: 'Hello',
                status: 'scheduled',
                channels: ['email'],
                audience: { kind: 'all_members' },
            },
            deliveries: { data: [] },
        },
    });
    render(
        <MessageDetail
            id="scheduled"
            onClose={vi.fn()}
            onEdit={vi.fn()}
            onChanged={vi.fn()}
            timezone="Africa/Accra"
        />
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel announcement' }));
    expect(sendJson.mock.calls.filter(([method]) => method === 'POST')).toHaveLength(0);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm cancellation' }));
    await waitFor(() =>
        expect(sendJson.mock.calls.filter(([method]) => method === 'POST')).toHaveLength(1)
    );
});
it('shows the review link only when the server grants an operator URL', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: {
            message: {
                id: '1',
                subject: 'News',
                body: 'Hello',
                status: 'partial',
                channels: ['email'],
                audience: { kind: 'all_members' },
            },
            deliveries: { data: [] },
        },
    });
    render(
        <MessageDetail
            id="1"
            onClose={vi.fn()}
            onEdit={vi.fn()}
            onChanged={vi.fn()}
            timezone="Africa/Accra"
            reconciliationUrl="https://example.test/messages/reconciliation"
        />
    );
    expect(
        (await screen.findByRole('link', { name: 'Review uncertain outcomes' })).getAttribute(
            'href'
        )
    ).toBe('https://example.test/messages/reconciliation');
});
it('does not promise future recipient selection after an empty completed send', async () => {
    sendJson.mockResolvedValue({
        ok: true,
        body: {
            message: {
                id: 'empty',
                subject: 'News',
                body: 'Hello',
                status: 'completed',
                channels: ['email'],
                audience: { kind: 'groups' },
            },
            deliveries: { data: [] },
        },
    });
    render(
        <MessageDetail
            id="empty"
            onClose={vi.fn()}
            onEdit={vi.fn()}
            onChanged={vi.fn()}
            timezone="Africa/Accra"
        />
    );
    await screen.findByText('Processing finished');
    expect(screen.queryByText('Recipients will be selected when processing begins.')).toBeNull();
});
