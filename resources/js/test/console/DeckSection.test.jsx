import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { DeckSection } from '@/Pages/Tenant/Events/panels/EngagementPanel';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const event = { id: 'evt-1' };

const polls = [
    { id: 'p1', question: 'Are you with us?', type: 'multiple_choice', status: 'live' },
    { id: 'p2', question: 'Coffee or tea?', type: 'multiple_choice', status: 'draft' },
    { id: 'p3', question: 'Not in any deck', type: 'open', status: 'draft' },
];

const deck = {
    id: 'deck-1',
    title: 'Opening plenary',
    status: 'draft',
    join_code: '4QJ8ET',
    current_poll_id: null,
    polls: [
        { id: 'p1', position: 0, question: 'Are you with us?', type: 'multiple_choice', status: 'live' },
        { id: 'p2', position: 1, question: 'Coffee or tea?', type: 'multiple_choice', status: 'draft' },
    ],
};

const respondWith = (decks) =>
    csrfFetch.mockImplementation(() =>
        Promise.resolve({ ok: true, json: async () => decks })
    );

describe('driving a deck from the console', () => {
    beforeEach(() => csrfFetch.mockReset());

    it('lists the deck with its questions in order', async () => {
        respondWith([deck]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        await screen.findByText('Opening plenary');
        expect(screen.getByText('Are you with us?')).toBeTruthy();
        expect(screen.getByText('Coffee or tea?')).toBeTruthy();
        expect(screen.getByText(/2 questions/)).toBeTruthy();
    });

    it('offers only the questions that are not already in the deck', async () => {
        respondWith([deck]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        const select = await screen.findByRole('combobox');
        const labels = within(select).getAllByRole('option').map((o) => o.textContent);

        // Adding a question the deck already holds would ask the server to put
        // it in twice, and (deck_id, position) is unique.
        expect(labels).toContain('Not in any deck');
        expect(labels).not.toContain('Are you with us?');
    });

    it('sends the whole new order when a question moves up', async () => {
        respondWith([deck]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        await screen.findByText('Opening plenary');
        csrfFetch.mockClear();
        fireEvent.click(screen.getAllByLabelText('Move up')[1]);

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        const [url, options] = csrfFetch.mock.calls[0];
        expect(url).toContain('tenant.events.decks.polls');
        expect(options.method).toBe('PUT');
        expect(JSON.parse(options.body).poll_ids).toEqual(['p2', 'p1']);
    });

    it('shows Start while a deck is idle', async () => {
        respondWith([deck]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        await screen.findByRole('button', { name: 'Start deck' });
        expect(screen.queryByRole('button', { name: 'Next question' })).toBeNull();
    });

    it('shows the running controls and the position once a deck is live', async () => {
        respondWith([{ ...deck, status: 'live', current_poll_id: 'p1' }]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        // Where the presenter is standing, which is the whole point of the
        // control strip: Start is gone and the pace controls are in its place.
        await screen.findByRole('button', { name: 'Next question' });
        expect(screen.getByText(/Question 1 of 2/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Back' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Close voting' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'End deck' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Start deck' })).toBeNull();
    });

    it('asks the presenter route for the next question', async () => {
        respondWith([{ ...deck, status: 'live', current_poll_id: 'p1' }]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        const next = await screen.findByRole('button', { name: 'Next question' });
        csrfFetch.mockClear();
        respondWith([{ ...deck, status: 'live', current_poll_id: 'p2' }]);
        fireEvent.click(next);

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        const [url, options] = csrfFetch.mock.calls[0];
        expect(url).toContain('tenant.events.decks.advance');
        expect(options.method).toBe('POST');
    });

    it('will not start a deck with nothing in it', async () => {
        respondWith([{ ...deck, polls: [] }]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        const start = await screen.findByRole('button', { name: 'Start deck' });
        expect(start.disabled).toBe(true);
    });

    it('creates a deck by name', async () => {
        respondWith([]);
        render(<DeckSection event={event} polls={polls} onChange={() => {}} />);

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        csrfFetch.mockClear();
        respondWith([]);

        fireEvent.change(screen.getByPlaceholderText(/Deck name/), {
            target: { value: 'Closing plenary' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Add deck' }));

        await waitFor(() => expect(csrfFetch.mock.calls.length).toBeGreaterThan(0));
        const [url, options] = csrfFetch.mock.calls[0];
        expect(url).toContain('tenant.events.decks.store');
        expect(JSON.parse(options.body)).toEqual({ title: 'Closing plenary' });
    });
});
