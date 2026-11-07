<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Mail\Billing\PaymentReceiptMail;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\SubscriptionRenewalService;
use App\Services\Payment\PaystackGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

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
    $this->travelTo('2026-10-10 08:00:00');
});

/**
 * @return array{0: Tenant, 1: Subscription, 2: Package, 3: User}
 */
function renewingTenant(string $periodEnd = '2026-10-10 17:00:00', string $status = Subscription::STATUS_ACTIVE, string $plan = 'growth'): array
{
    $package = Package::query()->where('slug', $plan)->firstOrFail();
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared', 'package_id' => $package->id, 'email' => 'accounts@acme.test']);
    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($owner->id);
    setPermissionsTeamId($tenant->id);
    $owner->assignRole('Org Superadmin');

    $subscription = Subscription::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'ps_first',
        'provider_status' => $status,
        'provider_plan' => $plan.'_month',
        'interval' => 'month',
        'current_period_end' => $periodEnd,
    ]);

    return [$tenant, $subscription, $package, $owner];
}

test('a renewal extends the period from where it ended, once per reference', function (): void {
    [$tenant, $subscription, $package] = renewingTenant();
    $service = app(SubscriptionRenewalService::class);

    expect($service->recordRenewal($subscription, $package, 'renew-1', 9900, 'GHS'))->toBeTrue()
        ->and($service->recordRenewal($subscription, $package, 'renew-1', 9900, 'GHS'))->toBeFalse();

    $subscription->refresh();

    expect($subscription->current_period_end->toDateTimeString())->toBe('2026-11-10 17:00:00')
        ->and($subscription->provider_status)->toBe('active')
        ->and(Transaction::query()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(Transaction::query()->where('provider_transaction_id', 'renew-1')->value('meta')['type'])->toBe('plan_renewal');
});

test('paying during grace continues the old period instead of starting a new one', function (): void {
    [, $subscription, $package] = renewingTenant(periodEnd: '2026-10-05 17:00:00', status: Subscription::STATUS_PAST_DUE);
    $subscription->update(['grace_ends_at' => '2026-10-12 17:00:00', 'renewal_attempts' => 3]);

    app(SubscriptionRenewalService::class)->recordRenewal($subscription, $package, 'renew-grace', 9900, 'GHS');

    $subscription->refresh();

    expect($subscription->current_period_end->toDateTimeString())->toBe('2026-11-05 17:00:00')
        ->and($subscription->grace_ends_at)->toBeNull()
        ->and($subscription->renewal_attempts)->toBe(0)
        ->and($subscription->provider_status)->toBe('active');
});

test('paying after the plan lapsed to Free restores it from today', function (): void {
    [$tenant, $subscription, $package] = renewingTenant(periodEnd: '2026-09-01 17:00:00', status: Subscription::STATUS_CANCELLED);
    $tenant->update(['package_id' => Package::query()->where('slug', 'free')->value('id')]);

    app(SubscriptionRenewalService::class)->recordRenewal($subscription, $package, 'renew-late', 9900, 'GHS');

    expect($subscription->fresh()->current_period_end->toDateTimeString())->toBe('2026-11-10 08:00:00')
        ->and($tenant->fresh()->package_id)->toEqual($package->id);
});

test('a scheduled downgrade to a cheaper paid plan is what gets renewed', function (): void {
    [$tenant, $subscription] = renewingTenant();
    $starter = Package::query()->where('slug', 'starter')->firstOrFail();
    $subscription->update(['pending_package_id' => $starter->id]);
    $service = app(SubscriptionRenewalService::class);

    expect($service->packageToRenew($subscription)?->slug)->toBe('starter')
        ->and($subscription->priceMinorFor($starter))->toBe(24900);

    $service->recordRenewal($subscription, $starter, 'renew-down', 24900, 'GHS');

    expect($tenant->fresh()->package_id)->toEqual($starter->id)
        ->and($subscription->fresh()->pending_package_id)->toBeNull()
        ->and($subscription->fresh()->provider_plan)->toBe('starter_month');
});

test('a renewal webhook with string metadata is routed, recorded once and receipted once', function (): void {
    [$tenant, $subscription, $package] = renewingTenant();
    $charge = [
        'reference' => 'renew-webhook',
        'status' => 'success',
        'amount' => 9900,
        'currency' => 'GHS',
        'channel' => 'card',
        'authorization' => ['authorization_code' => 'AUTH_new', 'reusable' => true, 'channel' => 'card', 'brand' => 'visa', 'last4' => '4242'],
        'customer' => ['email' => 'someone-who-left@example.com'],
        'metadata' => json_encode(['source' => 'miconvener', 'type' => 'plan_renewal', 'subscription_id' => $subscription->id, 'package_id' => $package->id]),
    ];

    foreach ([1, 2] as $delivery) {
        $body = json_encode(['event' => 'charge.success', 'data' => $charge]);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    }

    $subscription->refresh();

    expect($subscription->current_period_end->toDateString())->toBe('2026-11-10')
        ->and($subscription->canBeChargedAutomatically())->toBeTrue()
        ->and($subscription->authorization_label)->toBe('Visa card ending 4242')
        ->and(Transaction::query()->where('tenant_id', $tenant->id)->count())->toBe(1);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->description === 'Growth plan, billed monthly'
        && $mail->hasTo('accounts@acme.test'));
});

test('a renewal paid by link is recorded by the browser callback and the webhook once', function (): void {
    [$tenant, $subscription, $package, $owner] = renewingTenant();
    app()->instance(PaymentGateway::class, new PaystackGateway(['secret_key' => 'sk_one_account']));
    $charge = [
        'id' => 777,
        'reference' => 'renew-link',
        'status' => 'success',
        'amount' => 9900,
        'currency' => 'GHS',
        'channel' => 'mobile_money',
        'authorization' => ['authorization_code' => 'AUTH_momo', 'reusable' => false, 'channel' => 'mobile_money'],
        'customer' => ['email' => 'owner@example.com'],
        'metadata' => ['source' => 'miconvener', 'type' => 'plan_renewal', 'subscription_id' => $subscription->id, 'package_id' => $package->id],
    ];
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => $charge])]);
    $host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');

    $this->actingAs($owner)->get("http://{$host}/billing/callback?reference=renew-link", ['HTTP_HOST' => $host])
        ->assertRedirect()
        ->assertSessionHas('success', 'Payment received. Your Growth plan is renewed until 10 November 2026.');

    $body = json_encode(['event' => 'charge.success', 'data' => $charge]);
    $this->call('POST', '/webhooks/paystack', [], [], [], ['HTTP_x-paystack-signature' => hash_hmac('sha512', $body, 'sk_one_account'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

    expect($subscription->fresh()->current_period_end->toDateString())->toBe('2026-11-10')
        ->and($subscription->fresh()->canBeChargedAutomatically())->toBeFalse()
        ->and(Transaction::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    Mail::assertQueuedCount(1);
});

test('charging a saved authorization sends the reference and metadata Paystack routes on', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => [
        'status' => 'failed', 'reference' => 'renew-x', 'gateway_response' => 'Insufficient Funds', 'amount' => 9900, 'currency' => 'GHS', 'channel' => 'card',
    ]])]);

    $result = (new PaystackGateway(['secret_key' => 'sk_one_account']))->chargeAuthorization('owner@example.com', 9900, 'GHS', 'AUTH_card', 'renew-x', ['source' => 'miconvener', 'type' => 'plan_renewal']);

    expect($result['status'])->toBe('failed')->and($result['gateway_response'])->toBe('Insufficient Funds');
    Http::assertSent(fn ($request): bool => $request['reference'] === 'renew-x'
        && $request['authorization_code'] === 'AUTH_card'
        && $request['amount'] === 9900
        && $request['metadata']['type'] === 'plan_renewal');
});

test('a plan cancelled for the end of its period has nothing to renew', function (): void {
    [, $subscription] = renewingTenant();
    $subscription->update(['pending_package_id' => Package::query()->where('slug', 'free')->value('id')]);

    expect(app(SubscriptionRenewalService::class)->packageToRenew($subscription))->toBeNull();
});

test('monthly calendar anchors clamp February and recover the intended day', function (int $day, string $february, string $march): void {
    $this->travelTo('2027-01-30 08:00:00');
    [, $subscription, $package] = renewingTenant('2027-01-'.$day.' 17:23:45');
    $service = app(SubscriptionRenewalService::class);
    $service->recordRenewal($subscription, $package, 'calendar-first', 9900, 'GHS');
    expect($subscription->fresh()->current_period_end->toDateTimeString())->toBe($february.' 17:23:45');
    $this->travelTo($february.' 08:00:00');
    $service->recordRenewal($subscription->fresh(), $package, 'calendar-second', 9900, 'GHS');
    expect($subscription->fresh()->current_period_end->toDateTimeString())->toBe($march.' 17:23:45');
    expect($service->recordRenewal($subscription->fresh(), $package, 'calendar-second', 9900, 'GHS'))->toBeFalse();
    expect($subscription->fresh()->current_period_end->toDateTimeString())->toBe($march.' 17:23:45');
})->with([[31, '2027-02-28', '2027-03-31'], [30, '2027-02-28', '2027-03-30']]);

test('annual calendar anchors preserve leap-day through the next leap year', function (): void {
    $this->travelTo('2028-02-29 08:00:00');
    [, $subscription, $package] = renewingTenant('2028-02-29 17:23:45');
    $subscription->update(['interval' => 'year', 'provider_plan' => 'growth_year']);
    $service = app(SubscriptionRenewalService::class);
    foreach (['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'] as $index => $expected) {
        $service->recordRenewal($subscription->fresh(), $package, 'leap-calendar-'.$index, 99000, 'GHS');
        expect($subscription->fresh()->current_period_end->toDateTimeString())->toBe($expected.' 17:23:45');
        $this->travelTo($expected.' 08:00:00');
    }
});

test('a legacy calendar anchor starts from its known end rather than inventing January history', function (): void {
    $this->travelTo('2027-02-28 08:00:00');
    [, $subscription, $package] = renewingTenant('2027-02-28 17:23:45');
    app(SubscriptionRenewalService::class)->recordRenewal($subscription, $package, 'legacy-calendar', 9900, 'GHS');
    expect($subscription->fresh()->current_period_end->toDateTimeString())->toBe('2027-03-28 17:23:45')
        ->and($subscription->fresh()->billing_anchor_day)->toBe(28)
        ->and($subscription->fresh()->billing_anchor_month)->toBe(2);
});

test('grace retains an established calendar anchor but a lapsed restart resets it', function (bool $lapsed): void {
    $this->travelTo($lapsed ? '2027-04-10 08:00:00' : '2027-03-02 08:00:00');
    [, $subscription, $package] = renewingTenant('2027-02-28 17:23:45');
    $subscription->update(['billing_anchor_day' => 31, 'billing_anchor_month' => 1]);
    app(SubscriptionRenewalService::class)->recordRenewal($subscription, $package, 'anchor-grace-restart', 9900, 'GHS');
    $subscription->refresh();
    expect($subscription->current_period_end->toDateTimeString())->toBe($lapsed ? '2027-05-10 08:00:00' : '2027-03-31 17:23:45')
        ->and($subscription->billing_anchor_day)->toBe($lapsed ? 10 : 31)
        ->and($subscription->billing_anchor_month)->toBe($lapsed ? 4 : 1);
})->with([false, true]);

test('subscription calendar anchors reject invalid or incomplete stored pairs', function (?int $day, ?int $month): void {
    [, $subscription] = renewingTenant();
    expect(fn () => $subscription->update(['billing_anchor_day' => $day, 'billing_anchor_month' => $month]))
        ->toThrow(Illuminate\Database\QueryException::class);
})->with([[0, 1], [32, 1], [1, 13], [null, 1], [1, null]]);
