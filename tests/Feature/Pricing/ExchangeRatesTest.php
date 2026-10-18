<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Services\Pricing\ExchangeRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    refreshTenantDatabases();
    Cache::flush();
    config([
        'pricing.fx.bog_url' => 'https://bog.example/rates',
        'pricing.fx.republisher_url' => 'https://republisher.example/bog',
        'pricing.fx.republisher_key' => 'key-123',
    ]);
});

function fakeBogPage(): void
{
    Http::fake([
        'bog.example/*' => Http::response(file_get_contents(base_path('tests/Fixtures/pricing/bog-interbank.html'))),
        'republisher.example/*' => Http::response(['rate' => 11.7, 'date' => '2026-10-16']),
    ]);
}

test('it reads the Bank of Ghana mid rate and its date from the published page', function () {
    fakeBogPage();

    $rate = app(ExchangeRates::class)->refresh(force: true);

    expect($rate->rate)->toBe(11.665)
        ->and($rate->asOf->toDateString())->toBe('2026-10-16')
        ->and($rate->source)->toBe('bank_of_ghana')
        ->and(ExchangeRate::query()->count())->toBe(1);
});

test('the latest rate is cached, so repeated reads make no further requests', function () {
    fakeBogPage();
    $rates = app(ExchangeRates::class);

    $rates->usdToGhs();
    $rates->usdToGhs();
    $rates->usdToGhs();

    Http::assertSentCount(1);
});

test('refreshing again stores no duplicate row for the same published date', function () {
    fakeBogPage();
    $rates = app(ExchangeRates::class);

    $rates->refresh(force: true);
    $rates->refresh(force: true);

    expect(ExchangeRate::query()->count())->toBe(1);
});

test('when the Bank of Ghana page fails the backup source is used', function () {
    Http::fake([
        'bog.example/*' => Http::response('Service unavailable', 503),
        'republisher.example/*' => Http::response(['rate' => 11.7, 'date' => '2026-10-16']),
    ]);

    $rate = app(ExchangeRates::class)->refresh(force: true);

    expect($rate->rate)->toBe(11.7)->and($rate->source)->toBe('republisher');
});

test('when both sources fail the last stored rate is kept', function () {
    ExchangeRate::factory()->create(['rate' => 11.5, 'as_of' => '2026-10-15', 'source' => 'bank_of_ghana']);
    Http::fake(['*' => Http::response('down', 500)]);

    $rate = app(ExchangeRates::class)->refresh(force: true);

    expect($rate->rate)->toBe(11.5)->and($rate->asOf->toDateString())->toBe('2026-10-15');
});

test('a rate older than the alert threshold raises one operational warning a day', function () {
    CarbonImmutable::setTestNow('2026-10-21 10:00:00');
    ExchangeRate::factory()->create(['rate' => 11.5, 'as_of' => '2026-10-16', 'source' => 'bank_of_ghana']);
    Http::fake(['*' => Http::response('down', 500)]);
    Log::spy();

    $rates = app(ExchangeRates::class);
    $rates->refresh(force: true);
    $rates->refresh(force: true);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'pricing.fx.stale')->once();
    expect($rates->usdPaused())->toBeFalse();
    CarbonImmutable::setTestNow();
});

test('a rate older than the pause threshold pauses USD charging', function () {
    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    ExchangeRate::factory()->create(['rate' => 11.5, 'as_of' => '2026-10-16', 'source' => 'bank_of_ghana']);
    Http::fake(['*' => Http::response('down', 500)]);

    $rates = app(ExchangeRates::class);
    $rates->refresh(force: true);

    expect($rates->usdPaused())->toBeTrue();
    CarbonImmutable::setTestNow();
});

test('with no rate ever stored there is no rate and USD is paused', function () {
    Http::fake(['*' => Http::response('down', 500)]);

    $rates = app(ExchangeRates::class);

    expect($rates->usdToGhs())->toBeNull()->and($rates->usdPaused())->toBeTrue();
});

test('fx:refresh stores the published rate', function () {
    fakeBogPage();

    $this->artisan('fx:refresh', ['--force' => true])->assertSuccessful();

    expect(ExchangeRate::query()->where('source', 'bank_of_ghana')->value('rate'))->toEqual('11.665000');
});
