import { readFileSync } from 'node:fs';
import { expect, it } from 'vitest';

const css = readFileSync('resources/css/app.css', 'utf8');
const rgb = (hex) => hex.match(/[a-f0-9]{2}/gi).map((channel) => parseInt(channel, 16));
const luminance = (channels) => {
    const rgb = channels.map((channel) => {
        const value = channel / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722;
};

it.each([':root', '.dark'])('keeps primary action text at WCAG AA contrast in %s', (selector) => {
    const block = css.slice(css.indexOf(`${selector} {`)).split('}')[0];
    const background = block.match(/--color-accent: (#[a-f0-9]{6})/i)[1];
    const foreground = block.match(/--color-accent-ink: (#[a-f0-9]{6})/i)[1];
    const surface = rgb(block.match(/--color-surface: (#[a-f0-9]{6})/i)[1]);
    for (const opacity of [1, 0.9]) {
        const composite = (hex) => rgb(hex).map((channel, i) => opacity * channel + (1 - opacity) * surface[i]);
        const values = [luminance(composite(background)), luminance(composite(foreground))].sort((a, b) => b - a);
        expect((values[0] + 0.05) / (values[1] + 0.05)).toBeGreaterThanOrEqual(4.5);
    }
});
