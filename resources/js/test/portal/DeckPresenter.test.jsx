import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import DeckPresenter from '@/Pages/Public/Events/DeckPresenter';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const poll = (question) => ({
    id: `p-${question}`,
    question,
    type: 'multiple_choice',
    status: 'live',
    total_responses: 3,
    options: [{ id: 'o1', label: 'Yes', count: 3, percentage: 100, is_correct: null }],
});

const live = (position, overrides = {}) => ({
    id: 'deck-1',
    title: 'Opening plenary',
    status: 'live',
    position,
    total: 3,
    current: poll(`Question ${position}`),
    next: position < 3 ? { question: `Question ${position + 1}` } : null,
    can_go_back: position > 1,
    can_advance: position < 3,
    questions: ['Question 1', 'Question 2', 'Question 3'],
    ...overrides,
});

const props = (deck) => ({
    event: { id: 'evt-1', name: 'Congress' },
    organiser: { name: 'Purpledot' },
    deck,
    stateUrl: '/state',
    actionUrl: '/present/tok',
    wallUrl: '/wall',
});

const replyWith = (deck) =>
    csrfFetch.mockResolvedValue({ ok: true, status: 200, json: async () => deck });

describe('the presenter screen', () => {
    beforeEach(() => {
        csrfFetch.mockReset();
        global.fetch = vi.fn(() => new Promise(() => {}));
    });

    it('shows where the presenter is and what comes next', () => {
        render(<DeckPresenter {...props(live(1))} />);

        expect(screen.getByText('Question 1 of 3')).toBeTruthy();
        expect(screen.getAllByText('Question 2').length).toBeGreaterThan(0);
    });

    it('moves on when a presentation clicker sends PageDown', async () => {
        replyWith(live(2));
        render(<DeckPresenter {...props(live(1))} />);

        fireEvent.keyDown(window, { key: 'PageDown' });

        await waitFor(() => expect(csrfFetch).toHaveBeenCalledWith('/present/tok/advance', expect.anything()));
        await screen.findByText('Question 2 of 3');
    });

    it('goes back on PageUp, and not past the first question', async () => {
        render(<DeckPresenter {...props(live(1))} />);

        fireEvent.keyDown(window, { key: 'PageUp' });

        // Already on the first question: nothing is sent.
        expect(csrfFetch).not.toHaveBeenCalled();
    });

    it('sends one advance for a clicker that fires twice at once', async () => {
        let release;
        csrfFetch.mockImplementation(
            () =>
                new Promise((resolve) => {
                    release = () => resolve({ ok: true, status: 200, json: async () => live(2) });
                })
        );
        render(<DeckPresenter {...props(live(1))} />);

        // Two presses before the first has answered. Both reaching the server
        // would skip a question in front of the room.
        fireEvent.keyDown(window, { key: 'PageDown' });
        fireEvent.keyDown(window, { key: 'PageDown' });

        expect(csrfFetch).toHaveBeenCalledTimes(1);
        await act(async () => release());
    });

    it('does not advance past the last question', () => {
        render(<DeckPresenter {...props(live(3))} />);

        expect(screen.getByRole('button', { name: /Next question/ }).disabled).toBe(true);
        fireEvent.keyDown(window, { key: 'ArrowRight' });
        expect(csrfFetch).not.toHaveBeenCalled();
    });

    it('asks for a second press before ending the deck', async () => {
        replyWith(live(1, { status: 'ended', current: null, position: null }));
        render(<DeckPresenter {...props(live(1))} />);

        fireEvent.click(screen.getByRole('button', { name: 'End deck' }));
        expect(csrfFetch).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Press again to end' }));
        await waitFor(() => expect(csrfFetch).toHaveBeenCalledWith('/present/tok/end', expect.anything()));
    });

    it('starts a draft deck from the keyboard', async () => {
        replyWith(live(1));
        render(
            <DeckPresenter
                {...props({ ...live(1), status: 'draft', position: null, current: null, next: { question: 'Question 1' } })}
            />
        );

        expect(screen.getByText('Ready when you are')).toBeTruthy();
        fireEvent.keyDown(window, { key: 'ArrowRight' });

        await waitFor(() => expect(csrfFetch).toHaveBeenCalledWith('/present/tok/start', expect.anything()));
    });

    it('says plainly when the link has been replaced', async () => {
        csrfFetch.mockResolvedValue({ ok: false, status: 404, json: async () => ({}) });
        render(<DeckPresenter {...props(live(1))} />);

        fireEvent.click(screen.getByRole('button', { name: /Next question/ }));

        await screen.findByText(/presenter link has been replaced/);
    });
});
