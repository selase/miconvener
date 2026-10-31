<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Pricing\Money;
use App\Services\Pricing\VisitorCurrency;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The marketing pages that show who MiConvener is for. What they say lives in
 * config/use-cases.php so it can be read and corrected in one place.
 */
final class UseCaseController extends Controller
{
    public function index(): View
    {
        $cases = $this->cases();

        /** @var array<string, list<string>> $groups */
        $groups = config('use-cases.groups');

        return view('product.use-cases.index', [
            'hub' => config('use-cases.hub'),
            'groupMeta' => config('use-cases.group_meta'),
            'words' => config('use-cases.words'),
            'core' => config('use-cases.core'),
            'cases' => $cases,
            'groups' => array_map(
                fn (array $slugs): array => array_map(fn (string $slug): array => $cases[$slug], $slugs),
                $groups,
            ),
        ]);
    }

    public function show(Request $request, VisitorCurrency $visitorCurrency, string $slug): View
    {
        $cases = $this->cases();

        abort_unless(array_key_exists($slug, $cases), 404);

        $case = $cases[$slug];
        $currency = $visitorCurrency->resolve($request);
        $case['panels'] = array_map(fn (array $panel): array => $this->priced($panel, $currency), $case['panels'] ?? []);

        /** @var list<string> $related */
        $related = $case['related'] ?? [];

        return view('product.use-cases.show', [
            'case' => $case,
            'cases' => $cases,
            'related' => array_map(fn (string $other): array => $cases[$other], $related),
        ]);
    }

    /**
     * Every use case, keyed by slug, with its slug inside.
     *
     * @return array<string, array{panels?: array<string, array{title: string, columns: list<string>, rows: list<list<string|array{ghs: int, usd: int}>>}>, ...}>
     */
    private function cases(): array
    {
        /** @var array<string, array{panels?: array<string, array{title: string, columns: list<string>, rows: list<list<string|array{ghs: int, usd: int}>>}>, ...}> $cases */
        $cases = config('use-cases.cases');

        foreach ($cases as $slug => $case) {
            $cases[$slug] = ['slug' => $slug] + $case;
        }

        return $cases;
    }

    /**
     * Example prices are written in both currencies; show the one the visitor
     * sees MiConvener's own prices in.
     *
     * @param  array{title: string, columns: list<string>, rows: list<list<string|array{ghs: int, usd: int}>>}  $panel
     * @return array{title: string, columns: list<string>, rows: list<list<string>>}
     */
    private function priced(array $panel, string $currency): array
    {
        $panel['rows'] = array_map(fn (array $row): array => array_map(
            fn (string|array $cell): string => is_array($cell)
                ? (new Money(($currency === 'USD' ? $cell['usd'] : $cell['ghs']) * 100, $currency))->format()
                : $cell,
            $row,
        ), $panel['rows']);

        return $panel;
    }
}
