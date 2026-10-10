import { readFileSync } from 'node:fs';
import { beforeEach, expect, test } from 'vitest';

const view = readFileSync('resources/views/product/landing.blade.php', 'utf8');
beforeEach(() => {
    document.body.innerHTML = view;
    const script = document.querySelector('script[data-hero-showcase-script]');
    if (script) new Function(script.textContent)();
});

test('the event-day overview is selected initially and clicks reveal only the corresponding panel', () => {
    const tabs = [...document.querySelectorAll('[data-hero-showcase] [role="tab"]')];
    expect(tabs).toHaveLength(5);
    expect(tabs[1].getAttribute('aria-selected')).toBe('true');
    for (const tab of tabs) {
        tab.click();
        for (const candidate of tabs) {
            const selected = candidate === tab;
            expect(candidate.getAttribute('aria-selected')).toBe(String(selected));
            expect(document.getElementById(candidate.getAttribute('aria-controls')).hidden).toBe(
                !selected
            );
        }
    }
});

test('arrow, home and end keys select and focus tabs with a single tab stop', () => {
    const tabs = [...document.querySelectorAll('[data-hero-showcase] [role="tab"]')];
    expect(tabs).toHaveLength(5);
    const press = (index, key, expected) => {
        tabs[index].dispatchEvent(
            new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })
        );
        expect(document.activeElement).toBe(tabs[expected]);
        expect(tabs.filter((tab) => tab.tabIndex === 0)).toEqual([tabs[expected]]);
        expect(tabs[expected].getAttribute('aria-selected')).toBe('true');
    };
    press(1, 'ArrowRight', 2);
    press(4, 'ArrowRight', 0);
    press(0, 'ArrowLeft', 4);
    press(4, 'Home', 0);
    press(0, 'End', 4);
});
