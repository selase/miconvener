<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The marketing pages that show who MiConvener is for. What they say lives in
 * config/use-cases.php so it can be read and corrected in one place.
 */
final class UseCaseController extends Controller
{
    public function index(): View
    {
        /** @var array<string, array<string, mixed>> $cases */
        $cases = config('use-cases.cases');

        /** @var array<string, list<string>> $groups */
        $groups = config('use-cases.groups');

        return view('product.use-cases.index', [
            'hub' => config('use-cases.hub'),
            'groups' => array_map(
                fn (array $slugs): array => array_map(fn (string $slug): array => ['slug' => $slug] + $cases[$slug], $slugs),
                $groups,
            ),
        ]);
    }

    public function show(string $slug): View
    {
        /** @var array<string, array<string, mixed>> $cases */
        $cases = config('use-cases.cases');

        abort_unless(array_key_exists($slug, $cases), 404);

        /** @var list<string> $related */
        $related = $cases[$slug]['related'] ?? [];

        return view('product.use-cases.show', [
            'case' => ['slug' => $slug] + $cases[$slug],
            'related' => array_map(fn (string $other): array => ['slug' => $other] + $cases[$other], $related),
        ]);
    }
}
