import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';

const view = readFileSync('resources/views/product/landing.blade.php', 'utf8');
beforeEach(() => {
    document.body.innerHTML = view;
    const script = document.querySelector('script[data-hero-showcase-script]');
    if (script) new Function(script.textContent)();
});

afterEach(() => {
    vi.clearAllTimers();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const startHeadline = (reduced = false) => {
    vi.useFakeTimers();
    const preference = { matches: reduced, addEventListener: vi.fn() };
    vi.stubGlobal('matchMedia', () => preference);
    const script = document.querySelector('script[data-hero-headline-script]');
    expect(script).not.toBeNull();
    new Function(script.textContent)();
    return preference;
};

test('headline cycles use cases and can be paused and resumed without changing the showcase', () => {
    startHeadline();
    const active = () =>
        document.querySelector('[data-headline-phrase].is-active').textContent.trim();
    const pause = document.querySelector('[data-headline-pause]');
    expect(active()).toBe('Run the whole event');
    vi.advanceTimersByTime(5000);
    expect(active()).toBe('Run your conference');
    pause.click();
    vi.advanceTimersByTime(20000);
    expect(active()).toBe('Run your conference');
    expect(pause.textContent).toBe('Resume animation');
    pause.click();
    vi.advanceTimersByTime(5000);
    expect(active()).toBe('Run academic events');
    expect(document.querySelector('#hero-tab-run').getAttribute('aria-selected')).toBe('true');
});

test('reduced motion keeps the headline static including a preference change while running', () => {
    const preference = startHeadline(true);
    vi.advanceTimersByTime(30000);
    expect(document.querySelector('[data-headline-pause]').hidden).toBe(true);
    expect(document.querySelector('[data-headline-phrase].is-active').textContent.trim()).toBe(
        'Run the whole event'
    );
    preference.matches = false;
    preference.addEventListener.mock.calls[0][1]();
    vi.advanceTimersByTime(5000);
    expect(document.querySelector('[data-headline-phrase].is-active').textContent.trim()).toBe(
        'Run your conference'
    );
    preference.matches = true;
    preference.addEventListener.mock.calls[0][1]();
    vi.advanceTimersByTime(30000);
    expect(document.querySelector('[data-headline-phrase].is-active').textContent.trim()).toBe(
        'Run the whole event'
    );
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
