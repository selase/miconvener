import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import TicketTypesPanel from '@/Pages/Tenant/Events/TicketTypesPanel';
import csrfFetch from '@/lib/csrfFetch';
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
it('adds a ticket type with a distinct free member price', async () => {
    csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });
    render(
        <TicketTypesPanel
            event={{ id: 'event-1', currency: 'GHS', member_benefits_enabled: true }}
            ticketTypes={[]}
            canEdit
            onChange={vi.fn()}
        />
    );
    fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Conference' } });
    fireEvent.change(screen.getByLabelText('Price'), { target: { value: '50' } });
    fireEvent.change(screen.getByLabelText('Member price (GHS)'), { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add' }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
    expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toMatchObject({
        price: 5000,
        member_price: 0,
    });
});
