import { render, screen, waitFor, act } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import SessionOccupancyPanel from '@/Pages/Tenant/Events/panels/SessionOccupancyPanel';

const { fetchMock } = vi.hoisted(() => ({ fetchMock: vi.fn() }));
vi.mock('@/lib/csrfFetch', () => ({ default: fetchMock }));

beforeEach(() => {
    fetchMock.mockReset();
    window.Echo = undefined;
});

it('refreshes through protected HTTP and clears room data when membership is revoked', async () => {
    let incoming;
    const channel = {
        listen: vi.fn((name, callback) => {
            incoming = callback;
        }),
        stopListening: vi.fn(),
    };
    window.Echo = { private: vi.fn(() => channel) };
    fetchMock.mockResolvedValueOnce({
        ok: true,
        json: async () => ({
            sessions: [
                {
                    id: 'room1',
                    title: 'Private Room',
                    status: 'available',
                    capacity: 10,
                    live_headcount: 1,
                    occupancy_percentage: 10,
                },
            ],
            summary: {},
        }),
    });
    const view = render(<SessionOccupancyPanel event={{ id: 'event1' }} />);
    expect(await screen.findByText('Private Room')).toBeTruthy();
    fetchMock.mockResolvedValue({
        ok: false,
        status: 403,
        json: async () => ({ message: 'Forbidden' }),
    });
    await act(async () => incoming({ attendee_name: 'Never trust socket data' }));
    await waitFor(() => expect(screen.queryByText('Private Room')).toBeNull());
    expect(screen.queryByText('Never trust socket data')).toBeNull();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    view.unmount();
    window.Echo = undefined;
});
