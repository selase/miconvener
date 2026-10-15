import { afterEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import ImportMembers from '@/Pages/Tenant/Community/ImportMembers';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
afterEach(() => vi.restoreAllMocks());

const preview = {
    token: 'preview-token',
    summary: { new: 1, existing: 1, duplicate: 0, error: 1 },
    rows: [
        {
            line: 2,
            name: 'Ama',
            phone: '+233241234567',
            email: 'ama@example.test',
            membership_type: 'Regular',
            status: 'new',
            errors: [],
        },
        {
            line: 3,
            name: 'Kojo',
            email: 'kojo@example.test',
            membership_type: 'Regular',
            status: 'existing',
            errors: [],
        },
        {
            line: 4,
            name: 'Bad Row',
            membership_type: 'Missing',
            status: 'error',
            errors: ['Choose an active membership type.'],
        },
    ],
};

it('previews without saving then filters errors and confirms the selected policy once', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, json: async () => preview })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                created: 1,
                updated: 1,
                skipped: 0,
                errors: 1,
                rows: [
                    {
                        line: 4,
                        status: 'error',
                        errors: ['Membership type changed after preview.'],
                    },
                ],
            }),
        });
    render(<ImportMembers />);
    expect(screen.getByText(/Nothing is saved until/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Download CSV template' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Spreadsheet'), {
        target: { files: [new File(['a'], 'members.csv', { type: 'text/csv' })] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview spreadsheet' }));
    await screen.findByText('Bad Row');
    expect(screen.getByText('+233241234567')).toBeTruthy();
    expect(screen.getByText('ama@example.test')).toBeTruthy();
    expect(screen.getByText('3 rows · Page 1 of 1')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Show rows'), { target: { value: 'duplicate' } });
    expect(screen.getByText('0 rows · Page 1 of 1')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Show rows'), { target: { value: 'error' } });
    expect(screen.queryByText('Ama')).toBeNull();
    expect(screen.getByText('Choose an active membership type.')).toBeTruthy();
    expect(screen.getByText('1 row · Page 1 of 1')).toBeTruthy();
    fireEvent.click(screen.getByLabelText('Update matching members'));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await screen.findByText(/1 added/);
    expect(screen.getByRole('status').textContent).toContain('1 error');
    expect(screen.getByRole('status').textContent).not.toContain('1 errors');
    expect(screen.getByText('Membership type changed after preview.')).toBeTruthy();
    expect(screen.getByText('1 row · Page 1 of 1')).toBeTruthy();
    expect(JSON.parse(fetch.mock.calls[1][1].body).existing_policy).toBe('update');
    expect(screen.queryByRole('button', { name: 'Confirm import' })).toBeNull();
    fetch.mockRestore();
});

it.each([0, 1, 2])('uses correct grammar for %i import errors', async (errors) => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({ ok: true, json: async () => preview })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ created: 0, updated: 0, skipped: 0, errors, rows: [] }),
        });
    render(<ImportMembers />);
    fireEvent.change(screen.getByLabelText('Spreadsheet'), {
        target: { files: [new File(['a'], 'members.csv')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview spreadsheet' }));
    await screen.findByText('Bad Row');
    fireEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await screen.findByText(/0 added/);
    const message = screen.getByRole('status').textContent;
    expect(message).toContain(`${errors} ${errors === 1 ? 'error' : 'errors'}`);
    if (errors === 1) {
        expect(message).not.toContain('1 errors');
    }
    fetch.mockRestore();
});

it('shows actionable expired-preview and validation errors', async () => {
    const fetch = vi
        .spyOn(globalThis, 'fetch')
        .mockResolvedValueOnce({
            ok: false,
            status: 422,
            json: async () => ({ errors: { file: ['Use the template headers.'] } }),
        })
        .mockResolvedValueOnce({ ok: true, json: async () => preview })
        .mockResolvedValueOnce({
            ok: false,
            status: 410,
            json: async () => ({ message: 'This import has already been used' }),
        });
    render(<ImportMembers />);
    fireEvent.change(screen.getByLabelText('Spreadsheet'), {
        target: { files: [new File(['a'], 'members.csv')] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Preview spreadsheet' }));
    await waitFor(() =>
        expect(screen.getByRole('alert').textContent).toContain('Use the template headers.')
    );
    fireEvent.click(screen.getByRole('button', { name: 'Preview spreadsheet' }));
    await screen.findByText('Bad Row');
    fireEvent.click(screen.getByRole('button', { name: 'Confirm import' }));
    await waitFor(() =>
        expect(screen.getByRole('alert').textContent).toContain('Preview the spreadsheet again')
    );
    expect(screen.queryByRole('button', { name: 'Confirm import' })).toBeNull();
    fetch.mockRestore();
});
