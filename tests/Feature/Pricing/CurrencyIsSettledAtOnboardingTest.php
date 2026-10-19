<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pricing\VisitorCurrency;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Nobody can choose the Ghana price. A visitor sees the currency their country
 * gives them; an organisation keeps the currency it was settled in at
 * onboarding, wherever its people are.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Cache::flush();
    Http::fake(['*' => Http::response('offline', 500)]);
    $this->seed(EventPackageSeeder::class);
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    ExchangeRate::factory()->create(['rate' => 11.77, 'as_of' => now()->toDateString()]);
});

function consolePlansProps(Tenant $tenant, User $user, string $country): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->get(route('tenant.pricing', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => $country]);
}

function ownerOfRegion(?string $region): array
{
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['pricing_region' => $region]);
    makeTenantOwner($user, $tenant);

    return [$tenant, $user];
}

test('a cookie asking for GHS from abroad still shows USD', function (): void {
    $this->withCookie(VisitorCurrency::COOKIE, 'GHS')->get('/', ['CF-IPCountry' => 'US'])
        ->assertSee('US$ 19', false)->assertDontSee('GHS 249', false);
});

test('a visitor in Ghana sees GHS, and may choose to see USD', function (): void {
    $this->get('/', ['CF-IPCountry' => 'GH'])->assertSee('GHS 249', false)->assertSee('Show prices in USD', false);
    $this->withCookie(VisitorCurrency::COOKIE, 'USD')->get('/', ['CF-IPCountry' => 'GH'])
        ->assertSee('US$ 19', false)->assertSee('Show prices in GHS', false);
});

test('a visitor abroad is not offered a switch at all', function (): void {
    $this->get('/', ['CF-IPCountry' => 'US'])->assertDontSee('data-currency-switch', false);
    $this->get('/', ['CF-IPCountry' => 'NG'])->assertDontSee('Show prices in GHS', false);
});

test('choosing GHS from abroad changes nothing, and a visitor in Ghana can choose either', function (): void {
    $this->post(route('currency.update'), ['currency' => 'GHS'], ['CF-IPCountry' => 'US'])->assertRedirect()->assertCookieMissing(VisitorCurrency::COOKIE);
    $this->post(route('currency.update'), ['currency' => 'USD'], ['CF-IPCountry' => 'GH'])->assertRedirect()->assertCookie(VisitorCurrency::COOKIE, 'USD');
    $this->post(route('currency.update'), ['currency' => 'GHS'], ['CF-IPCountry' => 'GH'])->assertRedirect()->assertCookieExpired(VisitorCurrency::COOKIE);
    $this->post(route('currency.update'), ['currency' => 'EUR'], ['CF-IPCountry' => 'GH'])->assertSessionHasErrors('currency');
});

test('an organisation onboarded in USD sees USD even from a Ghana address', function (): void {
    [$tenant, $user] = ownerOfRegion('INTL');

    consolePlansProps($tenant, $user, 'GH')->assertInertia(fn ($page) => $page->where('currency', 'USD'));
});

test('a Ghana eligible organisation sees GHS from any country, whatever the cookie says', function (): void {
    [$tenant, $user] = ownerOfRegion('GH');

    consolePlansProps($tenant, $user, 'US')->assertInertia(fn ($page) => $page->where('currency', 'GHS'));
    $this->withCookie(VisitorCurrency::COOKIE, 'USD');
    consolePlansProps($tenant, $user, 'NG')->assertInertia(fn ($page) => $page->where('currency', 'GHS'));
});

test('an organisation whose region is not known yet follows its country', function (): void {
    [$tenant, $user] = ownerOfRegion(null);

    consolePlansProps($tenant, $user, 'GH')->assertInertia(fn ($page) => $page->where('currency', 'GHS'));
    consolePlansProps($tenant, $user, 'US')->assertInertia(fn ($page) => $page->where('currency', 'USD'));
});

test('add-ons and AI tokens follow the organisation too', function (): void {
    [$tenant, $user] = ownerOfRegion('INTL');

    $this->actingAs($user)->get(route('billing.addons.index', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => 'GH'])->assertInertia(fn ($page) => $page->where('currency', 'USD'));
    $this->actingAs($user)->get(route('tenant.llm-usage.index', ['subdomain' => $tenant->slug]), ['CF-IPCountry' => 'GH'])->assertInertia(fn ($page) => $page->where('tokenPacks.0.price', 'US$ 5'));
});

test('signing up from abroad records that the organisation was settled in USD, and from Ghana leaves it open', function (): void {
    $register = fn (string $country, string $slug) => $this->withoutMiddleware(App\Http\Middleware\PreventRequestForgery::class)->post('/register', [
        'first_name' => 'Ada', 'last_name' => 'Osei', 'organization_name' => 'Acme '.$slug, 'email' => $slug.'@acme.test',
        'password' => 'Password1!', 'password_confirmation' => 'Password1!', 'plan' => 'free', 'slug' => $slug,
    ], ['CF-IPCountry' => $country]);

    $register('US', 'acme-us');
    $register('GH', 'acme-gh');

    expect(Tenant::query()->where('slug', 'acme-us')->value('pricing_region'))->toBe('INTL')
        ->and(Tenant::query()->where('slug', 'acme-gh')->value('pricing_region'))->toBeNull();
});
