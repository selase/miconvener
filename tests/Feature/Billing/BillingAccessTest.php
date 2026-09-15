<?php

declare(strict_types=1);

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * Paying for and changing the plan is the owner's decision. Any team member —
 * an admin, a check-in volunteer — used to be able to change the plan, buy
 * tokens or pay a renewal.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
});

/**
 * @return array{0: User, 1: App\Models\Tenant}
 */
function billingMember(string $role): array
{
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user, ['package_id' => Package::query()->where('slug', 'growth')->value('id'), 'meta' => ['paystack_id' => 'CUS_1']]);
    $user->assignRole($role);

    return [$user, $tenant];
}

test('only the owner holds manage billing among the built-in tenant roles', function (): void {
    expect(App\Models\Role::findByName('Org Superadmin')->hasPermissionTo('manage billing'))->toBeTrue()
        ->and(App\Models\Role::findByName('Org Admin')->hasPermissionTo('manage billing'))->toBeFalse()
        ->and(App\Models\Permission::TENANT_SAFE)->not->toContain('manage billing');
});

test('a team member who is not the owner cannot see or use billing', function (): void {
    [$admin, $tenant] = billingMember('Org Admin');
    $invoice = Invoice::factory()->create(['tenant_id' => $tenant->id, 'status' => Invoice::STATUS_ISSUED]);
    Subscription::query()->create(['tenant_id' => $tenant->id, 'name' => 'default', 'provider_id' => 'ps_1', 'provider_status' => 'active', 'provider_plan' => 'growth_month', 'current_period_end' => now()->addDays(3)]);
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldNotReceive('createOneTimeCheckoutSession');
    $this->swap(PaymentGateway::class, $gateway);
    $subdomain = ['subdomain' => $tenant->slug];

    $this->actingAs($admin);
    $this->get(route('billing.index', $subdomain))->assertForbidden();
    $this->get(route('tenant.pricing', $subdomain))->assertForbidden();
    $this->post(route('billing.checkout', $subdomain), ['plan' => 'starter'])->assertForbidden();
    $this->get(route('billing.renew', $subdomain))->assertForbidden();
    $this->post(route('billing.llm-checkout', $subdomain), ['pack' => 'starter'])->assertForbidden();
    $this->get(route('billing.invoices.show', $invoice->id))->assertForbidden();
    $this->get(route('billing.invoices.download', $invoice->id))->assertForbidden();

    $this->get(route('tenant.llm-usage.index', $subdomain))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can.manage_billing', false)->where('tokenPacks', []));

    expect($tenant->fresh()->package_id)->toEqual(Package::query()->where('slug', 'growth')->value('id'));
});

test('the owner can reach billing and is offered token packs', function (): void {
    [$owner, $tenant] = billingMember('Org Superadmin');
    $subdomain = ['subdomain' => $tenant->slug];

    $this->actingAs($owner);
    $this->get(route('billing.index', $subdomain))->assertOk()->assertInertia(fn ($page) => $page->where('auth.can.manage_billing', true));
    $this->get(route('tenant.pricing', $subdomain))->assertOk();
    $this->get(route('tenant.llm-usage.index', $subdomain))->assertInertia(fn ($page) => $page->has('tokenPacks', 3));
});

test('a paid payment is still fulfilled whoever returns from Paystack', function (): void {
    /*
     * The callback records money already taken. Refusing it for a non-owner
     * would leave the payment unrecorded until the webhook arrived.
     */
    [$admin, $tenant] = billingMember('Org Admin');
    $gateway = Mockery::mock(PaymentGateway::class);
    $gateway->shouldReceive('verifyTransaction')->andReturn([
        'status' => 'success', 'reference' => 'ref_admin_return', 'transaction_id' => 1, 'amount' => 6000, 'currency' => 'ghs',
        'metadata' => ['type' => 'llm_token_purchase', 'pack_key' => 'starter', 'tenant_id' => $tenant->id],
    ]);
    $this->swap(PaymentGateway::class, $gateway);

    $this->actingAs($admin)->get(route('billing.callback', ['reference' => 'ref_admin_return']))->assertRedirect();

    expect((int) $tenant->fresh()->llm_topup_balance)->toBe(500000);
});
