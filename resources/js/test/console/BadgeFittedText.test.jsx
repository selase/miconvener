import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import BadgeFittedText from '@/Pages/Tenant/Events/Badges/BadgeFittedText';

it('shrinks overflowing names and restores the selected size when the available box grows', () => {
    let height = 25;
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(() => ({
        width: 160,
        height,
    }));
    const scroll = vi
        .spyOn(HTMLElement.prototype, 'scrollHeight', 'get')
        .mockImplementation(function () {
            return Number.parseFloat(this.style.fontSize) * 2.3;
        });
    const { rerender } = render(
        <BadgeFittedText
            text="Long attendee name"
            item={{ font_size: 22, width: 0.8 }}
            pageWidthMm={54}
        />
    );
    const text = screen.getByText('Long attendee name');
    expect(Number.parseFloat(text.style.fontSize)).toBeLessThan(20);
    height = 100;
    rerender(
        <BadgeFittedText text="Short name" item={{ font_size: 22, width: 0.8 }} pageWidthMm={54} />
    );
    expect(Number.parseFloat(screen.getByText('Short name').style.fontSize)).toBeGreaterThan(20);
    scroll.mockRestore();
    vi.restoreAllMocks();
});

it('reports names that cannot fit even at the minimum size', () => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
        width: 160,
        height: 1,
    });
    vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockReturnValue(100);
    const onOverflow = vi.fn();
    render(
        <BadgeFittedText
            text="Too long"
            item={{ font_size: 22, width: 0.8 }}
            pageWidthMm={54}
            onOverflow={onOverflow}
        />
    );
    expect(onOverflow).toHaveBeenLastCalledWith(true);
    expect(Number.parseFloat(screen.getByText('Too long').style.fontSize)).toBeCloseTo(
        (6 * 160) / 0.8 / ((54 * 72) / 25.4)
    );
    vi.restoreAllMocks();
});
