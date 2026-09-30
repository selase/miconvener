import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import PollChart, { densityFor } from '@/Components/Polls/PollChart';

const option = (i, count = 1) => ({
    id: `o${i}`,
    label: `Option ${i}`,
    count,
    percentage: 10,
    is_correct: null,
});

describe('fitting a question onto one screen', () => {
    it('gives a few options room, with the label above the bar', () => {
        expect(densityFor(3).compact).toBe(false);
        expect(densityFor(7).compact).toBe(false);
    });

    it('drops a long list to one line per option so it stays on screen', () => {
        // Eleven is the scale question -- the one that used to run past the fold.
        expect(densityFor(8).compact).toBe(true);
        expect(densityFor(11).compact).toBe(true);
    });

    it("shrinks each row's share as the options grow, and never divides by nothing", () => {
        const share = (n) => parseFloat(densityFor(n).bar.match(/min\(([\d.]+)cqh/)[1]);

        expect(share(11)).toBeLessThan(share(8));
        expect(share(3)).toBeLessThan(share(2));
        expect(() => densityFor(0)).not.toThrow();
    });

    it('sizes against its own box, not the window, so one chart fits wall and laptop', () => {
        const d = densityFor(5);

        expect(d.label).toContain('cq');
        expect(d.label).not.toContain('vh');
        expect(d.label).not.toContain('vw');
    });
});

describe('the chart', () => {
    it('draws every option of an eleven-point scale', () => {
        const options = Array.from({ length: 11 }, (_, i) => option(i));
        render(<PollChart poll={{ type: 'multiple_choice', status: 'live', options }} />);

        expect(screen.getAllByRole('listitem')).toHaveLength(11);
        expect(screen.getByText('Option 10')).toBeTruthy();
    });

    it('marks the right answer only once voting has closed', () => {
        const quiz = (status) => ({
            type: 'quiz',
            status,
            options: [
                { ...option(1), label: 'Cholera', is_correct: true },
                { ...option(2), label: 'Asthma', is_correct: false },
            ],
        });

        const { unmount } = render(<PollChart poll={quiz('live')} />);
        expect(screen.queryByText('correct')).toBeNull();
        unmount();

        render(<PollChart poll={quiz('closed')} />);
        expect(screen.getByText('correct')).toBeTruthy();
    });

    it('shows open answers rather than bars', () => {
        render(
            <PollChart
                poll={{
                    type: 'open',
                    status: 'live',
                    options: [],
                    open_responses: [{ id: 'r1', text: 'More coffee', name: null }],
                }}
            />
        );

        expect(screen.getByText('More coffee')).toBeTruthy();
    });
});
