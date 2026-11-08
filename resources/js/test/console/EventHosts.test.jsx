import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import EventHostsPanel from '@/Pages/Tenant/Events/EventHostsPanel';
import EventHostCredits from '@/Pages/Public/Events/EventHostCredits';
import { csrfFetchFormData } from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ csrfFetchFormData: vi.fn() }));

it('shows all ordered names without inventing logos or tenant follow targets', () => {
    const hosts = [
        { id: 'a', name: 'Ada', type: 'individual', website_url: 'https://example.com' },
        { id: 'b', name: 'Friends '.repeat(20), type: 'organisation' },
    ];
    render(<EventHostCredits hosts={hosts} />);
    expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
        'Ada',
        'Friends '.repeat(20),
    ]);
    expect(screen.getByRole('link', { name: 'Ada' }).getAttribute('rel')).toContain('noopener');
    expect(screen.queryByRole('img')).toBeNull();
});

it('adds, reorders, removes and saves an explicit host list with its original version', async () => {
    csrfFetchFormData.mockResolvedValue({
        ok: true,
        json: async () => ({ version: 3, hosts: [] }),
    });
    render(
        <EventHostsPanel
            event={{
                id: 'e',
                hosts_version: 2,
                hosts: [{ id: 'a', name: 'Ada', type: 'individual' }],
            }}
            canEdit
        />
    );
    fireEvent.click(screen.getByRole('button', { name: 'Add host' }));
    fireEvent.change(screen.getByLabelText('Host 2 name'), { target: { value: 'Friends' } });
    fireEvent.click(screen.getByRole('button', { name: 'Move host 2 up' }));
    expect(screen.getByLabelText('Host 1 name').value).toBe('Friends');
    fireEvent.click(screen.getByRole('button', { name: 'Remove host 2' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save hosts' }));
    await waitFor(() => expect(csrfFetchFormData).toHaveBeenCalled());
    const data = csrfFetchFormData.mock.calls[0][1];
    expect(data.get('version')).toBe('2');
    expect(data.get('hosts[0][name]')).toBe('Friends');
    expect(data.has('hosts[0][logo_path]')).toBe(false);
});

it('hides editing from readers and explains the tenant fallback', () => {
    render(<EventHostsPanel event={{ id: 'e', hosts: [] }} canEdit={false} />);
    expect(screen.queryByRole('button')).toBeNull();
    expect(screen.getByText(/managing organisation appears/)).toBeTruthy();
});

it('keeps unsaved edits and exposes stale save errors with a reload action', async () => {
    csrfFetchFormData.mockResolvedValue({
        ok: false,
        status: 409,
        json: async () => ({ message: 'Hosts changed in another session.' }),
    });
    render(<EventHostsPanel event={{ id: 'e', hosts_version: 0, hosts: [] }} canEdit />);
    fireEvent.click(screen.getByRole('button', { name: 'Save hosts' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Hosts changed'));
    expect(screen.getByRole('button', { name: 'Reload hosts' })).toBeTruthy();
});
