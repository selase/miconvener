<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.webhook_secret' => 'settlement_webhook_secret_test']);
});

function chargeLedgerHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('an own_gateway charge.success webhook writes a charge ledger entry with the real gateway fee', function () {
    Mail::fake();
    [$tenant] = chargeLedgerHost('acme');
    $secret = 'sk_test_webhook_secret';

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => $secret,
        'is_active' => true,
    ]);

    $event = Event::factory()->published()->paid(10_000)->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 5.0]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 10_000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_ledger_own_gw',
            'amount' => 10_000,
            'fees' => 150,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'type' => 'event_ticket'],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $secret);

    $this->call('POST', "/webhooks/merchant/paystack/{$tenant->id}", [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    $entry = EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->firstOrFail();
    expect($entry->gross_amount)->toBe(10_000);
    expect($entry->gateway_fee_amount)->toBe(150);
    expect($entry->commission_amount)->toBe(500); // 5% of 10,000
    expect($entry->net_amount)->toBe(9_350); // 10,000 - 150 - 500
    expect($entry->registration_id)->toBe($registration->id);
});

test('a platform_default charge.success confirms the registration via tenant_id in metadata and writes a charge ledger entry', function () {
    Mail::fake();
    [$tenant] = chargeLedgerHost('acme');
    $platformSecret = 'sk_settlement_platform_secret';
    config(['services.settlement.paystack.secret_key' => $platformSecret]);
    config(['services.settlement.paystack.webhook_secret' => $platformSecret]);

    $event = Event::factory()->published()->paid(10_000)->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 5.0]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 10_000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_ledger_platform_default',
            'amount' => 10_000,
            'fees' => 150,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['event_registration_id' => $registration->id, 'tenant_id' => $tenant->id, 'type' => 'event_ticket'],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $platformSecret);

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    $registration->refresh();
    expect($registration->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect($registration->ticket_code)->not->toBeNull();

    $entry = EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_CHARGE)->firstOrFail();
    expect($entry->gross_amount)->toBe(10_000);
    expect($entry->gateway_fee_amount)->toBe(150);
    expect($entry->commission_amount)->toBe(500);
    expect($entry->net_amount)->toBe(9_350);
});

test('an invalid settlement webhook signature is rejected', function () {
    config(['services.settlement.paystack.webhook_secret' => 'real_secret']);

    $response = $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => 'wrong-signature', 'CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'charge.success', 'data' => []]));

    $response->assertStatus(400);
});
