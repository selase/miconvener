import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { beforeEach, expect, it, vi } from 'vitest';
import VenueBookingDrawer from '@/Components/Marketplace/VenueBookingDrawer';

const venue = {
    slug: 'grand-hall',
    title: 'Grand Hall',
    rental_price_pesewas: 50000,
    pricing_model: 'per_hour',
    price_visibility: 'public',
    capacity_breakdown: { banquet: 200 },
    approval_requests: true,
    booking_timezone: 'Africa/Accra',
};
const pricing = {
    duration_units: 8,
    time_slot_type: 'hourly',
    rental_amount_pesewas: 400000,
    security_deposit_pesewas: 0,
    total_amount_pesewas: 400000,
    deposit_required_pesewas: 400000,
    service_fee_percent: 3,
    service_fee_pesewas: 12000,
    due_now_pesewas: 412000,
};

beforeEach(() => {
    globalThis.fetch = vi.fn(async (url) => ({
        ok: true,
        json: async () =>
            String(url).includes('booked-slots') ? { slots: [] } : { available: true, pricing },
    }));
});

function fill() {
    fireEvent.change(screen.getByLabelText('Full Name'), { target: { value: 'Ama Planner' } });
    fireEvent.change(screen.getByLabelText('Email Address'), {
        target: { value: 'ama@planner.test' },
    });
    fireEvent.click(screen.getByRole('checkbox'));
}

it('offers a request, not a payment, and says the dates are not reserved', async () => {
    render(<VenueBookingDrawer isOpen onClose={vi.fn()} venue={venue} />);
    await screen.findByRole('button', { name: /Send request/ });

    expect(screen.getByText(/Your dates are not reserved until the host approves/)).toBeTruthy();
    expect(screen.queryByText(/Pay Reservation Deposit/)).toBeNull();
    expect(screen.queryByText('Due Now:')).toBeNull();
    expect(screen.queryByText(/service fee/i)).toBeNull();
    expect((await screen.findAllByText(/indicative/i)).length).toBeGreaterThan(0);
});

it('sends offset timestamps in the venue zone with a stable operation key to the request route', async () => {
    render(<VenueBookingDrawer isOpen onClose={vi.fn()} venue={venue} />);
    await screen.findByRole('button', { name: /Send request/ });
    fill();
    fireEvent.click(screen.getByRole('button', { name: /Send request/ }));

    const [url, body, options] = router.post.mock.calls[0];
    expect(url).toContain('marketplace.venues.request');
    expect(body.starts_at).toMatch(/T09:00:00\+00:00$/);
    expect(body.ends_at).toMatch(/T17:00:00\+00:00$/);
    expect(body.operation_key).toMatch(/^[0-9a-f-]{36}$/);
    expect(body.request_quote_only).toBeUndefined();

    options.onError({ starts_at: 'Dates unavailable' });
    await screen.findByText('Dates unavailable');
    fireEvent.click(screen.getByRole('button', { name: /Send request/ }));
    expect(router.post.mock.calls[1][1].operation_key).toBe(body.operation_key);
});

it('keeps the legacy pay-now form for venues that have not opted in', async () => {
    render(
        <VenueBookingDrawer
            isOpen
            onClose={vi.fn()}
            venue={{ ...venue, approval_requests: false }}
        />
    );

    expect(await screen.findByRole('button', { name: /Pay Reservation Deposit/ })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Send request/ })).toBeNull();
});

it('tells an unverified visitor to verify their email and come back', async () => {
    render(<VenueBookingDrawer isOpen onClose={vi.fn()} venue={venue} />);
    await screen.findByRole('button', { name: /Send request/ });
    fill();
    fireEvent.click(screen.getByRole('button', { name: /Send request/ }));
    router.post.mock.calls[0][2].onError({
        planner_email: 'Verify this email address before requesting a venue.',
    });

    const link = await screen.findByRole('link', { name: /Verify your email/ });
    expect(link.getAttribute('href')).toBe(
        '/my?return_to=' + encodeURIComponent(window.location.pathname)
    );
});

it('refuses a time that does not exist in the venue zone instead of guessing', async () => {
    render(
        <VenueBookingDrawer
            isOpen
            onClose={vi.fn()}
            venue={{ ...venue, booking_timezone: 'Europe/London' }}
        />
    );
    await screen.findByRole('button', { name: /Send request/ });
    fill();
    fireEvent.change(screen.getByLabelText('Event Date'), { target: { value: '2030-03-31' } });
    fireEvent.change(screen.getByLabelText('Start Time'), { target: { value: '01:30' } });
    fireEvent.click(screen.getByRole('button', { name: /Send request/ }));

    await waitFor(() => expect(screen.getByText(/does not exist in Europe\/London/)).toBeTruthy());
    expect(router.post).not.toHaveBeenCalled();
});
