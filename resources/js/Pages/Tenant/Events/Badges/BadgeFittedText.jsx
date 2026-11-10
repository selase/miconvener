import { useLayoutEffect, useRef } from 'react';

export default function BadgeFittedText({ text, item, pageWidthMm, onOverflow }) {
    const ref = useRef(null);
    useLayoutEffect(() => {
        const element = ref.current;
        const parent = element?.parentElement;
        if (!parent) return;
        const fit = () => {
            const bounds = parent.getBoundingClientRect();
            if (!bounds.width || !bounds.height || !item.width) return;
            const scale = bounds.width / item.width / ((pageWidthMm * 72) / 25.4);
            const original = Number(item.font_size) || 12;
            const minimum = Math.min(6, original);
            let size = original;
            element.style.fontSize = `${size * scale}px`;
            const overflows = () =>
                element.scrollHeight > bounds.height + 0.5 ||
                element.scrollWidth > bounds.width + 0.5;
            while (size > minimum && overflows()) {
                size = Math.max(minimum, size - 0.5);
                element.style.fontSize = `${size * scale}px`;
            }
            onOverflow?.(overflows());
        };
        fit();
        const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(fit) : null;
        observer?.observe(parent);
        let active = true;
        document.fonts?.ready.then(() => {
            if (active) fit();
        });
        document.fonts?.addEventListener('loadingdone', fit);
        return () => {
            active = false;
            observer?.disconnect();
            document.fonts?.removeEventListener('loadingdone', fit);
        };
    }, [text, item, pageWidthMm, onOverflow]);
    return (
        <span
            ref={ref}
            style={{
                display: 'block',
                width: '100%',
                whiteSpace: 'normal',
                overflowWrap: 'normal',
                lineHeight: 1.15,
            }}
        >
            {text}
        </span>
    );
}
