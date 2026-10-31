import { beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import MemberBenefitsPanel from '@/Pages/Tenant/Events/MemberBenefitsPanel';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
const event = {
    id: 'event-1',
    currency: 'GHS',
    ticket_price: 10000,
    members_only: false,
    member_ticket_price: null,
};
beforeEach(() => csrfFetch.mockReset());
it('saves members-only registration and an explicit zero member price', async () => {
    csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });
    const onChange = vi.fn();
    render(<MemberBenefitsPanel event={event} enabled canEdit onChange={onChange} />);
    fireEvent.click(screen.getByLabelText('Members-only registration'));
    fireEvent.change(screen.getByLabelText('Member ticket price (GHS)'), {
        target: { value: '0' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save member benefits' }));
    await waitFor(() => expect(onChange).toHaveBeenCalled());
    expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({
        members_only: true,
        member_ticket_price: 0,
    });
});
it('keeps blank member pricing distinct from a free member price', async () => {
    csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });
    render(<MemberBenefitsPanel event={event} enabled canEdit onChange={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save member benefits' }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
    expect(JSON.parse(csrfFetch.mock.calls[0][1].body).member_ticket_price).toBeNull();
});
it('does not expose edit controls to a read-only organiser', () => {
    render(<MemberBenefitsPanel event={event} enabled canEdit={false} onChange={() => {}} />);
    expect(screen.queryByRole('button', { name: 'Save member benefits' })).toBeNull();
    expect(screen.queryByRole('checkbox')).toBeNull();
});
