import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import BadgeCanvasElement from '@/Pages/Tenant/Events/Badges/BadgeCanvasElement';

it('keeps a resized QR square and inside the page near the corner', () => {
    const change = vi.fn();
    render(
        <BadgeCanvasElement
            elementKey="qr"
            item={{ x: 0.99, y: 0.98, width: 0.01, height: 0.014 }}
            template={{ width_mm: 100, height_mm: 70 }}
            selected
            onSelect={vi.fn()}
            onChange={change}
        >
            QR
        </BadgeCanvasElement>
    );
    fireEvent.keyDown(screen.getByRole('button', { name: 'Resize QR' }), { key: 'ArrowRight' });
    const next = change.mock.calls[0][1];
    expect(next.width + 0.99).toBeLessThanOrEqual(1);
    expect(next.height + 0.98).toBeLessThanOrEqual(1);
    expect(next.height * 70).toBeCloseTo(next.width * 100);
});

it('drags within the badge and cancels back to the original position', () => {
    vi.stubGlobal('PointerEvent', MouseEvent);
    const change = vi.fn();
    const item = { x: 0.2, y: 0.2, width: 0.2, height: 0.3 };
    const { container } = render(
        <div data-badge-canvas>
            <BadgeCanvasElement
                elementKey="qr"
                item={item}
                template={{ width_mm: 100, height_mm: 70 }}
                onSelect={vi.fn()}
                onChange={change}
            >
                QR
            </BadgeCanvasElement>
        </div>
    );
    vi.spyOn(container.firstChild, 'getBoundingClientRect').mockReturnValue({
        width: 1000,
        height: 700,
    });
    const qr = screen.getByRole('button', { name: 'Select QR' });
    fireEvent.pointerDown(qr, { button: 0, clientX: 200, clientY: 140 });
    fireEvent.pointerMove(qr, { clientX: 1500, clientY: -200 });
    expect(change).toHaveBeenLastCalledWith('qr', { x: 0.8, y: 0 });
    fireEvent.pointerCancel(qr);
    expect(change).toHaveBeenLastCalledWith('qr', item);
    vi.unstubAllGlobals();
});
