<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Mail\Billing\PaymentFailedMail;
use App\Mail\Billing\PaymentReceiptMail;
use App\Mail\Billing\RenewalReminderMail;
use App\Mail\Billing\SubscriptionEndedMail;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/**
 * The daily run, walked through a period: reminders, the charge, grace, lapse.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config([
        'services.paystack.secret_key' => 'sk_one_account',
        'services.paystack.metadata_source' => 'miconvener',
        'services.paystack.currency' => 'GHS',
    ]);
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $subscription
 * @return array{0: Tenant, 1: Subscription}
 */
function scheduledTenant(array $subscription = [], array $tenant = []): array
{
    $growth = Package::query()->where('slug', 'growth')->firstOrFail();
    $model = Tenant::factory()->create($tenant + ['slug' => 'acme', 'isolation_mode' => 'shared', 'package_id' => $growth->id, 'email' => 'accounts@acme.test']);
    $owner = User::factory()->create(['tenant_id' => $model->id, 'email' => 'owner@example.com']);
    $model->users()->attach($owner->id);

    $sub = Subscription::query()->create($subscription + [
        'tenant_id' => $model->id,
        'name' => 'default',
        'provider_id' => 'ps_first',
        'provider_status' => 'active',
        'provider_plan' => 'growth_month',
        'interval' => 'month',
        'current_period_end' => '2026-10-10 17:00:00',
    ]);

    return [$model, $sub];
}

function cardOnFile(): array
{
    return ['authorization_code' => 'AUTH_card', 'authorization_reusable' => true, 'authorization_email' => 'payer@example.com', 'authorization_label' => 'Visa card ending 4081'];
}

function runRenewals(string $at): string
{
    test()->travelTo($at);
    Artisan::call('billing:process-renewals');

    return Artisan::output();
}

test('a pay-link tenant is reminded 7 and 3 days ahead, once each', function (): void {
    [$tenant] = scheduledTenant();

    runRenewals('2026-10-02 07:00:00');
    Mail::assertNothingQueued();

    runRenewals('2026-10-03 07:00:00');
    runRenewals('2026-10-04 07:00:00');
    runRenewals('2026-10-07 07:00:00');
    runRenewals('2026-10-08 07:00:00');

    Mail::assertQueued(RenewalReminderMail::class, 2);
    Mail::assertQueued(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => ! $mail->overdue
        && $mail->amountDisplay === 'GHS 99.00'
        && $mail->dueOn === '10 October 2026'
        && $mail->hasTo('owner@example.com')
        && $mail->hasTo('accounts@acme.test')
        && str_ends_with($mail->payUrl, '/billing/renew'));
});

test('a saved card is not reminded, and is charged when the period ends', function (): void {
    [$tenant, $subscription] = scheduledTenant(cardOnFile());
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => [
        'status' => 'success', 'reference' => "renew-{$subscription->id}-20261010-1", 'amount' => 9900, 'currency' => 'GHS', 'channel' => 'card',
        'authorization' => ['authorization_code' => 'AUTH_card', 'reusable' => true, 'channel' => 'card', 'brand' => 'visa', 'last4' => '4081'],
    ]])]);

    runRenewals('2026-10-07 07:00:00');
    Http::assertNothingSent();
    Mail::assertNothingQueued();

    $output = runRenewals('2026-10-10 18:00:00');
    runRenewals('2026-10-10 19:00:00');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['email'] === 'payer@example.com' && $request['amount'] === 9900 && $request['metadata']['subscription_id'] === $subscription->id);
    expect($output)->toContain('acme: charged GHS 99.00 (attempt 1)')
        ->and($subscription->fresh()->current_period_end->toDateTimeString())->toBe('2026-11-10 17:00:00')
        ->and($subscription->fresh()->provider_status)->toBe('active')
        ->and(Transaction::query()->where('tenant_id', $tenant->id)->count())->toBe(1);
    Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->hasTo('payer@example.com'));
});

test('a declined card is retried daily through grace and then the plan moves to Free', function (): void {
    [$tenant, $subscription] = scheduledTenant(cardOnFile());
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds', 'amount' => 9900, 'currency' => 'GHS']])]);

    runRenewals('2026-10-11 07:00:00');

    expect($subscription->fresh()->provider_status)->toBe('past_due')
        ->and($subscription->fresh()->grace_ends_at->toDateTimeString())->toBe('2026-10-17 17:00:00');
    Mail::assertQueued(PaymentFailedMail::class, fn (PaymentFailedMail $mail): bool => $mail->retryOn === '12 October 2026'
        && $mail->movesToFreeOn === '17 October 2026');

    foreach (['12', '13', '14', '15', '16', '17'] as $day) {
        runRenewals("2026-10-{$day} 07:00:00");
    }

    Http::assertSentCount(7);
    expect($subscription->fresh()->renewal_attempts)->toBe(7)
        ->and(Http::recorded()->map(fn ($pair) => $pair[0]['reference'])->unique()->count())->toBe(7);

    runRenewals('2026-10-17 18:00:00');

    expect($tenant->fresh()->package?->is_free)->toBeTrue()
        ->and($subscription->fresh()->provider_status)->toBe('cancelled');
    Mail::assertQueued(SubscriptionEndedMail::class, fn (SubscriptionEndedMail $mail): bool => $mail->previousPlan === 'Growth');

    runRenewals('2026-10-18 07:00:00');
    Http::assertSentCount(7);
    Mail::assertQueued(SubscriptionEndedMail::class, 1);
});

test('an unpaid pay-link renewal gets a daily overdue reminder, then lapses', function (): void {
    [$tenant, $subscription] = scheduledTenant();

    runRenewals('2026-10-11 07:00:00');
    runRenewals('2026-10-11 09:00:00');
    runRenewals('2026-10-12 07:00:00');

    Mail::assertQueued(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->overdue && $mail->movesToFreeOn === '17 October 2026');
    Mail::assertQueued(RenewalReminderMail::class, 2);
    Http::assertNothingSent();

    runRenewals('2026-10-18 07:00:00');

    expect($tenant->fresh()->package?->is_free)->toBeTrue();
});

test('a rejected saved card falls back to pay-link reminders', function (): void {
    [, $subscription] = scheduledTenant(cardOnFile());
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => false, 'message' => 'Invalid authorization code'], 400)]);

    $output = runRenewals('2026-10-11 07:00:00');

    expect($output)->toContain('saved payment method rejected')
        ->and($subscription->fresh()->canBeChargedAutomatically())->toBeFalse();
    Mail::assertQueued(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->overdue);

    runRenewals('2026-10-12 07:00:00');
    Http::assertSentCount(1);
});

test('a plan cancelled for the end of its period ends then, without a charge', function (): void {
    [$tenant, $subscription] = scheduledTenant(cardOnFile() + ['pending_package_id' => Package::query()->where('slug', 'free')->value('id')]);
    Http::fake();

    runRenewals('2026-10-07 07:00:00');
    Mail::assertNothingQueued();

    runRenewals('2026-10-10 18:00:00');

    Http::assertNothingSent();
    expect($tenant->fresh()->package?->is_free)->toBeTrue();
    Mail::assertQueued(SubscriptionEndedMail::class, 1);
});

test('complimentary tenants, free tenants and other providers are left alone', function (): void {
    [$comped] = scheduledTenant(tenant: ['billing_complimentary' => true]);
    $freeTenant = Tenant::factory()->create(['slug' => 'freebie', 'package_id' => Package::query()->where('slug', 'free')->value('id')]);
    Subscription::query()->create(['tenant_id' => $freeTenant->id, 'name' => 'default', 'provider_id' => 'ps_x', 'provider_status' => 'active', 'provider_plan' => 'growth_month', 'current_period_end' => '2026-10-01']);
    $stripeTenant = Tenant::factory()->create(['slug' => 'stripey', 'package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    Subscription::query()->create(['tenant_id' => $stripeTenant->id, 'name' => 'default', 'provider_id' => 'sub_123', 'provider_status' => 'active', 'provider_plan' => 'price_1', 'current_period_end' => '2026-10-01']);
    Http::fake();

    runRenewals('2026-10-20 07:00:00');

    Http::assertNothingSent();
    Mail::assertNothingQueued();
    expect($comped->fresh()->package?->is_free)->toBeFalse()
        ->and($stripeTenant->fresh()->package?->is_free)->toBeFalse();
});

test('only the tenant\'s latest plan subscription is renewed', function (): void {
    [$tenant, $old] = scheduledTenant(['provider_id' => 'ps_older', 'current_period_end' => '2026-10-01 10:00:00']);
    Subscription::query()->create(['tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_newer', 'provider_status' => 'active', 'provider_plan' => 'growth_month', 'interval' => 'month', 'current_period_end' => '2026-11-01 10:00:00']);

    runRenewals('2026-10-20 07:00:00');

    Mail::assertNothingQueued();
    expect($tenant->fresh()->package?->is_free)->toBeFalse();
});

test('pretend lists the actions and changes nothing', function (): void {
    [$tenant, $subscription] = scheduledTenant(cardOnFile());
    Http::fake();

    $this->travelTo('2026-10-11 07:00:00');
    $this->artisan('billing:process-renewals', ['--pretend' => true])
        ->expectsOutput('acme: charge GHS 99.00 to Visa card ending 4081 (attempt 1)')
        ->assertSuccessful();

    Http::assertNothingSent();
    Mail::assertNothingQueued();
    expect($subscription->fresh()->provider_status)->toBe('active')
        ->and($subscription->fresh()->renewal_attempts)->toBe(0);
});

test('the pay link opens a Paystack charge for the next period as a renewal', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['package_id' => Package::query()->where('slug', 'growth')->value('id'), 'meta' => ['paystack_id' => 'CUS_1']]);
    makeTenantOwner($user, $tenant);
    $subscription = Subscription::query()->create(['tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_first', 'provider_status' => 'past_due', 'provider_plan' => 'growth_year', 'interval' => 'year', 'current_period_end' => now()->subDay()]);

    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->with('CUS_1', (int) round((float) Package::query()->where('slug', 'growth')->value('yearly_price') * 100), 'GHS', Mockery::any(), Mockery::on(fn (array $meta): bool => $meta['type'] === 'plan_renewal'
            && $meta['subscription_id'] === $subscription->id
            && $meta['source'] === 'miconvener'))
        ->andReturn('https://checkout.paystack.com/renew');
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($user)->get(route('billing.renew', ['subdomain' => $tenant->slug]))
        ->assertRedirect('https://checkout.paystack.com/renew');
});

test('the renewal run is scheduled daily', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'billing:process-renewals'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 7 * * *');
});
