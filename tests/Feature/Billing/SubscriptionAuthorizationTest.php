<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\ComplimentaryPlans;
use App\Services\Payment\PaystackGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * A renewal can only charge the customer again if the first payment's
 * authorization was kept, and only if Paystack said it is reusable.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config([
        'services.paystack.secret_key' => 'sk_one_account',
        'services.settlement.paystack.secret_key' => 'sk_one_account',
        'services.paystack.metadata_source' => 'miconvener',
    ]);
    Mail::fake();
});

/**
 * @return array{0: Tenant, 1: User}
 */
function authorizationTenant(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');

    return [$tenant, $owner];
}

/**
 * @param  array<string, mixed>  $authorization
 * @return array<string, mixed>
 */
function planCharge(Tenant $tenant, array $authorization, string $interval = 'month'): array
{
    return [
        'id' => 51515151,
        'reference' => 'ref_plan_auth',
        'status' => 'success',
        'amount' => 9900,
        'currency' => 'GHS',
        'channel' => $authorization['channel'] ?? 'card',
        'authorization' => $authorization,
        'customer' => ['email' => 'Owner@Example.com'],
        'metadata' => ['source' => 'miconvener', 'type' => 'plan_subscription', 'plan_slug' => 'growth', 'interval' => $interval, 'tenant_id' => $tenant->id],
    ];
}

function postPlanWebhook(array $charge): void
{
    $body = json_encode(['event' => 'charge.success', 'data' => $charge]);
    test()->call('POST', '/webhooks/paystack', [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();
}

test('a card plan payment keeps its reusable authorization, encrypted', function (): void {
    [$tenant] = authorizationTenant();

    postPlanWebhook(planCharge($tenant, ['authorization_code' => 'AUTH_card_1', 'reusable' => true, 'channel' => 'card', 'brand' => 'visa', 'last4' => '4081'], 'year'));

    $subscription = Subscription::query()->where('tenant_id', $tenant->id)->sole();

    expect($subscription->authorization_code)->toBe('AUTH_card_1')
        ->and($subscription->authorization_reusable)->toBeTrue()
        ->and($subscription->authorization_email)->toBe('owner@example.com')
        ->and($subscription->authorization_label)->toBe('Visa card ending 4081')
        ->and($subscription->interval)->toBe('year')
        ->and($subscription->canBeChargedAutomatically())->toBeTrue()
        ->and(DB::connection('landlord')->table('subscriptions')->where('id', $subscription->id)->value('authorization_code'))->not->toContain('AUTH_card_1')
        ->and($subscription->toArray())->not->toHaveKey('authorization_code');
});

test('a mobile money payment Paystack will not reuse is kept but never auto-charged', function (): void {
    [$tenant] = authorizationTenant();

    postPlanWebhook(planCharge($tenant, ['authorization_code' => 'AUTH_momo_1', 'reusable' => false, 'channel' => 'mobile_money']));

    $subscription = Subscription::query()->where('tenant_id', $tenant->id)->sole();

    expect($subscription->authorization_reusable)->toBeFalse()
        ->and($subscription->authorization_label)->toBe('Mobile money')
        ->and($subscription->canBeChargedAutomatically())->toBeFalse();
});

test('the browser callback keeps the authorization too', function (): void {
    [$tenant, $owner] = authorizationTenant();
    app()->instance(PaymentGateway::class, new PaystackGateway(['secret_key' => 'sk_one_account']));
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => planCharge($tenant, ['authorization_code' => 'AUTH_card_2', 'reusable' => true, 'channel' => 'card', 'brand' => 'mastercard', 'last4' => '1111'])])]);
    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($owner)->get("http://{$host}/billing/callback?reference=ref_plan_auth", ['HTTP_HOST' => $host])->assertRedirect();

    $subscription = Subscription::query()->where('tenant_id', $tenant->id)->sole();

    expect($subscription->authorization_code)->toBe('AUTH_card_2')
        ->and($subscription->canBeChargedAutomatically())->toBeTrue()
        ->and($subscription->interval)->toBe('month');
});

test('tenants on a paid plan they never paid for are marked complimentary; payers are not', function (): void {
    $growth = Package::query()->where('slug', 'growth')->firstOrFail();
    $free = Package::query()->where('slug', 'free')->firstOrFail();
    $handMade = Tenant::factory()->create(['package_id' => $growth->id]);
    $payer = Tenant::factory()->create(['package_id' => $growth->id]);
    $freeTenant = Tenant::factory()->create(['package_id' => $free->id]);
    Transaction::query()->create(['tenant_id' => $payer->id, 'provider' => 'paystack', 'provider_transaction_id' => 'ref_paid_plan', 'amount' => 9900, 'currency' => 'ghs', 'status' => 'success', 'type' => 'charge', 'meta' => ['package_id' => $growth->id]]);

    expect(app(ComplimentaryPlans::class)->markHandProvisionedTenants())->toBe(1)
        ->and($handMade->fresh()->billing_complimentary)->toBeTrue()
        ->and($payer->fresh()->billing_complimentary)->toBeFalse()
        ->and($freeTenant->fresh()->billing_complimentary)->toBeFalse();
});

test('a yearly subscription costs the yearly price and a monthly one the monthly price', function (): void {
    $growth = Package::query()->where('slug', 'growth')->firstOrFail();

    expect((new Subscription(['interval' => 'year']))->priceMinorFor($growth))->toBe((int) round((float) $growth->yearly_price * 100))
        ->and((new Subscription(['provider_plan' => 'growth_month']))->priceMinorFor($growth))->toBe(9900)
        ->and((new Subscription(['provider_plan' => 'growth_year']))->billingInterval())->toBe('year');
});
