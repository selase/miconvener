import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import VenueRequestPolicyFields from '@/Components/Venue/VenueRequestPolicyFields';

const policy = { default_hold_hours: 48, timezone: 'Africa/Accra' };

it('states that requests are approval-first and offers no instant-booking choice', () => {
    render(<VenueRequestPolicyFields policy={policy} errors={{}} onChange={vi.fn()} />);

    expect(screen.getByText(/The host approves every request/)).toBeTruthy();
    expect(screen.getByText(/does not reserve the dates/)).toBeTruthy();
    expect(screen.queryByLabelText(/instant/i)).toBeNull();
});

it('reports the hold length as a number and the time zone as typed', () => {
    const onChange = vi.fn();
    render(<VenueRequestPolicyFields policy={policy} errors={{}} onChange={onChange} />);

    fireEvent.change(screen.getByLabelText('Default hold (hours)'), { target: { value: '72' } });
    fireEvent.change(screen.getByLabelText('Venue time zone'), {
        target: { value: 'Europe/London' },
    });

    expect(onChange).toHaveBeenNthCalledWith(1, { ...policy, default_hold_hours: 72 });
    expect(onChange).toHaveBeenNthCalledWith(2, { ...policy, timezone: 'Europe/London' });
});

it('shows the server errors next to the fields they belong to', () => {
    render(
        <VenueRequestPolicyFields
            policy={policy}
            errors={{
                'public_booking_policy.default_hold_hours': 'A hold lasts 1 to 168 hours.',
                'public_booking_policy.timezone': 'Unknown time zone.',
            }}
            onChange={vi.fn()}
        />
    );

    expect(screen.getByText('A hold lasts 1 to 168 hours.')).toBeTruthy();
    expect(screen.getByText('Unknown time zone.')).toBeTruthy();
});
