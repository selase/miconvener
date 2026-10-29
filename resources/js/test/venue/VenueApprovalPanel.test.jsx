import { act, fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { expect, it } from 'vitest';
import VenueApprovalPanel, {
    VenuePaymentConflictBanner,
} from '@/Components/Venue/VenueApprovalPanel';

const approval = (changes = {}) => ({
    flow: true,
    effective_status: 'pending_host_approval',
    hold_expires_at: null,
    timezone: 'Africa/Accra',
    default_hold_hours: 48,
    max_hold_hours: 168,
    decision_reason: null,
    actions: [],
    conflicts: [],
    ...changes,
});
const inquiry = (changes) => ({
    id: 'booking-1',
    booking_reference: 'VRQ-AAAA1111',
    approval: approval(changes),
});

it('explains that a pending request holds nothing and offers approve or decline', () => {
    render(<VenueApprovalPanel inquiry={inquiry()} />);

    expect(screen.getByText(/does not hold these dates/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Approve dates' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Decline request' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Release|Renew/ })).toBeNull();
});

it('approves with the default hold and a stable operation key kept across a failed try', () => {
    render(<VenueApprovalPanel inquiry={inquiry()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Approve dates' }));
    expect(screen.getByLabelText('Hold the dates for (hours)').value).toBe('48');
    fireEvent.click(screen.getByRole('button', { name: 'Confirm approval' }));

    const [url, body, options] = router.post.mock.calls[0];
    expect(url).toContain('tenant.venue.inquiries.approve');
    expect(body.hold_hours).toBe(48);
    expect(body.operation_key).toMatch(/^[0-9a-f-]{36}$/);

    act(() => {
        options.onFinish();
        options.onError({ hold_hours: 'A hold lasts between 1 and 168 hours.' });
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm approval' }));
    expect(router.post.mock.calls[1][1].operation_key).toBe(body.operation_key);
});

it('blocks a double click while the first decision is still being sent', () => {
    render(<VenueApprovalPanel inquiry={inquiry()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Approve dates' }));
    const confirm = screen.getByRole('button', { name: 'Confirm approval' });
    fireEvent.click(confirm);
    fireEvent.click(confirm);

    expect(router.post).toHaveBeenCalledTimes(1);
    expect(confirm.disabled).toBe(true);
});

it.each(['0', '169', ''])('refuses %s hours before sending anything', (hours) => {
    render(<VenueApprovalPanel inquiry={inquiry()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Approve dates' }));
    fireEvent.change(screen.getByLabelText('Hold the dates for (hours)'), {
        target: { value: hours },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm approval' }));

    expect(screen.getByRole('alert').textContent).toMatch(/between 1 and 168/);
    expect(router.post).not.toHaveBeenCalled();
});

it('needs a reason to decline and sends it with the decision', () => {
    render(<VenueApprovalPanel inquiry={inquiry()} />);
    fireEvent.click(screen.getByRole('button', { name: 'Decline request' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm decline' }));
    expect(screen.getByRole('alert').textContent).toMatch(/reason/i);
    expect(router.post).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Reason shown to the requester'), {
        target: { value: 'Closed for repairs' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm decline' }));
    expect(router.post.mock.calls[0][0]).toContain('tenant.venue.inquiries.decline');
    expect(router.post.mock.calls[0][1].reason).toBe('Closed for repairs');
});

it('shows the exact deadline for a hold and offers release, not approval', () => {
    render(
        <VenueApprovalPanel
            inquiry={inquiry({
                effective_status: 'approved_hold',
                hold_expires_at: '2030-04-20T15:30:00+00:00',
            })}
        />
    );

    expect(screen.getByText(/Dates held until/)).toBeTruthy();
    expect(screen.getByText(/3:30 pm/i)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Release hold' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Approve dates' })).toBeNull();
    expect(screen.getByText(/payment is not available yet/)).toBeTruthy();
});

it('offers a renewal only for an expired hold, and says the dates are free meanwhile', () => {
    render(
        <VenueApprovalPanel
            inquiry={inquiry({
                effective_status: 'hold_expired',
                hold_expires_at: '2030-04-20T15:30:00+00:00',
            })}
        />
    );

    expect(screen.getByText(/no longer held/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Renew hold' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm renewal' }));
    expect(router.post.mock.calls[0][0]).toContain('tenant.venue.inquiries.renew');
});

it('offers no decision for a closed request and shows the recorded reason', () => {
    render(
        <VenueApprovalPanel
            inquiry={inquiry({ effective_status: 'declined', decision_reason: 'Booked elsewhere' })}
        />
    );

    expect(screen.getByText(/Booked elsewhere/)).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();
});

it('shows each notice as queued, sent, or not confirmed without offering a resend', () => {
    const actions = [
        {
            id: '1',
            action: 'requested',
            delivery_state: 'sent',
            created_at: '2030-04-01T08:00:00+00:00',
        },
        {
            id: '2',
            action: 'approved',
            delivery_state: 'uncertain',
            created_at: '2030-04-01T09:00:00+00:00',
        },
        {
            id: '3',
            action: 'declined',
            delivery_state: 'failed',
            created_at: '2030-04-01T10:00:00+00:00',
        },
    ];
    render(<VenueApprovalPanel inquiry={inquiry({ actions })} />);

    expect(screen.getByText('Email sent')).toBeTruthy();
    expect(screen.getByText(/Email not confirmed.*not resent/)).toBeTruthy();
    expect(screen.getByText('Email could not be sent')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /resend/i })).toBeNull();
});

it('warns of a payment that could not take its dates and offers no payment action', () => {
    const conflicts = [
        {
            id: 'c1',
            reason: 'calendar_taken',
            provider_reference: 'pstk_late_1',
            amount_pesewas: 20000,
            currency: 'GHS',
            recorded_at: '2030-04-01T08:00:00+00:00',
        },
    ];
    render(<VenuePaymentConflictBanner conflicts={conflicts} />);

    expect(screen.getByRole('alert').textContent).toMatch(
        /Payment\/calendar conflict — reconciliation required/
    );
    expect(screen.getByText(/pstk_late_1/)).toBeTruthy();
    expect(screen.getByText(/GHS 200.00/)).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();
});

it('renders nothing when there is no conflict', () => {
    const { container } = render(<VenuePaymentConflictBanner conflicts={[]} />);

    expect(container.textContent).toBe('');
});

it('says so and disables approval when the requested dates have since been taken', () => {
    render(<VenueApprovalPanel inquiry={inquiry({ dates_available: false })} />);

    expect(screen.getByText(/now taken by another booking/)).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Approve dates' }).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Decline request' }).disabled).toBe(false);
});
