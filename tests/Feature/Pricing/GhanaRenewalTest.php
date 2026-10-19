<?php

declare(strict_types=1);

use App\Mail\Billing\RenewalReminderMail;
use App\Models\ExchangeRate;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\SubscriptionRenewalService;
use App\Services\Pricing\GhanaEligibility;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * A renewal is priced by where the organisation stands today, not by how it
 * first paid: Ghana eligible organisations renew at the Ghana price, those
 * settled in USD at the USD price converted on the renewal day, and an
 * organisation nobody has classified yet keeps what it was on.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Cache::flush();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.currency' => 'GHS', 'services.paystack.metadata_source' => 'miconvener']);
    Mail::fake();
    ExchangeRate::factory()->create(['rate' => 11.77, 'as_of' => '2026-10-16']);

    $this->growth = Package::query()->where('slug', 'growth')->firstOrFail();
    $this->subscribed = function (?string $region, string $billing = 'GHS', array $subscription = []): array {
        $tenant = Tenant::factory()->create(['slug' => 'acme-'.uniqid(), 'package_id' => $this->growth->id, 'pricing_region' => $region, 'email' => 'accounts@acme.test', 'isolation_mode' => 'shared']);
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $tenant->users()->attach($owner->id);

        return [$tenant, Subscription::query()->create($subscription + [
            'tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_'.uniqid(), 'provider_status' => 'active', 'provider_plan' => 'growth_month',
            'interval' => 'month', 'current_period_end' => now()->addDays(2), 'billing_currency' => $billing,
        ])];
    };
});

test('a Ghana eligible organisation renews at the Ghana price, whatever it first paid in', function (): void {
    [, $subscription] = ($this->subscribed)('GH', 'USD');

    expect($subscription->fresh()->priceMinorFor($this->growth))->toBe(49900);
});

test('an organisation settled in USD renews at the USD price converted on the renewal day', function (): void {
    [, $subscription] = ($this->subscribed)('INTL', 'GHS');

    expect($subscription->fresh()->priceMinorFor($this->growth))->toBe(45903);

    ExchangeRate::factory()->create(['rate' => 12.5, 'as_of' => '2026-10-17']);
    Cache::flush();
    expect($subscription->fresh()->priceMinorFor($this->growth))->toBe(48750);
});

test('an organisation nobody has classified keeps the currency it was on', function (): void {
    [, $ghs] = ($this->subscribed)(null, 'GHS');
    [, $usd] = ($this->subscribed)(null, 'USD');

    expect($ghs->fresh()->priceMinorFor($this->growth))->toBe(49900)
        ->and($usd->fresh()->priceMinorFor($this->growth))->toBe(45903);
});

test('removing eligibility moves the next renewal to USD, and restoring it moves it back', function (): void {
    [$tenant, $subscription] = ($this->subscribed)('GH', 'GHS');
    expect($subscription->fresh()->priceMinorFor($this->growth))->toBe(49900);

    app(GhanaEligibility::class)->revoke($tenant->fresh(), User::factory()->create(['tenant_id' => null]), 'Moved abroad');
    expect($subscription->fresh()->priceMinorFor($this->growth))->toBe(45903);

    app(GhanaEligibility::class)->grant($tenant->fresh(), GhanaEligibility::SOURCE_SUPERADMIN, ['reason' => 'Back'], User::factory()->create(['tenant_id' => null]));
    expect($subscription->fresh()->priceMinorFor($this->growth))->toBe(49900);
});

test('an Enterprise agreed price is always in GHS', function (): void {
    [$tenant, $subscription] = ($this->subscribed)('INTL', 'USD');
    $enterprise = Package::query()->where('slug', 'enterprise')->firstOrFail();
    $tenant->update(['package_id' => $enterprise->id, 'agreed_price_pesewas' => 250000, 'agreed_price_interval' => 'month']);

    expect($subscription->fresh()->priceMinorFor($enterprise))->toBe(250000);
});

test('the reminder before a USD renewal names the amount and says it is converted on the day', function (): void {
    [$tenant, $subscription] = ($this->subscribed)('INTL', 'GHS', ['current_period_end' => now()->addDays(3)]);

    Artisan::call('billing:process-renewals');

    Mail::assertQueued(RenewalReminderMail::class, function (RenewalReminderMail $mail): bool {
        return $mail->amountDisplay === 'GHS 459.03'
            && str_contains((string) $mail->priceNote, 'US$ 39')
            && str_contains((string) $mail->priceNote, 'Bank of Ghana rate')
            && str_contains((string) $mail->priceNote, 'priced in Ghana cedis before');
    });
});

test('the reminder before a Ghana price renewal carries no conversion note', function (): void {
    ($this->subscribed)('GH', 'GHS', ['current_period_end' => now()->addDays(3)]);

    Artisan::call('billing:process-renewals');

    Mail::assertQueued(RenewalReminderMail::class, fn (RenewalReminderMail $mail): bool => $mail->amountDisplay === 'GHS 499.00' && $mail->priceNote === null);
});

test('a recorded renewal keeps the currency the plan is now billed in', function (): void {
    [$tenant, $subscription] = ($this->subscribed)('INTL', 'GHS');

    app(SubscriptionRenewalService::class)->recordRenewal($subscription, $this->growth, 'renew-1', 45903, 'GHS', ['pricing' => ['display_currency' => 'USD', 'display_amount_minor' => 3900]]);

    expect($subscription->fresh()->billing_currency)->toBe('USD');
});
