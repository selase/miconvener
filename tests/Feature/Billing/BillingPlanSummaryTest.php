<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.currency' => 'GHS']);
    $this->travelTo('2026-10-05 09:00:00');
});

/**
 * @param  array<string, mixed>  $subscription
 * @return array{0: User, 1: Tenant}
 */
function billingPageTenant(?array $subscription = null, array $tenant = []): array
{
    $user = User::factory()->create();
    $model = setActiveTenantForTest($user, $tenant + ['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    makeTenantOwner($user, $model);

    if ($subscription !== null) {
        Subscription::query()->create($subscription + [
            'tenant_id' => $model->id,
            'name' => 'default',
            'provider_id' => 'ps_first',
            'provider_status' => 'active',
            'provider_plan' => 'growth_month',
            'interval' => 'month',
            'current_period_end' => '2026-10-10 17:00:00',
        ]);
    }

    return [$user, $model];
}

function billingPage(User $user, Tenant $tenant)
{
    return test()->actingAs($user)->get(route('billing.index', ['subdomain' => $tenant->slug]))->assertOk();
}

test('a saved card shows when it renews and what will be charged', function (): void {
    [$user, $tenant] = billingPageTenant(['authorization_code' => 'AUTH_card', 'authorization_reusable' => true, 'authorization_email' => 'payer@example.com', 'authorization_label' => 'Visa card ending 4081']);

    billingPage($user, $tenant)->assertInertia(fn ($page) => $page
        ->where('subscription.package_name', 'Growth')
        ->where('subscription.period_end', '10 October 2026')
        ->where('subscription.auto_renews', true)
        ->where('subscription.payment_method', 'Visa card ending 4081')
        ->where('subscription.can_pay_now', false));
});

test('a pay-link plan within a week of renewal offers to pay now', function (): void {
    [$user, $tenant] = billingPageTenant([]);

    billingPage($user, $tenant)->assertInertia(fn ($page) => $page
        ->where('subscription.auto_renews', false)
        ->where('subscription.can_pay_now', true)
        ->where('subscription.renew_amount', 'GHS 99.00'));
});

test('an overdue plan shows the grace deadline', function (): void {
    [$user, $tenant] = billingPageTenant(['provider_status' => 'past_due', 'current_period_end' => '2026-10-01 17:00:00', 'grace_ends_at' => '2026-10-08 17:00:00']);

    billingPage($user, $tenant)->assertInertia(fn ($page) => $page
        ->where('subscription.status', 'past_due')
        ->where('subscription.grace_ends_at', '8 October 2026')
        ->where('subscription.can_pay_now', true));
});

test('a complimentary tenant with no subscription shows its real plan, not Free', function (): void {
    [$user, $tenant] = billingPageTenant(null, ['billing_complimentary' => true]);

    billingPage($user, $tenant)->assertInertia(fn ($page) => $page
        ->where('subscription.package_name', 'Growth')
        ->where('subscription.is_free', false)
        ->where('subscription.complimentary', true)
        ->where('subscription.can_pay_now', false));
});

test('a plan cancelled for period end says it ends', function (): void {
    [$user, $tenant] = billingPageTenant(['pending_package_id' => Package::query()->where('slug', 'free')->value('id')]);

    billingPage($user, $tenant)->assertInertia(fn ($page) => $page
        ->where('subscription.ends_at_period_end', true)
        ->where('subscription.can_pay_now', false));
});

test('the superadmin can turn a complimentary plan on and off from the command line', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'purpledot']);

    $this->artisan('billing:complimentary', ['tenant' => 'purpledot'])->assertSuccessful();
    expect($tenant->fresh()->billing_complimentary)->toBeTrue();

    $this->artisan('billing:complimentary', ['tenant' => 'purpledot', '--off' => true])->assertSuccessful();
    expect($tenant->fresh()->billing_complimentary)->toBeFalse();

    $this->artisan('billing:complimentary', ['tenant' => 'nobody'])->assertFailed();
});
