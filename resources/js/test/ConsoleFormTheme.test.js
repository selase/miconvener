import { beforeAll, describe, expect, it } from 'vitest';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { compile } from '@tailwindcss/node';
import postcss from 'postcss';
import selectorParser from 'postcss-selector-parser';

let stylesheet;

function specificity(selector) {
    function score(node) {
        if (node.type === 'id') return 100;
        if (node.type === 'class' || node.type === 'attribute') return 10;
        if (node.type === 'tag') return 1;
        if (node.type === 'pseudo') {
            if (node.value === ':where') return 0;
            if ([':is', ':not', ':has'].includes(node.value))
                return Math.max(...node.nodes.map(score));
            return 10;
        }
        return (node.nodes ?? []).reduce((total, child) => total + score(child), 0);
    }
    return score(selectorParser().astSync(selector));
}

beforeAll(async () => {
    const base = resolve('resources/css');
    const compiler = await compile(await readFile(`${base}/app.css`, 'utf8'), {
        base,
        onDependency: () => {},
    });
    stylesheet = postcss.parse(compiler.build(['text-ink', 'bg-transparent']));
});

// Inspect the real compiled base and utility layers. JSDOM cannot resolve
// CSS variables or layer precedence, so resolve these declarations explicitly.
function stylesFor(markup, dark = false, console = true) {
    document.documentElement.className = dark ? 'dark' : '';
    document.body.className = console ? 'console-root' : '';
    document.body.innerHTML = markup;
    const element = document.body.firstElementChild;
    const values = {};
    const variables = {};
    const layers = ['theme', 'base', 'components', 'utilities', null];

    for (const layer of layers) {
        const priorities = {};
        stylesheet.walkRules((rule) => {
            const parent = rule.parent;
            const ruleLayer =
                parent.type === 'atrule' && parent.name === 'layer' ? parent.params : null;
            if (ruleLayer !== layer || (parent.type !== 'root' && parent.name !== 'layer')) return;
            const selectors = rule.selectors.filter(
                (selector) => !selector.includes('::') && !selector.includes(':-')
            );
            for (const selector of selectors) {
                if (document.documentElement.matches(selector)) {
                    rule.walkDecls((declaration) => {
                        if (declaration.prop.startsWith('--'))
                            variables[declaration.prop] = declaration.value;
                    });
                }
                if (element.matches(selector)) {
                    const priority = specificity(selector);
                    rule.walkDecls((declaration) => {
                        if (priority >= (priorities[declaration.prop] ?? -1)) {
                            values[declaration.prop] = declaration.value;
                            priorities[declaration.prop] = priority;
                        }
                    });
                }
            }
        });
    }
    return Object.fromEntries(
        Object.entries(values).map(([property, value]) => [
            property,
            value.replace(/var\((--[\w-]+)\)/g, (_, variable) => variables[variable] ?? variable),
        ])
    );
}

describe('console form theme defaults', () => {
    const controls = [
        '<input class="text-ink">',
        ...[
            'text',
            'email',
            'password',
            'number',
            'date',
            'datetime-local',
            'month',
            'search',
            'tel',
            'time',
            'url',
            'week',
        ].map((type) => `<input type="${type}" class="text-ink">`),
        '<select class="text-ink"><option>Monthly</option></select>',
        '<textarea class="text-ink"></textarea>',
    ];

    it.each(controls)('uses a matching background and text for %s in both themes', (control) => {
        expect(stylesFor(control)).toMatchObject({
            'background-color': '#fbfbfc',
            color: '#111618',
            'caret-color': '#111618',
        });
        expect(stylesFor(control, true)).toMatchObject({
            'background-color': '#0c0e10',
            color: '#e8ecee',
            'caret-color': '#e8ecee',
        });
    });

    it('preserves intentional utility overrides', () => {
        expect(stylesFor('<input class="text-ink bg-transparent">', true)['background-color']).toBe(
            'transparent'
        );
    });

    it('does not retheme legacy pages or checkbox controls', () => {
        expect(stylesFor('<input class="text-ink">', true, false)['background-color']).toBe('#fff');
        expect(stylesFor('<input type="checkbox">', true)['background-color']).toBe('#fff');
    });
});
