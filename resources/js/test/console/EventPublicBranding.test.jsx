import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import Panel from '@/Pages/Tenant/Events/EventPublicBrandingPanel';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));

it('lets managers replace organisation socials with event pages or posts and save their choices', async () => {
    csrfFetch.mockResolvedValue({
        ok: true,
        json: async () => ({
            show_managing_organisation: false,
            social_links_mode: 'event',
            social_links: { instagram: 'https://instagram.com/p/event123' },
        }),
    });
    render(<Panel event={{ id: 'e' }} canEdit />);
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.change(screen.getByLabelText('Social links to display'), {
        target: { value: 'event' },
    });
    fireEvent.change(screen.getByLabelText('Event Instagram link'), {
        target: { value: 'https://instagram.com/p/event123' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save public page options' }));
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('saved'));
    expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({
        show_managing_organisation: false,
        social_links_mode: 'event',
        social_links: { instagram: 'https://instagram.com/p/event123' },
    });
});
it('retains edits and exposes server errors without claiming success', async () => {
    csrfFetch.mockResolvedValue({
        ok: false,
        json: async () => ({ errors: { 'social_links.website': ['Use a valid link.'] } }),
    });
    render(<Panel event={{ id: 'e', social_links_mode: 'event' }} canEdit />);
    fireEvent.change(screen.getByLabelText('Event Website link'), {
        target: { value: 'https://example.org/event' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save public page options' }));
    await waitFor(() =>
        expect(screen.getByRole('alert').textContent).toContain('Use a valid link.')
    );
    expect(screen.getByLabelText('Event Website link').value).toBe('https://example.org/event');
    expect(screen.queryByRole('status')).toBeNull();
});
it('keeps the controls out of read-only views', () => {
    const { container } = render(<Panel event={{ id: 'e' }} canEdit={false} />);
    expect(container.innerHTML).toBe('');
});
