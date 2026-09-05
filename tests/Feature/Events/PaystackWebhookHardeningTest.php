<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function webhookHardeningHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function signedPaystackWebhookCall(\Tests\TestCase $test, string $tenantId, string $secret, array $payload)
{
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $secret);

    return $test->call(
        'POST',
        "/webhooks/merchant/paystack/{$tenantId}",
        [], [], [],
        ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $body
    );
}

test('a failed paystack charge is recorded as a failed merchant transaction, and the registration stays pending', function () {
    Mail::fake();
    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.failed',
        'data' => [
            'reference' => 'ref_failed_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];

    $response = signedPaystackWebhookCall($this, $tenant->id, $secret, $payload);
    $response->assertOk();

    $transaction = MerchantTransaction::where('tenant_id', $tenant->id)
        ->where('provider_transaction_id', 'ref_failed_123')
        ->firstOrFail();
    expect($transaction->status)->toBe('failed');

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_PENDING_PAYMENT);
    Mail::assertNothingSent();
});

test('an invalid paystack signature is logged', function () {
    Log::spy();
    [$tenant] = webhookHardeningHost('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_webhook_secret',
        'is_active' => true,
    ]);

    $response = $this->call(
        'POST',
        "/webhooks/merchant/paystack/{$tenant->id}",
        [], [], [],
        ['HTTP_x-paystack-signature' => 'not-a-real-signature', 'CONTENT_TYPE' => 'application/json'],
        json_encode(['event' => 'charge.success', 'data' => []])
    );

    $response->assertStatus(400);
    Log::shouldHaveReceived('warning')->once();
});

test('a paystack webhook processing exception is caught, logged, and returns 500 so the provider retries', function () {
    Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_boom_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];

    $response = signedPaystackWebhookCall($this, $tenant->id, $secret, $payload);

    $response->assertStatus(500);
    Log::shouldHaveReceived('error')->once()->with(
        'Merchant Paystack webhook processing failed',
        Mockery::on(fn ($context): bool => $context['tenant'] === $tenant->id
            && $context['event'] === 'charge.success'
            && $context['reference'] === 'ref_boom_123')
    );
});

test('charge.success still confirms the registration exactly once (regression)', function () {
    Mail::fake();
    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(5000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_ok_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];

    signedPaystackWebhookCall($this, $tenant->id, $secret, $payload)->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('a stale charge.failed webhook does not downgrade an already-succeeded transaction', function () {
    Mail::fake();
    [$tenant] = webhookHardeningHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $transaction = MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'ref_race_123',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_email' => 'guest@example.com',
    ]);

    $payload = [
        'event' => 'charge.failed',
        'data' => [
            'reference' => 'ref_race_123',
            'amount' => 5000,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => [],
        ],
    ];

    signedPaystackWebhookCall($this, $tenant->id, $secret, $payload)->assertOk();

    expect($transaction->fresh()->status)->toBe('succeeded');
    expect(MerchantTransaction::where('tenant_id', $tenant->id)->count())->toBe(1);
});
