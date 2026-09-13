<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Most console components destructure a fixed set of props and do not forward
 * the rest, so a misnamed prop is dropped without an error anywhere. That is
 * how nine dialogs and seven delete confirmations shipped unable to open:
 * they passed `isOpen`, `onCancel` and `message`, none of which exist. These
 * tests read each component's own signature and every place it is used.
 */

/**
 * Props each console component accepts, keyed by exported component name.
 * Components that forward `...props` are omitted — any prop is valid there.
 *
 * @return array<string, array<int, string>>
 */
function consoleComponentProps(): array
{
    $components = [];

    foreach (Finder::create()->files()->in(resource_path('js/Components/Console'))->name('*.jsx') as $file) {
        preg_match_all('/export (?:default )?function (\w+)\(\s*\{(.*?)\}\s*\)/s', $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as [, $name, $signature]) {
            if (str_contains($signature, '...')) {
                continue;
            }

            $withoutDefaults = (string) preg_replace('/=\s*(\'[^\']*\'|"[^"]*"|[^,]+)/', '', $signature);
            $props = array_filter(array_map(
                fn (string $part): string => mb_trim(explode(':', $part)[0]),
                explode(',', $withoutDefaults),
            ));

            $components[$name] = [...array_values($props), 'key', 'children'];
        }
    }

    return $components;
}

/**
 * Every opening <Component ...> tag in a source file, with its line number.
 *
 * A recursive pattern keeps `>` inside JSX expressions such as
 * onClick={() => ...} from ending the tag early. It needs no offset slicing,
 * which Pint's mb_str_functions rule would turn into character arithmetic
 * over byte offsets and misread any file with a multibyte character.
 *
 * @return array<int, array{tag: string, line: int}>
 */
function consoleOpeningTags(string $source, string $component): array
{
    $pattern = '/<'.$component.'\b(?:[^{}>]++|(?<brace>\{(?:[^{}]++|(?&brace))*\}))*>/u';

    preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $match): array => [
        'tag' => $match[0],
        // mb_strcut takes a byte offset, which is what PREG_OFFSET_CAPTURE reports.
        'line' => mb_substr_count(mb_strcut($source, 0, $match[1]), "\n") + 1,
    ], $matches[0]);
}

/**
 * Prop names on an opening tag, including bare boolean props like `danger`.
 *
 * String literals are blanked, then JSX expressions are collapsed innermost
 * first into a brace-free placeholder, so text and props of JSX nested inside
 * a prop — actions={<Button icon={...}>Edit</Button>} — never read as props of
 * the outer tag.
 *
 * @return array<int, string>
 */
function consolePropNames(string $tag): array
{
    $flattened = (string) preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/', '""', (string) preg_replace('/^<\w+/', '', mb_substr($tag, 0, -1)));

    do {
        $before = $flattened;
        $flattened = (string) preg_replace('/\{[^{}]*\}/', '§', $flattened);
    } while ($flattened !== $before);

    preg_match_all('/(?<=\s)([A-Za-z][\w-]*)(?=\s*=|\s|\/$|$)/', $flattened, $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * Every usage of a console component outside the component directory.
 *
 * @return iterable<int, array{where: string, tag: string}>
 */
function consoleUsages(string $component): iterable
{
    $files = Finder::create()->files()->in(resource_path('js'))->name('*.jsx')->notPath('Components/Console');

    foreach ($files as $file) {
        $source = $file->getContents();

        if (! preg_match('/import[^;]*\b'.$component.'\b[^;]*from\s+[\'"][^\'"]*Console/', $source)) {
            continue;
        }

        foreach (consoleOpeningTags($source, $component) as ['tag' => $tag, 'line' => $line]) {
            yield ['where' => $file->getRelativePathname().':'.$line, 'tag' => $tag];
        }
    }
}

test('every console component is used only with props it actually accepts', function () {
    $problems = [];

    foreach (consoleComponentProps() as $component => $accepted) {
        foreach (consoleUsages($component) as ['where' => $where, 'tag' => $tag]) {
            if (str_contains($tag, '{...')) {
                continue;
            }

            foreach (consolePropNames($tag) as $prop) {
                if (! in_array($prop, $accepted, true) && ! str_starts_with($prop, 'aria-') && ! str_starts_with($prop, 'data-')) {
                    $problems[] = "{$where} passes `{$prop}` to {$component}, which ignores it";
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

test('every console overlay is given open, or it can never appear', function () {
    $problems = [];

    foreach (['Modal', 'ConfirmModal', 'Drawer'] as $overlay) {
        foreach (consoleUsages($overlay) as ['where' => $where, 'tag' => $tag]) {
            if (! str_contains($tag, '{...') && ! in_array('open', consolePropNames($tag), true)) {
                $problems[] = "{$where} renders {$overlay} without `open`";
            }
        }
    }

    expect($problems)->toBe([]);
});

test('no console Select is given an options prop it would silently ignore', function () {
    /*
     * Select forwards unknown props to the native <select>, so the general
     * test cannot see this one. It renders its children as the <option>
     * elements; an `options` array becomes a meaningless attribute and the
     * dropdown is empty.
     */
    $problems = [];

    foreach (consoleUsages('Select') as ['where' => $where, 'tag' => $tag]) {
        if (in_array('options', consolePropNames($tag), true)) {
            $problems[] = "{$where} passes `options`; render <option> children instead";
        }
    }

    expect($problems)->toBe([]);
});
