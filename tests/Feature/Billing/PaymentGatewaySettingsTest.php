<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $this->tenant->features()->create(['feature_key' => 'paid_tickets', 'enabled' => true]);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->user->assignRole('Org Superadmin');
    $this->tenant->users()->attach($this->user->id);

    $this->host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
});

function saveGateway(array $fields): TestResponse
{
    return test()->actingAs(test()->user)->post(
        'http://'.test()->host.'/settings/payments',
        $fields,
        ['HTTP_HOST' => test()->host]
    );
}

function stripeGateway(): ?TenantPaymentGateway
{
    return TenantPaymentGateway::query()
        ->where('tenant_id', test()->tenant->id)
        ->where('provider', 'stripe')
        ->first();
}

test('connecting a provider for the first time requires its secret key', function () {
    saveGateway(['provider' => 'stripe', 'api_key' => '', 'public_key' => 'pk_test_1'])
        ->assertSessionHasErrors(['api_key' => 'Enter the secret key to connect this provider.']);

    expect(stripeGateway())->toBeNull();
});

test('a connected provider is saved and confirmed with a success flash', function () {
    saveGateway([
        'provider' => 'stripe',
        'api_key' => 'sk_test_original',
        'public_key' => 'pk_test_1',
        'webhook_secret' => 'whsec_original',
        'is_active' => true,
    ])->assertRedirect()->assertSessionHas('success', 'Stripe settings saved.');

    $gateway = stripeGateway();
    expect($gateway->api_key_encrypted)->toBe('sk_test_original')
        ->and($gateway->webhook_secret_encrypted)->toBe('whsec_original')
        ->and($gateway->public_key_encrypted)->toBe('pk_test_1')
        ->and($gateway->is_active)->toBeTrue();
});

test('saving with the secret fields left blank keeps the saved secrets', function () {
    /*
     * The old page pre-filled both secret fields with a literal "********".
     * Changing only the publishable key or the active switch and saving wrote
     * those asterisks over the real keys and broke the tenant's checkout.
     */
    saveGateway(['provider' => 'stripe', 'api_key' => 'sk_test_original', 'webhook_secret' => 'whsec_original']);

    saveGateway([
        'provider' => 'stripe',
        'api_key' => '',
        'public_key' => 'pk_test_changed',
        'webhook_secret' => '',
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    $gateway = stripeGateway();
    expect($gateway->api_key_encrypted)->toBe('sk_test_original')
        ->and($gateway->webhook_secret_encrypted)->toBe('whsec_original')
        ->and($gateway->public_key_encrypted)->toBe('pk_test_changed')
        ->and($gateway->is_active)->toBeFalse();
});

test('a new secret key replaces the saved one', function () {
    saveGateway(['provider' => 'stripe', 'api_key' => 'sk_test_original']);
    saveGateway(['provider' => 'stripe', 'api_key' => 'sk_test_rotated']);

    expect(stripeGateway()->api_key_encrypted)->toBe('sk_test_rotated');
});

test('the page shows which providers are connected but never their secrets', function () {
    saveGateway(['provider' => 'stripe', 'api_key' => 'sk_test_secret_value', 'public_key' => 'pk_test_1', 'webhook_secret' => 'whsec_secret_value']);

    $response = $this->actingAs($this->user)
        ->get("http://{$this->host}/settings/payments", ['HTTP_HOST' => $this->host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Payments')
            ->where('gateways.stripe.connected', true)
            ->where('gateways.stripe.has_webhook_secret', true)
            ->where('gateways.stripe.public_key', 'pk_test_1')
            ->where('gateways.stripe.webhook_url', route('webhooks.merchant.stripe', ['tenant' => $this->tenant->id]))
            ->where('gateways.paystack.connected', false));

    expect($response->getContent())
        ->not->toContain('sk_test_secret_value')
        ->not->toContain('whsec_secret_value');
});

test('an org admin without payment settings permission cannot save a gateway', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $admin->assignRole('Org Admin');
    $this->tenant->users()->attach($admin->id);

    $this->actingAs($admin)
        ->post("http://{$this->host}/settings/payments", ['provider' => 'stripe', 'api_key' => 'sk_test_x'], ['HTTP_HOST' => $this->host])
        ->assertForbidden();

    expect(stripeGateway())->toBeNull();
});
