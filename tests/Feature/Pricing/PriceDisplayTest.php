<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Pricing\VisitorCurrency;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Cache::flush();
    Http::fake(['*' => Http::response('offline', 500)]);
    $this->seed(EventPackageSeeder::class);
    ExchangeRate::factory()->create(['rate' => 11.77, 'as_of' => '2026-10-16']);
});

test('a visitor in Ghana sees the plans in GHS', function () {
    $this->get('/', ['CF-IPCountry' => 'GH'])
        ->assertOk()
        ->assertSee('GHS 249', false)
        ->assertDontSee('US$', false);
});

test('a visitor outside Ghana sees fixed USD prices and is told they are billed in GHS', function () {
    $this->get('/', ['CF-IPCountry' => 'US'])
        ->assertOk()
        ->assertSee('US$ 19', false)
        ->assertSee('Billed in GHS at checkout', false)
        ->assertDontSee('GHS 249', false);
});

test('a visitor in Ghana can choose to see USD on the pricing table', function () {
    $this->withCookie(VisitorCurrency::COOKIE, 'USD')
        ->get('/', ['CF-IPCountry' => 'GH'])
        ->assertSee('US$ 19', false);
});

test('while USD is paused for a stale rate, visitors abroad see GHS', function () {
    ExchangeRate::query()->update(['as_of' => now()->subDays(20)->toDateString()]);

    $this->get('/', ['CF-IPCountry' => 'US'])->assertSee('GHS 249', false)->assertDontSee('US$ 19', false);
});

test('the console plans page prices plans in the visitor currency with the GHS charge', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    makeTenantOwner($user, $tenant);

    $this->actingAs($user)
        ->get(route('tenant.pricing', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => 'NG'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currency', 'USD')
            ->where('packages', fn ($packages) => collect($packages)->contains(fn (array $p): bool => $p['slug'] === 'starter'
                && $p['price_label'] === 'US$ 19'
                && $p['yearly_price_label'] === 'US$ 190'
                && $p['charge_note'] === 'Charged as GHS 223.63 at the Bank of Ghana rate of 11.77 (16 Oct 2026).')));
});

test('a Ghana organisation sees console plan prices in GHS with no conversion note', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    makeTenantOwner($user, $tenant);

    $this->actingAs($user)
        ->get(route('tenant.pricing', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => 'GH'])
        ->assertInertia(fn ($page) => $page
            ->where('currency', 'GHS')
            ->where('packages', fn ($packages) => collect($packages)->contains(fn (array $p): bool => $p['slug'] === 'starter'
                && $p['price_label'] === 'GHS 249'
                && $p['charge_note'] === null)));
});

test('the add-ons page and AI token packs show prices in the visitor currency', function () {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    makeTenantOwner($user, $tenant);

    $this->actingAs($user)
        ->get(route('billing.addons.index', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => 'US'])
        ->assertInertia(fn ($page) => $page
            ->where('currency', 'USD')
            ->where('catalog', fn ($catalog) => collect($catalog)->contains(fn (array $item): bool => $item['key'] === 'team_seat'
                && $item['display_price'] === 'US$ 6'
                && str_starts_with((string) $item['charge_note'], 'Charged as GHS 70.62'))));

    $this->actingAs($user)
        ->get(route('tenant.llm-usage.index', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => 'US'])
        ->assertInertia(fn ($page) => $page
            ->where('tokenPacks.0.price', 'US$ 5')
            ->where('tokenPacks.0.charge_note', 'Charged as GHS 58.85 at the Bank of Ghana rate of 11.77 (16 Oct 2026).'));
});
