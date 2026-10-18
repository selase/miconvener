import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import ServicesPanel from '@/Pages/Public/Community/ServicesPanel';
const request = vi.hoisted(() => vi.fn());
vi.mock('@/lib/community', () => ({ communityRequest: request }));

it('keeps morning and evening services distinct and opens their own notes', async () => {
    request.mockResolvedValueOnce({
        organisation: { name: 'Church', timezone: 'Africa/Accra' },
        services: {
            data: [
                {
                    id: 'morning',
                    title: 'Morning service',
                    event_title: 'Sunday services',
                    starts_at: '2026-10-18T08:00:00Z',
                },
                {
                    id: 'evening',
                    title: 'Evening service',
                    event_title: 'Sunday services',
                    starts_at: '2026-10-18T18:00:00Z',
                },
            ],
        },
    });
    request.mockResolvedValueOnce({
        service: { id: 'morning', title: 'Morning service', starts_at: '2026-10-18T08:00:00Z' },
        timezone: 'Africa/Accra',
        notes: [{ id: 'note', title: 'Hope', plain_text: 'Morning sermon notes only' }],
        contexts: [],
    });
    render(<ServicesPanel tenantSlug="church" />);
    fireEvent.click(await screen.findByRole('button', { name: /Morning service/ }));
    expect(await screen.findByText('Morning sermon notes only')).toBeTruthy();
    expect(screen.queryByText('Evening sermon notes')).toBeNull();
});

it('shows a safe empty state for an organisation with no released service notes', async () => {
    request.mockResolvedValueOnce({
        organisation: { name: 'Church', timezone: 'Africa/Accra' },
        services: { data: [] },
    });
    render(<ServicesPanel tenantSlug="church" />);
    expect(await screen.findByText('No published service notes match these dates.')).toBeTruthy();
});

it('offers an authorised programme shortcut for the exact service occurrence', async () => {
    const { default: SchedulePanel } = await import('@/Pages/Tenant/Events/SchedulePanel');
    render(
        <SchedulePanel
            event={{ id: 'event-1', is_recurring: true, timezone: 'Africa/Accra' }}
            sessions={[
                {
                    id: 'session-1',
                    title: 'Sunday service',
                    is_occurrence: true,
                    occurrence_status: 'scheduled',
                    starts_at: '2026-10-18T08:00:00Z',
                    ends_at: '2026-10-18T10:00:00Z',
                    speakers: [],
                },
            ]}
            speakers={[]}
            onChange={vi.fn()}
            canPublishCommunityNotes
        />
    );
    expect(screen.getByRole('link', { name: 'Member notes' }).getAttribute('href')).toContain(
        'service=session-1'
    );
});
