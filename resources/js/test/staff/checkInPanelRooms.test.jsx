import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import CheckInPanel from '@/Pages/Tenant/Events/CheckInPanel';
import { ToastProvider } from '@/Components/Console/Toast';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('html5-qrcode', () => ({
    Html5Qrcode: vi.fn(function Html5Qrcode() {
        return { start: () => new Promise(() => {}), stop: async () => {} };
    }),
}));

vi.mock('@/lib/csrfFetch', () => ({
    default: vi.fn(async (url) => ({
        ok: true,
        status: 200,
        json: async () =>
            String(url).includes('search')
                ? [{ id: 'r1', full_name: 'Ama Owusu', email: '', ticket_code: 'EVT-1' }]
                : {
                      message: 'Ama Owusu checked in.',
                      action: 'check_in',
                      registration: { id: 'r1', full_name: 'Ama Owusu' },
                  },
    })),
}));

describe('CheckInPanel rooms on a staff link', () => {
    it('scans into a room through the address the staff link provides', async () => {
        const door = { scan: vi.fn(), search: vi.fn(), checkIn: vi.fn() };
        render(
            <ToastProvider>
                <CheckInPanel
                    event={{ id: 'e1' }}
                    door={door}
                    sessions={[
                        {
                            id: 's1',
                            title: 'Workshop B',
                            starts_at: '2026-11-02T10:00:00Z',
                            capacity: 30,
                        },
                    ]}
                    sessionScanUrlFor={(sessionId) => `/staff/tok/rooms/${sessionId}/scan`}
                    scanUrl="/staff/tok/checkin/scan"
                    searchUrl="/staff/tok/checkin/search"
                    checkInUrlFor={() => ''}
                />
            </ToastProvider>
        );

        fireEvent.click(screen.getByText('Breakout Room'));
        fireEvent.click(screen.getByText('Manual search'));
        fireEvent.change(screen.getByPlaceholderText(/Search by name/), {
            target: { value: 'Ama' },
        });
        fireEvent.click(await screen.findByText('Check in'));

        await vi.waitFor(() =>
            expect(
                csrfFetch.mock.calls.some(([url]) =>
                    String(url).startsWith('/staff/tok/rooms/s1/scan')
                )
            ).toBe(true)
        );
        // Room scanning is online: the offline door is not involved.
        expect(door.checkIn).not.toHaveBeenCalled();
    });
});
