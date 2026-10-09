import { useRef } from 'react';

export const badgeElementLabel = (key) =>
    key === 'qr'
        ? 'QR'
        : key === 'tenant_logo'
          ? 'Logo'
          : key.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
const clamp = (value, maximum) => Math.min(Math.max(value, 0), maximum);

export default function BadgeCanvasElement({
    elementKey,
    item,
    template,
    selected,
    onSelect,
    onActivate,
    onChange,
    style,
    children,
}) {
    const drag = useRef(null);
    const suppressClick = useRef(false);
    const interactive = Boolean(onSelect);
    const start = (event, resize = false) => {
        if (!onChange || event.button !== 0) return;
        const canvas = event.currentTarget.closest('[data-badge-canvas]')?.getBoundingClientRect();
        if (!canvas?.width || !canvas?.height) return;
        event.preventDefault();
        event.currentTarget.setPointerCapture?.(event.pointerId);
        onActivate?.(elementKey);
        drag.current = { item: { ...item }, x: event.clientX, y: event.clientY, canvas, resize };
        suppressClick.current = false;
    };
    const move = (event) => {
        const start = drag.current;
        if (!start) return;
        const dx = (event.clientX - start.x) / start.canvas.width;
        const dy = (event.clientY - start.y) / start.canvas.height;
        if (Math.abs(dx) + Math.abs(dy) < 0.003) return;
        suppressClick.current = true;
        if (start.resize) {
            const ratio =
                elementKey === 'qr'
                    ? template.width_mm / template.height_mm
                    : start.item.height / start.item.width;
            const width = clamp(
                start.item.width + dx,
                Math.min(1 - start.item.x, (1 - start.item.y) / ratio)
            );
            const minimum = Math.min(0.03, 1 - start.item.x, (1 - start.item.y) / ratio);
            const nextWidth = Math.max(minimum, width);
            onChange(elementKey, { width: nextWidth, height: nextWidth * ratio });
        } else {
            onChange(elementKey, {
                x: clamp(start.item.x + dx, 1 - start.item.width),
                y: clamp(start.item.y + dy, 1 - start.item.height),
            });
        }
    };
    const end = () => {
        drag.current = null;
    };
    const cancel = () => {
        if (drag.current) onChange(elementKey, drag.current.item);
        end();
    };
    const nudge = (event) => {
        if (!onChange || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key))
            return;
        event.preventDefault();
        const step = event.shiftKey ? 5 : 1;
        const horizontal =
            event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
        const vertical = event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
        onActivate?.(elementKey);
        onChange(elementKey, {
            x: clamp(item.x + horizontal / template.width_mm, 1 - item.width),
            y: clamp(item.y + vertical / template.height_mm, 1 - item.height),
        });
    };
    const Content = interactive ? 'button' : 'div';
    return (
        <div
            className="absolute"
            style={{
                left: `${item.x * 100}%`,
                top: `${item.y * 100}%`,
                width: `${item.width * 100}%`,
                height: `${item.height * 100}%`,
            }}
        >
            <Content
                type={interactive ? 'button' : undefined}
                aria-label={interactive ? `Select ${badgeElementLabel(elementKey)}` : undefined}
                aria-pressed={interactive ? selected : undefined}
                onClick={
                    interactive
                        ? () => {
                              if (suppressClick.current) {
                                  suppressClick.current = false;
                                  return;
                              }
                              onSelect(elementKey);
                          }
                        : undefined
                }
                onPointerDown={interactive ? start : undefined}
                onPointerMove={interactive ? move : undefined}
                onPointerUp={interactive ? end : undefined}
                onPointerCancel={interactive ? cancel : undefined}
                onKeyDown={interactive ? nudge : undefined}
                className="h-full w-full overflow-hidden leading-tight focus-visible:outline-2 focus-visible:outline-indigo-600"
                style={{
                    display: 'block',
                    alignContent: 'start',
                    margin: 0,
                    padding: 0,
                    border: 0,
                    background: 'transparent',
                    touchAction: interactive ? 'none' : undefined,
                    cursor: onChange ? 'move' : undefined,
                    outline: interactive && selected ? '2px solid #6366f1' : undefined,
                    outlineOffset: '-2px',
                    ...style,
                }}
            >
                {children}
            </Content>
            {interactive && selected && onChange && ['qr', 'tenant_logo'].includes(elementKey) && (
                <button
                    type="button"
                    aria-label={`Resize ${badgeElementLabel(elementKey)}`}
                    onPointerDown={(event) => start(event, true)}
                    onPointerMove={move}
                    onPointerUp={end}
                    onPointerCancel={cancel}
                    onKeyDown={(event) => {
                        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
                        event.preventDefault();
                        const ratio =
                            elementKey === 'qr'
                                ? template.width_mm / template.height_mm
                                : item.height / item.width;
                        const width = Math.max(
                            Math.min(0.03, 1 - item.x, (1 - item.y) / ratio),
                            clamp(
                                item.width +
                                    (event.key === 'ArrowRight' ? 1 : -1) / template.width_mm,
                                Math.min(1 - item.x, (1 - item.y) / ratio)
                            )
                        );
                        onChange(elementKey, { width, height: width * ratio });
                    }}
                    className="absolute -bottom-1 -right-1 h-4 w-4 rounded-sm border-2 border-white bg-indigo-600 focus-visible:ring-2 focus-visible:ring-indigo-500"
                    style={{ touchAction: 'none', cursor: 'nwse-resize' }}
                />
            )}
        </div>
    );
}
