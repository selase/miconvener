import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import CheckInPanel from '@/Pages/Tenant/Events/CheckInPanel';
import { ToastProvider } from '@/Components/Console/Toast';

vi.mock('html5-qrcode', () => ({
    Html5Qrcode: vi.fn(function Html5Qrcode() {
        return { start: () => new Promise(() => {}), stop: async () => {} };
    }),
}));

function renderWithDoor(door) {
    return render(
        <ToastProvider>
            <CheckInPanel
                event={{ id: 'e1' }}
                door={door}
                scanUrl=""
                searchUrl=""
                checkInUrlFor={() => ''}
            />
        </ToastProvider>
    );
}

async function searchAndCheckIn(text) {
    fireEvent.click(screen.getByText('Manual search'));
    fireEvent.change(screen.getByPlaceholderText(/Search by name/), { target: { value: text } });
    fireEvent.click(await screen.findByText('Check in'));
}

describe('CheckInPanel with a staff door', () => {
    it('searches and checks in through the door, showing the holder name large', async () => {
        const door = {
            scan: vi.fn(),
            search: vi.fn(async () => [
                { id: 'r1', full_name: 'Ama Owusu', email: '', ticket_code: 'EVT-1' },
            ]),
            checkIn: vi.fn(async () => ({
                message: 'Ama Owusu checked in (offline).',
                offline: true,
                registration: { id: 'r1', full_name: 'Ama Owusu' },
            })),
        };
        renderWithDoor(door);

        await searchAndCheckIn('Ama');

        expect(door.search).toHaveBeenCalledWith('Ama');
        expect(door.checkIn).toHaveBeenCalledWith('r1');
        expect((await screen.findByTestId('admitted-name')).textContent).toBe('Ama Owusu');
    });

    it('shows a refusal in red, without a name', async () => {
        const door = {
            scan: vi.fn(),
            search: vi.fn(async () => [
                { id: 'r9', full_name: 'Unknown', email: '', ticket_code: 'T' },
            ]),
            checkIn: vi.fn(async () => ({ message: 'Ticket not recognised.', refused: true })),
        };
        renderWithDoor(door);

        await searchAndCheckIn('Unk');

        const banner = (await screen.findAllByText('Ticket not recognised.'))[0].closest(
            'div[class*="rounded-md"]'
        );
        expect(banner.className).toContain('danger');
        expect(screen.queryByTestId('admitted-name')).toBeNull();
    });
});
