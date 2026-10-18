<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Models\Package;
use App\Services\Pricing\ChargeQuote;
use App\Services\Pricing\Money;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\VisitorCurrency;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Cache::flush();
    Http::fake(['*' => Http::response('offline', 500)]);
    ExchangeRate::factory()->create(['rate' => 11.77, 'as_of' => now()->toDateString()]);
});

function visitorRequest(array $headers = [], array $cookies = []): Request
{
    $request = Request::create('/', 'GET', [], $cookies);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

test('visitors in Ghana see GHS and everyone else sees USD', function () {
    $currency = app(VisitorCurrency::class);

    expect($currency->resolve(visitorRequest(['CF-IPCountry' => 'GH'])))->toBe('GHS')
        ->and($currency->resolve(visitorRequest(['CF-IPCountry' => 'NG'])))->toBe('USD')
        ->and($currency->resolve(visitorRequest(['CF-IPCountry' => 'US'])))->toBe('USD')
        ->and($currency->resolve(visitorRequest()))->toBe('USD');
});

test('a chosen currency wins over the country', function () {
    $currency = app(VisitorCurrency::class);

    expect($currency->resolve(visitorRequest(['CF-IPCountry' => 'US'], ['mc_currency' => 'GHS'])))->toBe('GHS')
        ->and($currency->resolve(visitorRequest(['CF-IPCountry' => 'GH'], ['mc_currency' => 'USD'])))->toBe('USD')
        ->and($currency->resolve(visitorRequest(['CF-IPCountry' => 'GH'], ['mc_currency' => 'EUR'])))->toBe('GHS');
});

test('the seeder gives each paid plan its USD price without overwriting one a superadmin set', function () {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);
    $starter = Package::query()->where('slug', 'starter')->firstOrFail();

    expect((float) $starter->price_usd)->toBe(19.0)->and((float) $starter->yearly_price_usd)->toBe(190.0);

    $starter->update(['price_usd' => 21]);
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);

    expect((float) $starter->fresh()->price_usd)->toBe(21.0);
});

test('the price book returns plan, add-on and token prices in each currency', function () {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);
    $book = app(PriceBook::class);
    $growth = Package::query()->where('slug', 'growth')->firstOrFail();

    expect($book->plan($growth, 'month', 'GHS'))->toEqual(new Money(49900, 'GHS'))
        ->and($book->plan($growth, 'year', 'USD'))->toEqual(new Money(39000, 'USD'))
        ->and($book->addon('sms_500', 'USD'))->toEqual(new Money(300, 'USD'))
        ->and($book->addon('sms_500', 'GHS', 2))->toEqual(new Money(7000, 'GHS'))
        ->and($book->tokenPack('standard', 'USD'))->toEqual(new Money(1500, 'USD'));
});

test('an item without a USD price, or paused USD, falls back to GHS', function () {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);
    $book = app(PriceBook::class);
    $starter = Package::query()->where('slug', 'starter')->firstOrFail();
    $starter->update(['price_usd' => null]);

    expect($book->plan($starter, 'month', 'USD'))->toEqual(new Money(24900, 'GHS'));

    ExchangeRate::query()->delete();
    Cache::flush();

    expect($book->addon('sms_500', 'USD'))->toEqual(new Money(3500, 'GHS'));
});

test('money formats whole amounts without decimals', function () {
    expect((new Money(24900, 'GHS'))->format())->toBe('GHS 249')
        ->and((new Money(1900, 'USD'))->format())->toBe('US$ 19')
        ->and((new Money(22363, 'GHS'))->format())->toBe('GHS 223.63');
});

test('a USD price converts to GHS at the current rate, rounded to the pesewa, with the rate recorded', function () {
    $quote = app(ChargeQuote::class)->for(new Money(1900, 'USD'));

    expect($quote->ghsMinor())->toBe(22363)
        ->and($quote->meta())->toMatchArray(['display_currency' => 'USD', 'display_amount_minor' => 1900, 'fx_rate' => 11.77, 'fx_source' => 'bank_of_ghana']);
});

test('a GHS price passes through unchanged with no rate', function () {
    $quote = app(ChargeQuote::class)->for(new Money(24900, 'GHS'));

    expect($quote->ghsMinor())->toBe(24900)->and($quote->meta()['fx_rate'])->toBeNull();
});

test('the currency switch remembers the choice and rejects other currencies', function () {
    $this->post(route('currency.update'), ['currency' => 'GHS'])->assertRedirect()->assertCookie('mc_currency', 'GHS');
    $this->post(route('currency.update'), ['currency' => 'EUR'])->assertSessionHasErrors('currency');
});
