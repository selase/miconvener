import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import GuestPoll from '@/Pages/Public/Events/GuestPoll';
import PollPanel from '@/Pages/Public/Events/AttendeePortal/panels/PollPanel';
import GuestPollSettings from '@/Pages/Tenant/Events/panels/GuestPollSettings';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@inertiajs/react', () => ({ Head: () => null, usePage: () => ({ props: { auth: { can: { update_event: true } } } }), Link: ({ children, ...props }) => <a {...props}>{children}</a> }));
vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.clearAllMocks(); });
const props = { event: { name: 'PISP Connect' }, joined: false, joinUrl: '/live-polls/join', pollUrl: '/live-polls/state' };

it('joins explicitly without asking for registration or contact details', async () => {
    csrfFetch.mockResolvedValue({ ok: true, json: async () => ({ joined: true }) });
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ poll: null }) }));
    render(<GuestPoll {...props} />);
    expect(screen.queryByRole('textbox')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Join live polls' }));
    await waitFor(() => expect(csrfFetch).toHaveBeenCalledWith(props.joinUrl, expect.objectContaining({ method: 'POST' })));
    await waitFor(() => expect(fetch).toHaveBeenCalledWith(props.pollUrl, expect.anything()));
});

it('keeps a useful retry message if joining is refused', async () => {
    csrfFetch.mockResolvedValue({ ok: false, json: async () => ({ message: 'Guest participation is paused.' }) });
    render(<GuestPoll {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Join live polls' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Guest participation is paused.');
    expect(screen.getByRole('button', { name: 'Join live polls' }).disabled).toBe(false);
});

it('preserves the registered portal endpoint by default', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ poll: null }) }));
    render(<PollPanel registration={{ id: 'registered-id' }} />);
    await waitFor(() => expect(fetch).toHaveBeenCalledWith('/my/events/registered-id/poll', expect.anything()));
});

it('lets event staff enable guests and exposes the join URL and QR', async () => {
    global.route = (name) => name;
    csrfFetch.mockResolvedValueOnce({ ok: true, json: async () => ({ enabled: false, url: 'https://example.test/live-polls', qr: '' }) });
    csrfFetch.mockResolvedValueOnce({ ok: true, json: async () => ({ enabled: true, url: 'https://example.test/live-polls', qr: 'data:image/svg+xml;base64,PHN2Zy8+' }) });
    render(<GuestPollSettings event={{ id: 'event' }} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Enable guest polls' }));
    expect((await screen.findByRole('link', { name: 'Open guest join page' })).getAttribute('href')).toBe('https://example.test/live-polls');
    expect(screen.getByRole('img', { name: 'Scan to join live polls' })).toBeTruthy();
});

it('shows the closed question without offering another answer', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ poll: { id: 'q', question: 'A finished question', status: 'closed', type: 'multiple_choice', options: [{ id: 'a', label: 'Yes' }], total_votes: 0, user_response: null } }) }));
    render(<PollPanel pollUrl="/guest/state" />);
    expect(await screen.findByText('Voting has closed. Waiting for the next question.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Yes/ })).toBeNull();
});

it('never labels an ordinary poll answer as an incorrect quiz on reload', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ poll: { id: 'q', question: 'Your preference?', type: 'multiple_choice', options: [{ id: 'a', label: 'Yes', votes_count: 1, percentage: 100 }], total_votes: 1, user_response: { option_id: 'a', score: 0, is_correct: null } } }) }));
    render(<PollPanel pollUrl="/guest/state" />);
    await screen.findByText('Your preference?');
    expect(screen.queryByText(/Incorrect/)).toBeNull();
});
