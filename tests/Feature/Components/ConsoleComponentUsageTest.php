<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * The console Modal renders only when its `open` prop is truthy and silently
 * ignores any prop it does not know. Passing `isOpen`, or leaving `open` out,
 * produces no error anywhere — the button is clicked and nothing appears.
 * Nine modals across five event panels shipped that way, so this reads every
 * <Modal> tag in the frontend and fails on either mistake.
 */
const MODAL_ACCEPTED_PROPS = ['open', 'onClose', 'title', 'className', 'children'];

/**
 * Every opening <Component ...> tag in a file with its line number.
 *
 * A recursive pattern keeps `>` inside JSX expressions such as
 * onClose={() => ...} from ending the tag early, and needs no offset
 * arithmetic — Pint's mb_str_functions rule would rewrite byte-based slicing
 * into character-based slicing and silently misread any file containing a
 * multibyte character.
 *
 * @return array<int, array{tag: string, line: int}>
 */
function consoleOpeningTags(string $source, string $component = 'Modal'): array
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
 * Prop names on an opening tag, including bare boolean props like `open`.
 *
 * String literals are blanked first — including template literals, whose
 * words and ${...} would otherwise read as props.
 *
 * @return array<int, string>
 */
function consolePropNames(string $tag): array
{
    $flattened = (string) preg_replace('/"[^"]*"|\'[^\']*\'|`[^`]*`/', '""', (string) preg_replace('/^<\w+/', '', mb_substr($tag, 0, -1)));

    for ($pass = 0; $pass < 8; $pass++) {
        $flattened = (string) preg_replace('/\{[^{}]*\}/', '{}', $flattened);
    }

    preg_match_all('/(?<=\s)([A-Za-z][\w-]*)(?=\s*=|\s|$)/', $flattened, $matches);

    return array_values(array_unique($matches[1]));
}

test('every console Modal is given open and no prop it would silently ignore', function () {
    $problems = [];

    $files = Finder::create()->files()->in(resource_path('js'))->name('*.jsx');

    foreach ($files as $file) {
        $source = $file->getContents();

        foreach (consoleOpeningTags($source) as ['tag' => $tag, 'line' => $line]) {
            if (str_contains($tag, '{...')) {
                continue;
            }

            $props = consolePropNames($tag);
            $where = $file->getRelativePathname().':'.$line;

            if (! in_array('open', $props, true)) {
                $problems[] = "{$where} has no `open` prop, so it can never appear";
            }

            foreach (array_diff($props, MODAL_ACCEPTED_PROPS) as $unknown) {
                $problems[] = "{$where} passes `{$unknown}`, which Modal ignores";
            }
        }
    }

    expect($problems)->toBe([]);
});

test('no console Select is given an options prop it would silently ignore', function () {
    /*
     * Select renders its children as the <option> elements and has no
     * `options` prop. An `options` array is spread onto the native <select>
     * as a meaningless attribute, leaving a dropdown with nothing in it — the
     * registration field type could never be changed from its default.
     */
    $problems = [];

    foreach (Finder::create()->files()->in(resource_path('js'))->name('*.jsx') as $file) {
        foreach (consoleOpeningTags($file->getContents(), 'Select') as ['tag' => $tag, 'line' => $line]) {
            if (in_array('options', consolePropNames($tag), true)) {
                $problems[] = $file->getRelativePathname().':'.$line.' passes `options`; render <option> children instead';
            }
        }
    }

    expect($problems)->toBe([]);
});
