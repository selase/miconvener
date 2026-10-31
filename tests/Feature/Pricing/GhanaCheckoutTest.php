<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\PaystackGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The Ghana price is charged only to an organisation that is Ghana eligible. One
 * that is not, and is offered it anyway, can pay only with Ghana mobile money
 * or a card, which is how it proves it. Everyone else pays USD converted to GHS.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Cache::flush();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.currency' => 'GHS', 'services.payment.default' => 'paystack', 'services.paystack.metadata_source' => 'miconvener']);
    Mail::fake();
    ExchangeRate::factory()->create(['rate' => 11.77, 'as_of' => now()->toDateString()]);
});

/**
 * @return array{0: User, 1: Tenant}
 */
function ghanaTestOwner(?string $region, string $plan = 'free'): array
{
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['package_id' => Package::query()->where('slug', $plan)->value('id'), 'meta' => ['paystack_id' => 'CUS_1'], 'pricing_region' => $region]);
    makeTenantOwner($user, $tenant);

    return [$user, $tenant];
}

/**
 * Expects one Paystack checkout for this many GHS minor units, and keeps what was sent.
 *
 * @param  array<string, mixed>|null  $sent
 */
function expectCheckoutOf(int $ghsMinor, ?array &$sent): void
{
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')->once()
        ->with('CUS_1', $ghsMinor, 'GHS', Mockery::any(), Mockery::on(function (array $metadata) use (&$sent): bool {
            $sent = $metadata;

            return true;
        }))
        ->andReturn('https://checkout.paystack.com/x');
    test()->swap(PaymentGateway::class, $gateway);
}

function buyStarter(User $user, Tenant $tenant, string $country, array $extra = []): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'starter', 'interval' => 'month'] + $extra, ['X-Inertia' => 'true', 'CF-IPCountry' => $country]);
}

test('an eligible organisation is charged the Ghana price, on every channel, from anywhere', function (string $country): void {
    [$user, $tenant] = ghanaTestOwner('GH');
    expectCheckoutOf(24900, $sent);

    buyStarter($user, $tenant, $country)->assertStatus(409);

    expect($sent['pricing'])->toMatchArray(['display_currency' => 'GHS', 'proof_required' => false])
        ->and($sent)->not->toHaveKey('allowed_channels');
})->with(['Ghana' => 'GH', 'abroad' => 'US']);

test('an organisation settled in USD is charged the USD equivalent, even from a Ghana address', function (?string $region, string $country): void {
    [$user, $tenant] = ghanaTestOwner($region);
    expectCheckoutOf(22363, $sent);

    buyStarter($user, $tenant, $country)->assertStatus(409);

    expect($sent['pricing'])->toMatchArray(['display_currency' => 'USD', 'display_amount_minor' => 1900, 'proof_required' => false])
        ->and($sent)->not->toHaveKey('allowed_channels');
})->with(['onboarded in USD, in Ghana' => ['INTL', 'GH'], 'unknown, abroad' => [null, 'US']]);

test('an organisation not yet eligible, in Ghana, is offered the Ghana price with mobile money and card only, and proof required', function (): void {
    [$user, $tenant] = ghanaTestOwner(null);
    expectCheckoutOf(24900, $sent);

    buyStarter($user, $tenant, 'GH')->assertStatus(409);

    expect($sent['pricing'])->toMatchArray(['display_currency' => 'GHS', 'proof_required' => true])
        ->and($sent['allowed_channels'])->toBe(['mobile_money', 'card']);
});

test('a posted currency or price changes nothing', function (): void {
    [$user, $tenant] = ghanaTestOwner(null);
    expectCheckoutOf(22363, $sent);

    buyStarter($user, $tenant, 'US', ['currency' => 'GHS', 'amount' => 1, 'price' => 1, 'pricing_region' => 'GH'])->assertStatus(409);

    expect($sent['pricing']['display_currency'])->toBe('USD')->and($tenant->fresh()->pricing_region)->toBeNull();
});

test('when USD is not available the fallback GHS price still needs proof from an organisation that has not given it', function (): void {
    ExchangeRate::query()->update(['as_of' => now()->subDays(20)->toDateString()]);
    [$user, $tenant] = ghanaTestOwner('INTL');
    expectCheckoutOf(24900, $sent);

    buyStarter($user, $tenant, 'US')->assertStatus(409);

    expect($sent['pricing']['proof_required'])->toBeTrue()->and($sent['allowed_channels'])->toBe(['mobile_money', 'card']);
});

test('an Enterprise agreed price is not subject to proof', function (): void {
    [$user, $tenant] = ghanaTestOwner('INTL', 'free');
    $tenant->update(['agreed_price_pesewas' => 250000, 'agreed_price_interval' => 'month']);
    expectCheckoutOf(250000, $sent);

    test()->actingAs($user)->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'enterprise'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])->assertStatus(409);

    expect($sent['pricing']['proof_required'])->toBeFalse()->and($sent)->not->toHaveKey('allowed_channels');
});

test('an add-on is priced by the same rule', function (): void {
    [$user, $tenant] = ghanaTestOwner('GH', 'growth');
    expectCheckoutOf(7500, $sent);

    $this->actingAs($user)->post(route('billing.addons.checkout', ['subdomain' => $tenant->slug]), ['addon_key' => 'team_seat'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])->assertStatus(409);

    expect($sent['pricing']['display_currency'])->toBe('GHS')->and($sent)->not->toHaveKey('allowed_channels');
});

test('AI tokens are priced by the same rule', function (): void {
    [$user, $tenant] = ghanaTestOwner(null, 'growth');
    expectCheckoutOf(6000, $sent);

    $this->actingAs($user)->post(route('billing.llm-checkout', ['subdomain' => $tenant->slug]), ['pack' => 'starter'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'GH'])->assertStatus(409);

    expect($sent['pricing']['proof_required'])->toBeTrue()->and($sent['allowed_channels'])->toBe(['mobile_money', 'card']);
});

test('Paystack is told to accept only the allowed channels, and nothing is restricted otherwise', function (): void {
    config(['services.paystack.secret_key' => 'sk_test']);
    Http::fake([
        'api.paystack.co/customer/*' => Http::response(['status' => true, 'data' => ['email' => 'owner@acme.test']]),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']]),
    ]);

    app(PaystackGateway::class)->createOneTimeCheckoutSession('CUS_1', 24900, 'GHS', 'https://x.test/cb', ['allowed_channels' => ['mobile_money', 'card']]);
    app(PaystackGateway::class)->createOneTimeCheckoutSession('CUS_1', 24900, 'GHS', 'https://x.test/cb', []);

    $payloads = Http::recorded(fn ($request): bool => str_contains($request->url(), 'transaction/initialize'))->map(fn ($pair) => $pair[0]->data())->values();
    expect($payloads[0]['channels'])->toBe(['mobile_money', 'card'])->and($payloads[1])->not->toHaveKey('channels');
});
