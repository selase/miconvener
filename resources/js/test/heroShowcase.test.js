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
    vi.advanceTimersByTime(8000);
    expect(active()).toBe('Run your conference');
    pause.click();
    vi.advanceTimersByTime(20000);
    expect(active()).toBe('Run your conference');
    expect(pause.getAttribute('aria-label')).toBe('Resume animation');
    expect(pause.querySelector('[data-icon-resume]').hasAttribute('hidden')).toBe(false);
    expect(pause.querySelector('[data-icon-pause]').hasAttribute('hidden')).toBe(true);
    pause.click();
    vi.advanceTimersByTime(8000);
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
    vi.advanceTimersByTime(8000);
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

test('heading, supporting copy and photograph advance together through every use case', () => {
    startHeadline();
    for (let index = 0; index < 6; index++) {
        const active = (attribute) =>
            [...document.querySelectorAll(`[${attribute}]`)].findIndex((el) =>
                el.classList.contains('is-active')
            );
        expect(active('data-headline-phrase')).toBe(index);
        expect(active('data-headline-copy')).toBe(index);
        expect(active('data-headline-image')).toBe(index);
        vi.advanceTimersByTime(8000);
    }
    expect(document.querySelectorAll('[data-headline-image].is-active')).toHaveLength(1);
});

test('pause freezes copy and imagery and visibility changes do not restart a paused hero', () => {
    startHeadline();
    vi.advanceTimersByTime(8000);
    document.querySelector('[data-headline-pause]').click();
    document.dispatchEvent(new Event('visibilitychange'));
    vi.advanceTimersByTime(32000);
    expect(
        document.querySelectorAll('[data-headline-copy]')[1].classList.contains('is-active')
    ).toBe(true);
    expect(
        document.querySelectorAll('[data-headline-image]')[1].classList.contains('is-active')
    ).toBe(true);
});

test('reduced motion loads only the overview photograph and failed photographs preserve the blue fallback', () => {
    startHeadline(true);
    const images = [...document.querySelectorAll('[data-headline-image]')];
    expect(images).toHaveLength(6);
    expect(images.slice(1).every((image) => !image.hasAttribute('src'))).toBe(true);
    images[0].dispatchEvent(new Event('error'));
    expect(images[0].classList.contains('is-active')).toBe(false);
    expect(document.querySelector('[data-headline-copy].is-active')).not.toBeNull();
});
