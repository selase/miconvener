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
