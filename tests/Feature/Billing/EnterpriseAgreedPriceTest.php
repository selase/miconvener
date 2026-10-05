<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Mail\Billing\AgreedPriceMail;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

/**
 * Enterprise has no list price (decided 2026-10-05): a superadmin sets each
 * organisation's agreed price, checkout and renewals charge it, the Billing
 * page shows it, and the organisation is emailed when it is set or changed.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    config(['services.paystack.currency' => 'GHS']);
    Mail::fake();
});

function enterprisePackage(): Package
{
    return Package::query()->where('slug', 'enterprise')->firstOrFail();
}

/**
 * @return array{0: User, 1: Tenant}
 */
function enterpriseOwner(array $tenant = []): array
{
    $owner = User::factory()->create(['email' => 'owner@acme.test']);
    $model = setActiveTenantForTest($owner, $tenant + [
        'package_id' => enterprisePackage()->id,
        'email' => 'accounts@acme.test',
        'meta' => ['paystack_id' => 'CUS_1'],
    ]);
    $owner->assignRole('Org Superadmin');

    return [$owner, $model];
}

function pricingSuperadmin(): User
{
    $superadmin = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());

    return $superadmin;
}

test('Enterprise is a paid plan priced per organisation, not a free one', function (): void {
    expect(enterprisePackage()->isFree())->toBeFalse()
        ->and(enterprisePackage()->isNegotiated())->toBeTrue()
        ->and(Package::query()->where('slug', 'free')->firstOrFail()->isFree())->toBeTrue();
});

test('a superadmin sets the agreed price and the organisation is emailed once', function (): void {
    [, $tenant] = enterpriseOwner();
    $superadmin = pricingSuperadmin();

    $this->actingAs($superadmin)
        ->put(route('tenants.agreed-price.update', $tenant->uuid), ['price' => '2500', 'interval' => 'month'])
        ->assertSessionHas('success');

    expect($tenant->fresh()->agreed_price_pesewas)->toBe(250000)
        ->and($tenant->fresh()->agreed_price_interval)->toBe('month');
    Mail::assertQueued(AgreedPriceMail::class, fn (AgreedPriceMail $mail): bool => $mail->price === 'GHS 2,500.00 a month'
        && $mail->hasTo('accounts@acme.test') && $mail->hasTo('owner@acme.test'));

    $this->actingAs($superadmin)->put(route('tenants.agreed-price.update', $tenant->uuid), ['price' => '2500', 'interval' => 'month']);
    Mail::assertQueuedCount(1);
});

test('a price can only be set for an organisation on Enterprise', function (): void {
    [, $tenant] = enterpriseOwner(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);

    $this->actingAs(pricingSuperadmin())
        ->put(route('tenants.agreed-price.update', $tenant->uuid), ['price' => '2500', 'interval' => 'month'])
        ->assertSessionHas('error');

    expect($tenant->fresh()->agreed_price_pesewas)->toBeNull();
});

test('organisation owners cannot set their own price', function (): void {
    [$owner, $tenant] = enterpriseOwner();

    $this->actingAs($owner)
        ->put(route('tenants.agreed-price.update', $tenant->uuid), ['price' => '1', 'interval' => 'month'])
        ->assertForbidden();
});

test('the Billing page shows the agreed price and offers the first payment', function (): void {
    [$owner, $tenant] = enterpriseOwner(['agreed_price_pesewas' => 250000, 'agreed_price_interval' => 'month']);

    $this->actingAs($owner)
        ->get(route('billing.index', ['subdomain' => $tenant->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subscription.package_name', 'Enterprise')
            ->where('subscription.is_free', false)
            ->where('subscription.agreed_price', 'GHS 2,500.00 a month')
            ->where('subscription.first_payment_due', 'GHS 2,500.00'));
});

test('a complimentary Enterprise organisation is shown no price to pay', function (): void {
    [$owner, $tenant] = enterpriseOwner(['agreed_price_pesewas' => 250000, 'agreed_price_interval' => 'month', 'billing_complimentary' => true]);

    $this->actingAs($owner)
        ->get(route('billing.index', ['subdomain' => $tenant->slug]))
        ->assertInertia(fn ($page) => $page
            ->where('subscription.agreed_price', null)
            ->where('subscription.first_payment_due', null));
});

test('Enterprise checkout charges the agreed price and interval', function (): void {
    [$owner, $tenant] = enterpriseOwner(['agreed_price_pesewas' => 3000000, 'agreed_price_interval' => 'year']);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('createOneTimeCheckoutSession')
        ->once()
        ->withArgs(fn ($customer, $amount, $currency, $url, $metadata): bool => $amount === 3000000 && $metadata['interval'] === 'year' && $metadata['plan_slug'] === 'enterprise')
        ->andReturn('https://checkout.paystack.test/abc');
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($owner)
        ->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'enterprise', 'interval' => 'month'])
        ->assertRedirect('https://checkout.paystack.test/abc');

    expect($tenant->fresh()->package_id)->toEqual(enterprisePackage()->id);
});

test('without an agreed price Enterprise checkout charges nothing and keeps the plan', function (): void {
    [$owner, $tenant] = enterpriseOwner();
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldNotReceive('createOneTimeCheckoutSession');
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($owner)
        ->post(route('billing.checkout', ['subdomain' => $tenant->slug]), ['plan' => 'enterprise'])
        ->assertRedirect(route('tenant.pricing', ['subdomain' => $tenant->slug]));

    expect($tenant->fresh()->package_id)->toEqual(enterprisePackage()->id);
});

test('renewals charge the agreed price', function (): void {
    [, $tenant] = enterpriseOwner(['agreed_price_pesewas' => 250000, 'agreed_price_interval' => 'month']);
    $subscription = Subscription::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'default',
        'provider_id' => 'ps_ent',
        'provider_status' => 'active',
        'provider_plan' => 'enterprise_month',
        'interval' => 'month',
        'current_period_end' => now()->addDays(3),
    ]);

    expect($subscription->priceMinorFor(enterprisePackage()))->toBe(250000);
});

test('signing up for Enterprise starts the organisation on Free', function (): void {
    $this->post('/register', [
        'first_name' => 'Ada',
        'last_name' => 'Osei',
        'organization_name' => 'Acme Corp',
        'email' => 'ada@acmecorp.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'plan' => 'enterprise',
        'slug' => 'acme-corp',
    ]);

    expect(Tenant::query()->where('slug', 'acme-corp')->firstOrFail()->package?->slug)->toBe('free');
});
