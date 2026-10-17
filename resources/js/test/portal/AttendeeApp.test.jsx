import { render, screen, fireEvent, within, waitFor } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeApp from '@/Pages/Public/Events/AttendeePortal/AttendeeApp';
vi.mock('@/Layouts/PublicLayout', () => ({ default: ({ children }) => children }));
it('keeps certificate, abstract and attendance readers behind the Tickets section', async () => {
    window.history.replaceState({}, '', '/my?section=tickets');
    const fetcher = vi
        .spyOn(globalThis, 'fetch')
        .mockImplementation(async (url) => ({
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
