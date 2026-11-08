import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import AttendeeImportPanel from '@/Pages/Tenant/Events/AttendeeImportPanel';

afterEach(() => vi.restoreAllMocks());
it('shows a private upload, frozen preview and explicit no-mail confirmation', async () => {
    vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, json: async () => ({ imports: [] }) })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                id: 'batch',
                status: 'preview',
                rows: [
                    {
                        id: 'row',
                        line: 2,
                        status: 'valid',
                        data: {
                            full_name: 'Ada',
                            email: 'ada@example.test',
                            payment_status: 'paid_elsewhere',
                            external_amount: 1234,
                            currency: 'GHS',
                        },
                        errors: [],
                    },
                ],
                counts: { valid: 1 },
                invitations: {},
            }),
        });
    render(
        <AttendeeImportPanel
            event={{ id: 'event', status: 'draft', capacity: 20, ticket_types: [] }}
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Import attendees' }));
    expect(screen.getByRole('link', { name: 'Download CSV template' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('CSV file'), {
        target: { files: [new File(['full_name,email\nAda,ada@example.test'], 'attendees.csv')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview CSV' }));
    await screen.findByText('ada@example.test');
    expect(screen.getByText(/Paid elsewhere/)).toBeTruthy();
    expect(screen.getByText(/No invitations sent/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Confirm 1 attendee' })).toBeTruthy();
});
it('displays server errors and keeps submission recoverable', async () => {
    vi.spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, json: async () => ({ imports: [] }) })
        .mockResolvedValueOnce({
            ok: false,
            json: async () => ({ errors: { file: ['Malformed CSV.'] } }),
        });
    render(<AttendeeImportPanel event={{ id: 'event' }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Import attendees' }));
    fireEvent.change(screen.getByLabelText('CSV file'), {
        target: { files: [new File(['bad'], 'bad.csv')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview CSV' }));
    await screen.findByRole('alert');
    expect(screen.getByRole('alert').textContent).toContain('Malformed CSV.');
    expect(screen.getByRole('button', { name: 'Preview CSV' }).disabled).toBe(false);
});

it('reopens a stored preview with a non-null operation key for confirmation', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                imports: [{ id: 'saved', status: 'preview', created_at: '2026-11-08' }],
            }),
        })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                id: 'saved',
                status: 'preview',
                rows: [
                    {
                        id: 'saved-row',
                        line: 2,
                        status: 'valid',
                        data: { full_name: 'Ada', email: 'ada@example.test' },
                        errors: [],
                    },
                ],
                counts: { valid: 1 },
                invitations: {},
            }),
        })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                id: 'saved',
                status: 'completed',
                rows: [],
                counts: {},
                invitations: {},
            }),
        });
    render(<AttendeeImportPanel event={{ id: 'event', status: 'published' }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Import attendees' }));
    fireEvent.click(await screen.findByRole('button', { name: /preview/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Confirm 1 attendee' }));
    await screen.findByText('completed');
    expect(JSON.parse(fetch.mock.calls[2][1].body).operation_key).toMatch(/^[0-9a-f-]{36}$/);
});

it('reviews the current recipient and submits the matching frozen revision', async () => {
    const batch = {
        id: 'saved',
        status: 'completed',
        rows: [
            {
                id: 'row',
                line: 2,
                status: 'created',
                registration_id: 'registration',
                data: { full_name: 'Ada', email: 'old@example.test' },
                invitation_recipient: 'current@example.test',
                invitation_revision: 'a'.repeat(64),
                errors: [],
            },
        ],
        counts: { created: 1 },
        invitations: {},
    };
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                imports: [{ id: 'saved', status: 'completed', created_at: '2026-11-08' }],
            }),
        })
        .mockResolvedValueOnce({ ok: true, json: async () => batch })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ...batch, invitations: { pending: 1 } }),
        });
    render(<AttendeeImportPanel event={{ id: 'event', status: 'published' }} />);
    fireEvent.click(screen.getByRole('button', { name: 'Import attendees' }));
    fireEvent.click(await screen.findByRole('button', { name: /completed/ }));
    fireEvent.click(await screen.findByLabelText('Invite current@example.test'));
    fireEvent.click(screen.getByRole('button', { name: 'Review selected invitations' }));
    expect(screen.getByRole('list').textContent).toContain('current@example.test');
    expect(screen.getByRole('list').textContent).not.toContain('old@example.test');
    fireEvent.click(screen.getByRole('button', { name: 'Send 1 access invitation' }));
    await screen.findByText('1 pending');
    expect(JSON.parse(fetch.mock.calls[2][1].body).revisions.registration).toBe('a'.repeat(64));
});
