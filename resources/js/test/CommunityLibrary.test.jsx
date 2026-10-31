import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Library from '@/Pages/Tenant/Community/Library';
import LibraryPanel from '@/Pages/Public/Community/LibraryPanel';

vi.mock('@/Layouts/ConsoleLayout', () => ({ default: ({ children }) => children }));
const request = vi.hoisted(() => vi.fn());
vi.mock('@/lib/community', () => ({ communityRequest: request }));

it('shows retained documents after downgrade while refusing a new publication', () => {
    render(
        <Library
            items={{
                data: [
                    {
                        id: 'doc',
                        title: 'Constitution',
                        state: 'published',
                        kind: 'document',
                        plain_text: '',
                        audience: { kind: 'all_members' },
                    },
                ],
                links: [],
            }}
            groups={[]}
            canPublish={false}
        />
    );
    expect(screen.getByText('Constitution')).toBeTruthy();
    expect(screen.getByText(/Existing documents remain available/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Add document' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Withdraw' })).toBeTruthy();
});

it('opens a private document with plain text and its authorised file link', async () => {
    request.mockResolvedValueOnce({
        organisation: { name: 'Teacher Association' },
        entries: { data: [{ id: 'doc', title: 'Constitution', description: 'Our rules' }] },
        contexts: [],
    });
    request.mockResolvedValueOnce({
        item: {
            id: 'doc',
            title: 'Constitution',
            plain_text: '<script>never executed</script>',
            file: {
                url: '/my/communities/teachers/library/doc/file',
                edition: '2026 edition',
                mime: 'application/pdf',
                size: 1200,
            },
        },
    });
    render(<LibraryPanel tenantSlug="teachers" onExpired={vi.fn()} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Constitution' }));
    expect(await screen.findByText('<script>never executed</script>')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Open document' }).getAttribute('href')).toBe(
        '/my/communities/teachers/library/doc/file'
    );
});

it('clears private content and requests verification when the proof expires', async () => {
    request.mockRejectedValue(Object.assign(new Error('Verify again'), { status: 401 }));
    const expired = vi.fn();
    render(<LibraryPanel tenantSlug="teachers" onExpired={expired} />);
    await waitFor(() => expect(expired).toHaveBeenCalled());
    expect(screen.queryByText('Constitution')).toBeNull();
});

it('labels representative access when a document is opened directly', async () => {
    request.mockResolvedValueOnce({
        item: { id: 'doc', title: 'Constitution', plain_text: 'Community rules' },
        contexts: [{ id: 'member', name: 'Ama Mensah', role: 'representative' }],
    });
    render(<LibraryPanel tenantSlug="teachers" itemId="doc" onExpired={vi.fn()} />);
    expect(
        await screen.findByText('Reading as an approved representative for Ama Mensah.')
    ).toBeTruthy();
});
