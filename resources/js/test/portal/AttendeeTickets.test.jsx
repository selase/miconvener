import { render, screen, fireEvent } from '@testing-library/react';
import { it, expect, vi } from 'vitest';
import AttendeeTickets from '@/Pages/Public/Events/AttendeePortal/components/AttendeeTickets';
const item = {
    registration_id: 'one',
    registration_status: 'confirmed',
    event: { name: 'Conference', starts_at: '2026-10-24T09:00:00Z', location: 'Accra' },
    organiser: { name: 'Purpledot' },
    ticket: { type: 'Delegate' },
    event_workspace_url: '/my/events/one',
};
it('keeps original workspaces and records reachable without inventing a QR', () => {
    const filter = vi.fn();
    const record = vi.fn();
    render(
        <AttendeeTickets
            history={{ passes: { data: [item], current_page: 1, last_page: 1 } }}
            records={{}}
            offlineTickets={[]}
            onFilterChange={filter}
            onRecord={record}
            onOpen={vi.fn()}
        />
    );
    expect(screen.getByRole('link', { name: 'Event workspace' }).getAttribute('href')).toBe(
        '/my/events/one'
    );
    expect(screen.queryByAltText('Ticket QR code')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Past' }));
    expect(filter).toHaveBeenCalledWith('past', 1);
    fireEvent.click(screen.getByRole('button', { name: 'Certificates' }));
    expect(record).toHaveBeenCalledWith('certificates');
});
