<?php

declare(strict_types=1);

use App\Models\Package;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The use-case pages say who MiConvener is for. They may only promise what the
 * product does, so these tests also guard the claims that are easiest to get
 * wrong: what is not built yet, and what each plan includes.
 */
test('the hub lists every use case, grouped, with a link to each', function (): void {
    $page = $this->get(route('use-cases.index'))->assertOk()
        ->assertSee('Made for every kind of gathering')
        ->assertSee('Conferences and professional events')
        ->assertSee('Communities and celebrations');

    foreach (array_keys(config('use-cases.cases')) as $slug) {
        $page->assertSee(route('use-cases.show', $slug), false);
    }
});

test('each use case has its own page with its headline, features and timeline', function (string $slug): void {
    $case = config("use-cases.cases.{$slug}");

    $page = $this->get(route('use-cases.show', $slug))->assertOk()
        ->assertSee($case['headline'])
        ->assertSee('Who it is for')
        ->assertSee('What you get')
        ->assertSee('How it runs')
        ->assertSee($case['value']['title']);

    foreach ($case['features'] as $feature) {
        $page->assertSee($feature['title']);
    }
})->with(fn () => array_keys((require __DIR__.'/../../../config/use-cases.php')['cases']));

test('an unknown use case is a 404', function (): void {
    $this->get('/use-cases/political-fundraising')->assertNotFound();
});

test('every related use case and every group entry exists', function (): void {
    $cases = config('use-cases.cases');

    foreach ($cases as $slug => $case) {
        foreach ($case['related'] ?? [] as $related) {
            $this->assertArrayHasKey($related, $cases, "{$slug} links to a use case that does not exist");
        }
    }

    foreach (config('use-cases.groups') as $group => $members) {
        foreach ($members as $member) {
            $this->assertArrayHasKey($member, $cases, "{$group} lists a use case that does not exist");
        }
    }

    $grouped = collect(config('use-cases.groups'))->flatten()->all();
    expect(array_diff(array_keys($cases), $grouped))->toBe([], 'a use case is on no hub group');
});

test('no page promises what is not built: dues, anniversaries and surveys', function (): void {
    $copy = mb_strtolower(json_encode(config('use-cases'), JSON_THROW_ON_ERROR));

    foreach (['dues', 'anniversary reminder', 'survey'] as $notBuilt) {
        expect($copy)->not->toMatch('/\b'.preg_quote($notBuilt, '/').'/');
    }
});

test('no page names a customer or quotes one', function (): void {
    $copy = mb_strtolower(json_encode(config('use-cases'), JSON_THROW_ON_ERROR));

    foreach (['ugmc', 'nkabom', 'testimonial'] as $banned) {
        expect($copy)->not->toContain($banned);
    }
});

test('the live classroom page says live polling is included from Growth, and the plans agree', function (): void {
    $this->seed(EventPackageSeeder::class);

    $this->get(route('use-cases.show', 'classroom'))->assertSee('included from the Growth plan');

    $included = Package::query()->get()
        ->filter(fn (Package $package): bool => (bool) DB::connection('landlord')->table('package_features')
            ->join('features', 'features.id', '=', 'package_features.feature_id')
            ->where('package_features.package_id', $package->id)
            ->where('features.slug', 'live_polling')
            ->value('package_features.value'))
        ->pluck('slug')->sort()->values()->all();

    expect($included)->toBe(['enterprise', 'growth']);
});

test('the navigation and footer link to the use cases', function (): void {
    $this->get(route('home'))->assertOk()->assertSee(route('use-cases.index'), false);
});

test('each use case page opens with a black hero, its own line illustration and no buttons', function (string $slug): void {
    $html = $this->get(route('use-cases.show', $slug))->assertOk()->getContent();

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $hero = $xpath->query('//*[@data-use-case-hero]')->item(0);

    expect($hero)->not->toBeNull()
        ->and(view()->exists("product.use-cases.art.{$slug}"))->toBeTrue()
        ->and($xpath->query('.//svg[@data-use-case-art]', $hero)->length)->toBe(1)
        ->and($xpath->query('.//svg[@data-use-case-art]', $hero)->item(0)->getAttribute('aria-hidden'))->toBe('true')
        ->and($xpath->query('.//a[contains(@href, "/register")]', $hero)->length)->toBe(0)
        ->and(str_contains($hero->textContent, config("use-cases.cases.{$slug}.headline")))->toBeTrue();
})->with(fn () => array_keys((require __DIR__.'/../../../config/use-cases.php')['cases']));
