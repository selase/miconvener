import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import MyTicketPanel from '@/Pages/Public/Events/AttendeePortal/panels/MyTicketPanel';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const event = { id: 'evt-1', slug: 'congress', name: 'Congress' };

const registration = (overrides = {}) => ({
    id: 'reg-1',
    full_name: 'Ama Serwa',
    email: 'ama@stme.org',
    status: 'confirmed',
    ticket_code: 'EVT-AAAA-111',
    checked_in: false,
    self_check_in_available: true,
    ...overrides,
});

describe('marking yourself present', () => {
    beforeEach(() => {
        csrfFetch.mockReset();
        window.history.pushState({}, '', '/my/events/reg-1');
    });

    it('offers the button while check-in is available', () => {
        render(<MyTicketPanel event={event} registration={registration()} />);

        expect(screen.getByRole('button', { name: /I'm here/ })).toBeTruthy();
    });

    it('records presence and says so', async () => {
        csrfFetch.mockResolvedValue({ ok: true, json: async () => ({ checked_in: true, message: "You're checked in. Enjoy the event." }) });
        render(<MyTicketPanel event={event} registration={registration()} />);

        fireEvent.click(screen.getByRole('button', { name: /I'm here/ }));

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        expect(csrfFetch.mock.calls[0][0]).toBe('/my/events/reg-1/check-in');

        // The button is spent once it has worked, and the state it leaves is
        // the thing that earns the right to answer a poll.
        await screen.findByText('Checked in');
        expect(screen.queryByRole('button', { name: /I'm here/ })).toBeNull();
    });

    it("shows the server's reason when check-in is not open", async () => {
        csrfFetch.mockResolvedValue({
            ok: false,
            json: async () => ({ message: 'Check-in is open while the event is running.' }),
        });
        render(<MyTicketPanel event={event} registration={registration()} />);

        fireEvent.click(screen.getByRole('button', { name: /I'm here/ }));

        await screen.findByText('Check-in is open while the event is running.');
        expect(screen.queryByText('Checked in')).toBeNull();
    });

    it('offers nothing when check-in is not available, and says so when already present', () => {
        const { unmount } = render(
            <MyTicketPanel event={event} registration={registration({ self_check_in_available: false })} />
        );
        expect(screen.queryByRole('button', { name: /I'm here/ })).toBeNull();
        unmount();

        render(
            <MyTicketPanel
                event={event}
                registration={registration({ checked_in: true, status: 'checked_in' })}
            />
        );
        expect(screen.getByText('Checked in')).toBeTruthy();
        expect(screen.queryByRole('button', { name: /I'm here/ })).toBeNull();
    });
});
