<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\RenewalScheduler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * Paystack settles the platform in GHS only: a USD price is charged as its GHS
 * equivalent at the Bank of Ghana rate on the day, and the rate is recorded.
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
function payingOwner(string $plan = 'free'): array
{
    $user = User::factory()->create(['email' => 'owner@acme.test']);
    $tenant = setActiveTenantForTest($user, ['package_id' => Package::query()->where('slug', $plan)->value('id'), 'meta' => ['paystack_id' => 'CUS_1']]);
    makeTenantOwner($user, $tenant);

    return [$user, $tenant];
}

/**
 * A gateway that expects one checkout for the given GHS amount and keeps its metadata.
 */
function expectGhsCheckout(int $ghsMinor, ?array &$metadata): void
{
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->with('CUS_1', $ghsMinor, 'GHS', Mockery::any(), Mockery::on(function (array $sent) use (&$metadata): bool {
            $metadata = $sent;

            return true;
        }))
        ->andReturn('https://checkout.paystack.com/x');
    test()->swap(PaymentGateway::class, $gateway);
}

test('a USD visitor buying a plan is charged its GHS equivalent, with the quote in the metadata', function (): void {
    [$user, $tenant] = payingOwner();
    expectGhsCheckout(22363, $metadata);

    $this->actingAs($user)
        ->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'starter', 'interval' => 'month'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])
        ->assertStatus(409);

    expect($metadata['pricing'])->toMatchArray(['display_currency' => 'USD', 'display_amount_minor' => 1900, 'fx_rate' => 11.77, 'fx_source' => 'bank_of_ghana']);
});

test('a Ghana visitor is charged the GHS list price exactly as before', function (): void {
    [$user, $tenant] = payingOwner();
    expectGhsCheckout(24900, $metadata);

    $this->actingAs($user)
        ->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'starter', 'interval' => 'month'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'GH'])
        ->assertStatus(409);

    expect($metadata['pricing']['display_currency'])->toBe('GHS')->and($metadata['pricing']['fx_rate'])->toBeNull();
});

test('while USD is paused a visitor abroad is charged the GHS list price', function (): void {
    ExchangeRate::query()->update(['as_of' => now()->subDays(20)->toDateString()]);
    [$user, $tenant] = payingOwner();
    expectGhsCheckout(24900, $metadata);

    $this->actingAs($user)
        ->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'starter', 'interval' => 'month'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])
        ->assertStatus(409);
});

test('an Enterprise agreed price is charged in GHS whatever the visitor currency', function (): void {
    [$user, $tenant] = payingOwner();
    $tenant->update(['agreed_price_pesewas' => 250000, 'agreed_price_interval' => 'month']);
    expectGhsCheckout(250000, $metadata);

    $this->actingAs($user)
        ->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'enterprise'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])
        ->assertStatus(409);
});

test('paying for a USD plan records the rate and makes the subscription renew in USD', function (): void {
    [$user, $tenant] = payingOwner();
    $pricing = ['display_currency' => 'USD', 'display_amount_minor' => 1900, 'fx_rate' => 11.77, 'fx_as_of' => '2026-10-16', 'fx_source' => 'bank_of_ghana'];
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('verifyTransaction')->andReturn([
        'status' => 'success', 'reference' => 'ref_usd_plan', 'amount' => 22363, 'currency' => 'GHS',
        'metadata' => ['type' => 'plan_subscription', 'plan_slug' => 'starter', 'interval' => 'month', 'tenant_id' => $tenant->id, 'pricing' => $pricing],
    ]);
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($user)->get(route('billing.callback', ['reference' => 'ref_usd_plan']))->assertRedirect();

    $transaction = Transaction::query()->where('provider_transaction_id', 'ref_usd_plan')->firstOrFail();
    expect($transaction->amount)->toEqual(22363)
        ->and($transaction->meta['pricing'])->toEqual($pricing)
        ->and(Subscription::query()->where('tenant_id', $tenant->id)->latest('id')->value('billing_currency'))->toBe('USD');
});

test('the webhook also records the rate and the billing currency', function (): void {
    [, $tenant] = payingOwner();
    config(['services.paystack.secret_key' => 'sk_test']);
    $pricing = ['display_currency' => 'USD', 'display_amount_minor' => 1900, 'fx_rate' => 11.77, 'fx_as_of' => '2026-10-16', 'fx_source' => 'bank_of_ghana'];
    $payload = json_encode(['event' => 'charge.success', 'data' => [
        'reference' => 'ref_usd_hook', 'status' => 'success', 'amount' => 22363, 'currency' => 'GHS',
        'customer' => ['email' => 'owner@acme.test'],
        'metadata' => ['type' => 'plan_subscription', 'plan_slug' => 'starter', 'interval' => 'month', 'tenant_id' => $tenant->id, 'source' => 'miconvener', 'pricing' => $pricing],
    ]]);

    $this->call('POST', route('webhooks.paystack'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'sk_test')], $payload)->assertOk();

    expect(Transaction::query()->where('provider_transaction_id', 'ref_usd_hook')->firstOrFail()->meta['pricing'])->toEqual($pricing)
        ->and(Subscription::query()->where('tenant_id', $tenant->id)->latest('id')->value('billing_currency'))->toBe('USD');
});

test('a USD subscription renews at the rate on the renewal day, not the original one', function (): void {
    [$user, $tenant] = payingOwner('growth');
    $subscription = Subscription::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_first', 'provider_status' => 'active',
        'provider_plan' => 'growth_month', 'interval' => 'month', 'current_period_end' => now()->addDays(2), 'billing_currency' => 'USD',
    ]);
    ExchangeRate::factory()->create(['rate' => 12.5, 'as_of' => now()->addDay()->toDateString()]);
    Cache::flush();

    expect($subscription->priceMinorFor(Package::query()->where('slug', 'growth')->firstOrFail()))->toBe(48750);

    expectGhsCheckout(48750, $metadata);
    $this->actingAs($user)
        ->get(route('billing.renew', ['subdomain' => $tenant->slug]), ['X-Inertia' => 'true'])
        ->assertStatus(409);

    expect($metadata['pricing'])->toMatchArray(['display_currency' => 'USD', 'display_amount_minor' => 3900, 'fx_rate' => 12.5]);
});

test('a USD auto-renewal charges the converted amount and records the rate', function (): void {
    config(['services.paystack.secret_key' => 'sk_test']);
    [, $tenant] = payingOwner('growth');
    $subscription = Subscription::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_first', 'provider_status' => 'active',
        'provider_plan' => 'growth_month', 'interval' => 'month', 'current_period_end' => now()->subHour(), 'billing_currency' => 'USD',
        'authorization_code' => 'AUTH_card', 'authorization_reusable' => true, 'authorization_email' => 'payer@example.com', 'authorization_label' => 'Visa 4081',
    ]);
    Http::fake(['api.paystack.co/transaction/charge_authorization' => fn ($request) => Http::response(['status' => true, 'data' => [
        'status' => 'success', 'reference' => $request['reference'], 'amount' => 45903, 'currency' => 'GHS', 'channel' => 'card',
        'authorization' => ['authorization_code' => 'AUTH_card', 'reusable' => true, 'channel' => 'card', 'brand' => 'visa', 'last4' => '4081'],
    ]])]);

    app(RenewalScheduler::class)->process($subscription);

    Http::assertSent(fn ($request): bool => $request['amount'] === 45903 && $request['currency'] === 'GHS' && $request['metadata']['pricing']['display_amount_minor'] === 3900);
    expect(Transaction::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail()->meta['pricing']['fx_rate'])->toBe(11.77);
});

test('a USD visitor buying an add-on is charged in GHS and the add-on records what was charged', function (): void {
    [$user, $tenant] = payingOwner('growth');
    expectGhsCheckout(14124, $metadata);

    $this->actingAs($user)
        ->post(route('billing.addons.checkout', ['subdomain' => $tenant->slug]), ['addon_key' => 'team_seat', 'multiplier' => 2], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])
        ->assertStatus(409);

    expect($metadata['total_price'])->toBe(14124)->and($metadata['pricing']['display_amount_minor'])->toBe(1200);

    $addon = app(App\Services\Billing\TenantAddonService::class)->fulfillAddonPurchase($tenant, 'ref_addon_usd', $metadata);

    expect($addon->total_price)->toBe(14124)
        ->and(Transaction::query()->where('provider_transaction_id', 'ref_addon_usd')->firstOrFail()->meta['pricing']['display_currency'])->toBe('USD');
});

test('a USD visitor buying AI tokens is charged in GHS and the purchase records the amount paid', function (): void {
    [$user, $tenant] = payingOwner('growth');
    expectGhsCheckout(5885, $metadata);

    $this->actingAs($user)
        ->post(route('billing.llm-checkout', ['subdomain' => $tenant->slug]), ['pack' => 'starter'], ['X-Inertia' => 'true', 'CF-IPCountry' => 'US'])
        ->assertStatus(409);

    app(App\Services\Billing\PaymentFulfillmentService::class)->fulfillLlmTokenPurchase($tenant, 'ref_tokens_usd', $metadata, 5885);

    $transaction = Transaction::query()->where('provider_transaction_id', 'ref_tokens_usd')->firstOrFail();
    expect($transaction->amount)->toEqual(5885)
        ->and(mb_strtoupper($transaction->currency))->toBe('GHS')
        ->and($transaction->meta['pricing']['display_amount_minor'])->toBe(500);
});
