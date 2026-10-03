import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import PollChart from '@/Components/Polls/PollChart';
import PollPanel, { isComplete, payloadFor } from '@/Pages/Public/Events/AttendeePortal/panels/PollPanel';
import csrfFetch from '@/lib/csrfFetch';

vi.mock('@/lib/csrfFetch', () => ({ default: vi.fn() }));

const results = (type, extra = {}) => ({
    id: 'p1',
    question: 'Q',
    type,
    status: 'live',
    total_responses: 10,
    options: [],
    settings: {},
    suppressed: false,
    suppress_below: 5,
    summary: null,
    open_responses: [],
    ...extra,
});

describe('the wall chart for each question type', () => {
    it('says why a small room shows nothing, rather than drawing empty bars', () => {
        render(<PollChart poll={results('number', { suppressed: true, total_responses: 3 })} />);

        expect(screen.getByText('Not enough responses yet')).toBeTruthy();
        expect(screen.getByText(/once 5 people have answered/)).toBeTruthy();
        expect(screen.getByText(/3 so far/)).toBeTruthy();
    });

    it('draws yes/no as one proportion', () => {
        render(
            <PollChart
                poll={results('yes_no', {
                    options: [
                        { id: 'y', label: 'Yes', count: 7, percentage: 70 },
                        { id: 'n', label: 'No', count: 3, percentage: 30 },
                    ],
                })}
            />
        );

        expect(screen.getByText('70%')).toBeTruthy();
        expect(screen.getByText('30%')).toBeTruthy();
        expect(screen.getByText('7 yes · 3 no')).toBeTruthy();
    });

    it('shows a rating as its average out of five', () => {
        const options = ['1', '2', '3', '4', '5'].map((l) => ({ id: l, label: l, count: 2, percentage: 20 }));
        render(<PollChart poll={results('rating', { options, summary: { average: 4.2, out_of: 5 } })} />);

        expect(screen.getByText('4.2')).toBeTruthy();
        expect(screen.getByText('out of 5')).toBeTruthy();
    });

    it('marks a scale with its ends and its average', () => {
        render(
            <PollChart
                poll={results('scale', {
                    settings: { min: 1, max: 3, label_min: 'Low', label_max: 'High' },
                    summary: {
                        average: 2.4,
                        distribution: [
                            { value: 1, count: 1 },
                            { value: 2, count: 3 },
                            { value: 3, count: 6 },
                        ],
                    },
                })}
            />
        );

        expect(screen.getByText('Low')).toBeTruthy();
        expect(screen.getByText('High')).toBeTruthy();
        expect(screen.getByText('2.4')).toBeTruthy();
    });

    it('gives a number its average, median and range, with the unit', () => {
        render(
            <PollChart
                poll={results('number', {
                    settings: { unit: 'beds' },
                    summary: { average: 40, median: 30, minimum: 10, maximum: 100, histogram: [{ from: 10, to: 55, count: 4 }] },
                })}
            />
        );

        expect(screen.getByText('Average')).toBeTruthy();
        expect(screen.getByText('Median')).toBeTruthy();
        expect(screen.getByText('10 – 100')).toBeTruthy();
        expect(screen.getAllByText('beds').length).toBeGreaterThan(0);
    });

    it('sizes word cloud words by how many people used them', () => {
        render(
            <PollChart
                poll={results('word_cloud', { summary: { words: [{ text: 'data', count: 9 }, { text: 'trust', count: 1 }] } })}
            />
        );

        const big = screen.getByText('data');
        const small = screen.getByText('trust');
        expect(Number(big.style.opacity)).toBeGreaterThan(Number(small.style.opacity));
    });

    it('lists a ranking in the room\'s order and says how many ranked it', () => {
        render(
            <PollChart
                poll={results('ranking', {
                    options: [
                        { id: 'c', label: 'Funding', average_position: 1.3 },
                        { id: 'a', label: 'Staffing', average_position: 2.1 },
                    ],
                    summary: { respondents: 12 },
                })}
            />
        );

        expect(screen.getByText(/Ranked by 12 people/)).toBeTruthy();
        const labels = screen.getAllByRole('listitem').map((li) => li.textContent);
        expect(labels[0]).toContain('Funding');
        expect(labels[1]).toContain('Staffing');
    });

    it('explains that multi-select bars are a share of people', () => {
        render(
            <PollChart
                poll={results('multi_select', {
                    options: [{ id: 'a', label: 'A', count: 4, percentage: 100 }],
                    summary: { respondents: 4 },
                })}
            />
        );

        expect(screen.getByText(/Share of the 4 people who answered/)).toBeTruthy();
    });
});

describe('what the phone sends for each type', () => {
    const blank = { optionId: null, optionIds: [], text: '', number: '', scale: 5, scaleTouched: false, words: ['', '', ''], order: [] };

    it('sends the right field for each type', () => {
        expect(payloadFor({ type: 'scale' }, { ...blank, scale: 7 })).toEqual({ response_number: 7 });
        expect(payloadFor({ type: 'number' }, { ...blank, number: '12.5' })).toEqual({ response_number: 12.5 });
        expect(payloadFor({ type: 'multi_select' }, { ...blank, optionIds: ['a', 'c'] })).toEqual({ option_ids: ['a', 'c'] });
        expect(payloadFor({ type: 'ranking' }, { ...blank, order: ['c', 'a', 'b'] })).toEqual({ option_ids: ['c', 'a', 'b'] });
        expect(payloadFor({ type: 'word_cloud' }, { ...blank, words: [' data ', '', 'trust'] })).toEqual({ words: ['data', 'trust'] });
        expect(payloadFor({ type: 'rating' }, { ...blank, optionId: 'four' })).toEqual({ option_id: 'four' });
    });

    it('will not send a scale nobody moved, or an empty word cloud', () => {
        // The slider starts in the middle; sending that unmoved would record an
        // answer the person never gave.
        expect(isComplete({ type: 'scale' }, blank)).toBe(false);
        expect(isComplete({ type: 'scale' }, { ...blank, scaleTouched: true })).toBe(true);
        expect(isComplete({ type: 'word_cloud' }, { ...blank, words: ['  ', '', ''] })).toBe(false);
        expect(isComplete({ type: 'number' }, { ...blank, number: 'abc' })).toBe(false);
    });
});

describe('answering on the phone', () => {
    const livePoll = (poll) => ({
        ok: true,
        json: async () => ({ poll: { id: 'p1', question: 'Ready?', total_votes: 0, settings: {}, ...poll } }),
    });

    beforeEach(() => {
        csrfFetch.mockReset();
        global.fetch = vi.fn();
    });

    it('sends the tapped option at once for yes/no, not an empty answer', async () => {
        global.fetch.mockResolvedValue(livePoll({ type: 'yes_no', options: [{ id: 'yes-id', label: 'Yes' }, { id: 'no-id', label: 'No' }] }));
        csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });

        render(<PollPanel registration={{ id: 'r1' }} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Yes' }));

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        // The answer travels with the tap. Read from state instead, it would
        // still be empty, because setting it and sending it happen together.
        expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({ option_id: 'yes-id' });
    });

    it('sends one answer when a star is tapped twice in quick succession', async () => {
        global.fetch.mockResolvedValue(livePoll({ type: 'rating', options: ['1', '2', '3', '4', '5'].map((l) => ({ id: `s${l}`, label: l })) }));
        let release;
        csrfFetch.mockImplementation(() => new Promise((resolve) => (release = () => resolve({ ok: true, json: async () => ({}) }))));

        render(<PollPanel registration={{ id: 'r1' }} />);
        const four = await screen.findByRole('button', { name: '4 out of 5' });
        fireEvent.click(four);
        fireEvent.click(four);

        expect(csrfFetch).toHaveBeenCalledTimes(1);
        await act(async () => release());
    });

    it('lets a ranking be reordered before it is sent', async () => {
        global.fetch.mockResolvedValue(
            livePoll({ type: 'ranking', options: [{ id: 'a', label: 'Staffing' }, { id: 'b', label: 'Funding' }] })
        );
        csrfFetch.mockResolvedValue({ ok: true, json: async () => ({}) });

        render(<PollPanel registration={{ id: 'r1' }} />);
        fireEvent.click(await screen.findByRole('button', { name: 'Move Funding up' }));
        fireEvent.click(screen.getByRole('button', { name: 'Send' }));

        await waitFor(() => expect(csrfFetch).toHaveBeenCalled());
        expect(JSON.parse(csrfFetch.mock.calls[0][1].body)).toEqual({ option_ids: ['b', 'a'] });
    });
});
