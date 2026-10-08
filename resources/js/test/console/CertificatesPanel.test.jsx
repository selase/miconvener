import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import CertificatesPanel from '@/Pages/Tenant/Events/panels/CertificatesPanel';
import csrfFetch, { csrfFetchFormData } from '@/lib/csrfFetch';
const permissions = vi.hoisted(() => ({ current: {} }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { can: permissions.current } } }),
}));
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn(), csrfFetchFormData: vi.fn() }));
const certificate = {
    id: 'cert',
    uuid: 'uuid',
    recipient_name: 'Ada Mensah',
    role: 'delegate',
    verification_code: 'CERT-1',
};
const data = (page = 1, rows = [certificate]) => ({
    templates: [
        {
            id: 'template',
            role: 'delegate',
            title: 'Attendance',
            body_template: 'Thank you',
            layout: {},
        },
    ],
    certificates: {
        data: rows,
        current_page: page,
        last_page: 2,
        total: 21,
        from: page === 1 ? 1 : 21,
        to: page === 1 ? 20 : 21,
    },
});
const ok = (value) => ({ ok: true, json: async () => value });
const failures = [
    [
        'forbidden',
        () =>
            Promise.resolve({
                ok: false,
                status: 403,
                json: async () => ({ message: 'Access denied' }),
            }),
    ],
    [
        'validation',
        () =>
            Promise.resolve({
                ok: false,
                status: 422,
                json: async () => ({ errors: { title: ['Please use a shorter title.'] } }),
            }),
    ],
    ['server', () => Promise.resolve({ ok: false, status: 500, json: async () => ({}) })],
    [
        'html',
        () =>
            Promise.resolve({
                ok: false,
                status: 500,
                json: async () => {
                    throw new Error('Unexpected token');
                },
            }),
    ],
    ['network', () => Promise.reject(new Error('Connection lost'))],
];
beforeEach(() => {
    permissions.current = {
        read_certificate: true,
        create_certificate: true,
        issue_certificates: true,
        update_certificate: true,
        delete_certificate: true,
    };
    csrfFetch.mockReset();
    csrfFetchFormData.mockReset();
    csrfFetch.mockResolvedValue(ok(data()));
    URL.createObjectURL = vi.fn(() => 'blob:pdf');
    URL.revokeObjectURL = vi.fn();
});
it('hides write actions from a certificate reader', async () => {
    permissions.current = { read_certificate: true };
    render(<CertificatesPanel event={{ id: 'event' }} />);
    await screen.findByText('Ada Mensah');
    expect(screen.queryByRole('button', { name: 'Issue Certificates' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Customize' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Revoke Certificate' })).toBeNull();
});
it.each(failures)('shows %s load failures and supports retry', async (_, failure) => {
    csrfFetch.mockImplementationOnce(failure);
    render(<CertificatesPanel event={{ id: 'event' }} />);
    expect(await screen.findByRole('alert')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Retry loading certificates' }));
    await screen.findByText('Ada Mensah');
    expect(screen.queryByRole('alert')).toBeNull();
});
it.each(failures)(
    'keeps the edited draft and reports %s save failure in its dialog',
    async (_, failure) => {
        csrfFetchFormData.mockImplementationOnce(failure);
        render(<CertificatesPanel event={{ id: 'event' }} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Customize' }));
        fireEvent.change(screen.getByLabelText('Certificate title'), {
            target: { value: 'Edited draft' },
        });
        fireEvent.submit(screen.getByLabelText('Certificate title').closest('form'));
        const dialog = screen.getByRole('dialog');
        await within(dialog).findByRole('alert');
        expect(screen.getByLabelText('Certificate title').value).toBe('Edited draft');
    }
);
it('requests pagination, resets role and search, and moves back after the last later-page certificate is revoked', async () => {
    csrfFetch.mockImplementation((url, options) => {
        if (options?.method === 'DELETE') return Promise.resolve(ok({}));
        return Promise.resolve(ok(data(url.includes('page=2') ? 2 : 1)));
    });
    render(<CertificatesPanel event={{ id: 'event' }} />);
    await screen.findByText('Ada Mensah');
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(csrfFetch.mock.calls.at(-1)[0]).toContain('page=2'));
    await screen.findByText(/21–21 of 21/);
    fireEvent.click(screen.getByRole('button', { name: 'Revoke Certificate' }));
    fireEvent.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Revoke Certificate' })
    );
    await waitFor(() => expect(csrfFetch.mock.calls.at(-1)[0]).toContain('page=1'));
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText(/21–21 of 21/);
    fireEvent.click(screen.getByRole('button', { name: 'speakers' }));
    await waitFor(() => expect(csrfFetch.mock.calls.at(-1)[0]).toContain('page=1'));
    expect(csrfFetch.mock.calls.at(-1)[0]).toContain('role=speaker');
    fireEvent.change(screen.getByPlaceholderText('Search recipient or code...'), {
        target: { value: 'Ada' },
    });
    fireEvent.keyDown(screen.getByPlaceholderText('Search recipient or code...'), { key: 'Enter' });
    await waitFor(() => expect(csrfFetch.mock.calls.at(-1)[0]).toContain('search=Ada'));
});
it.each(failures)('reports %s issuance failure without losing recipients', async (_, failure) => {
    render(<CertificatesPanel event={{ id: 'event' }} />);
    await screen.findByText('Ada Mensah');
    fireEvent.click(screen.getByRole('button', { name: 'Issue Certificates' }));
    fireEvent.change(screen.getByLabelText('Target Recipient Cohort *'), {
        target: { value: 'custom' },
    });
    fireEvent.change(screen.getByLabelText('Recipient Name *'), { target: { value: 'Grace' } });
    fireEvent.change(screen.getByLabelText('Recipient Email *'), {
        target: { value: 'grace@example.com' },
    });
    csrfFetch.mockImplementationOnce(failure);
    fireEvent.submit(screen.getByLabelText('Recipient Name *').closest('form'));
    await within(screen.getByRole('dialog')).findByRole('alert');
    expect(screen.getByLabelText('Recipient Name *').value).toBe('Grace');
});
it.each(failures)('reports %s PDF download errors', async (_, failure) => {
    render(<CertificatesPanel event={{ id: 'event' }} />);
    await screen.findByText('Ada Mensah');
    csrfFetch.mockImplementationOnce(failure);
    fireEvent.click(screen.getByRole('button', { name: 'Download PDF for Ada Mensah' }));
    await screen.findByRole('alert');
    expect(URL.createObjectURL).not.toHaveBeenCalled();
});

it.each(failures)('keeps revoke confirmation open after a %s failure', async (_, failure) => {
    render(<CertificatesPanel event={{ id: 'event' }} />);
    await screen.findByText('Ada Mensah');
    fireEvent.click(screen.getByRole('button', { name: 'Revoke Certificate' }));
    csrfFetch.mockImplementationOnce(failure);
    fireEvent.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Revoke Certificate' })
    );
    await within(screen.getByRole('dialog')).findByRole('alert');
    expect(screen.getByText('Ada Mensah')).toBeTruthy();
    expect(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Revoke Certificate' })
            .disabled
    ).toBe(false);
});
it('rejects an HTML login response instead of downloading it as a PDF', async () => {
    render(<CertificatesPanel event={{ id: 'event' }} />);
    await screen.findByText('Ada Mensah');
    csrfFetch.mockResolvedValueOnce({
        ok: true,
        headers: { get: () => 'text/html' },
        blob: vi.fn(),
    });
    fireEvent.click(screen.getByRole('button', { name: 'Download PDF for Ada Mensah' }));
    await screen.findByRole('alert');
    expect(URL.createObjectURL).not.toHaveBeenCalled();
});
