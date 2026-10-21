import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import RoomsPanel from '@/Pages/Public/Community/RoomsPanel';

const request = vi.hoisted(() => vi.fn());
vi.mock('@/lib/community', () => ({ communityRequest: request }));

const today = new Date();
today.setHours(0, 0, 0, 0);
const iso = (date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const nine = new Date(today);
nine.setHours(9, 0, 0, 0);
const eleven = new Date(today);
eleven.setHours(11, 0, 0, 0);
const week = {
    organisation: 'UGMC',
    from: iso(today),
    to: iso(new Date(today.getTime() + 6 * 86400000)),
    rooms: [
        {
            id: 'room-1',
            title: 'Council Chamber',
            audience: 'staff',
            requires_approval: true,
            busy: [
                {
                    starts_at: nine.toISOString(),
                    ends_at: eleven.toISOString(),
                    status: 'confirmed',
                },
            ],
        },
        {
            id: 'room-2',
            title: 'Open Hall',
            audience: 'public',
            requires_approval: false,
            busy: [],
        },
    ],
    mine: [],
};

beforeEach(() => request.mockClear());

it('lists the rooms with their busy times and marks staff-only rooms', async () => {
    request.mockResolvedValue(week);
    render(<RoomsPanel tenantSlug="ugmc" />);

    expect(await screen.findByText('Council Chamber')).toBeTruthy();
    expect(screen.getByText('Staff only')).toBeTruthy();
    expect(screen.getByText(/Busy 09:00-11:00/)).toBeTruthy();
    expect(screen.getAllByText('Free all day').length).toBeGreaterThan(0);
    expect(screen.getByText('Free for staff.')).toBeTruthy();
});

it('sends a request and tells the person it is waiting for approval', async () => {
    request.mockResolvedValueOnce(week);
    request.mockResolvedValueOnce({ booking: { id: 'b1', status: 'pending_approval' } });
    request.mockResolvedValue(week);
    render(<RoomsPanel tenantSlug="ugmc" />);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Request this room' }))[0]);
    expect(screen.getByText('A facility manager will approve your request.')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Starts'), { target: { value: '2030-01-10T09:00' } });
    fireEvent.change(screen.getByLabelText('Ends'), { target: { value: '2030-01-10T10:00' } });
    fireEvent.change(screen.getByLabelText('What it is for'), {
        target: { value: 'Budget review' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

    await waitFor(() =>
        expect(screen.getByRole('status').textContent).toMatch(/emailed when it is decided/)
    );
    const [url, method, payload] = request.mock.calls.find(([, m]) => m === 'POST');
    expect(url).toBe('/my/communities/ugmc/rooms/room-1/requests');
    expect(method).toBe('POST');
    expect(payload.purpose).toBe('Budget review');
});

it('says a room that needs no approval is booked at once', async () => {
    request.mockResolvedValueOnce(week);
    request.mockResolvedValueOnce({ booking: { id: 'b2', status: 'confirmed' } });
    request.mockResolvedValue(week);
    render(<RoomsPanel tenantSlug="ugmc" />);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Request this room' }))[1]);
    expect(screen.getByText('This room is booked as soon as you send the request.')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Starts'), { target: { value: '2030-01-10T09:00' } });
    fireEvent.change(screen.getByLabelText('Ends'), { target: { value: '2030-01-10T10:00' } });
    fireEvent.change(screen.getByLabelText('What it is for'), { target: { value: 'Lecture' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

    await waitFor(() => expect(screen.getByText('Your room is booked.')).toBeTruthy());
});

it('shows the reason a request was refused', async () => {
    request.mockResolvedValueOnce(week);
    request.mockRejectedValueOnce(
        Object.assign(new Error('That time is not available. Please choose another.'), {
            status: 422,
        })
    );
    render(<RoomsPanel tenantSlug="ugmc" />);

    fireEvent.click((await screen.findAllByRole('button', { name: 'Request this room' }))[0]);
    fireEvent.change(screen.getByLabelText('Starts'), { target: { value: '2030-01-10T09:00' } });
    fireEvent.change(screen.getByLabelText('Ends'), { target: { value: '2030-01-10T10:00' } });
    fireEvent.change(screen.getByLabelText('What it is for'), {
        target: { value: 'Budget review' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Send request' }));

    expect((await screen.findByRole('alert')).textContent).toMatch(/not available/);
});

it('lists the person own bookings and cancels one', async () => {
    const mine = [
        {
            id: 'b3',
            room: 'Council Chamber',
            starts_at: nine.toISOString(),
            ends_at: eleven.toISOString(),
            status: 'pending_approval',
        },
    ];
    request.mockResolvedValueOnce({ ...week, mine });
    request.mockResolvedValueOnce({ booking: { id: 'b3', status: 'cancelled' } });
    request.mockResolvedValue(week);
    render(<RoomsPanel tenantSlug="ugmc" />);

    expect(await screen.findByText(/waiting for approval/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    await waitFor(() =>
        expect(request).toHaveBeenCalledWith('/my/communities/ugmc/room-requests/b3', 'DELETE')
    );
    expect(await screen.findByText('Cancelled.')).toBeTruthy();
});

it('tells the app when the verification has expired', async () => {
    const onExpired = vi.fn();
    request.mockRejectedValueOnce(
        Object.assign(new Error('Your verification expired.'), { status: 401 })
    );
    render(<RoomsPanel tenantSlug="ugmc" onExpired={onExpired} />);

    await waitFor(() => expect(onExpired).toHaveBeenCalled());
    expect((await screen.findByRole('alert')).textContent).toMatch(/verification expired/);
});
