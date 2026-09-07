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
            'metadata' => ['source' => 'miconvener', 'event_registration_id' => $registration->id, 'tenant_id' => $tenant->id, 'type' => 'event_ticket'],
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
    config(['services.settlement.paystack.secret_key' => 'real_secret']);

    $response = $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => 'wrong-signature', 'CONTENT_TYPE' => 'application/json'], json_encode(['event' => 'charge.success', 'data' => []]));

    $response->assertStatus(400);
});

test('the settlement webhook verifies signatures using the platform secret key, not a separate webhook secret', function () {
    Mail::fake();
    [$tenant] = chargeLedgerHost('acme');
    $platformSecret = 'sk_settlement_real_key';
    config(['services.settlement.paystack.secret_key' => $platformSecret]);
    // Paystack has no separate webhook-signing secret (unlike Stripe) — it
    // signs with the same secret API key. Deliberately setting a different,
    // unused webhook_secret proves verification doesn't depend on it.
    config(['services.settlement.paystack.webhook_secret' => 'this-value-must-not-be-used']);

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
            'reference' => 'ref_secret_key_check',
            'amount' => 5000,
            'fees' => 0,
            'currency' => 'GHS',
            'customer' => ['email' => 'guest@example.com', 'first_name' => 'Paying', 'last_name' => 'Guest'],
            'metadata' => ['source' => 'miconvener', 'event_registration_id' => $registration->id, 'tenant_id' => $tenant->id, 'type' => 'event_ticket'],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, $platformSecret);

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('a mail transport failure cannot fail the settlement webhook', function () {
    // The ticket and its ledger entry commit before the confirmation email is
    // handed off. Sending inline would turn a successful charge into a 500 and a
    // provider retry whenever the mail transport is slow or unavailable.
    Mail::fake();
    [$tenant] = chargeLedgerHost('acme');
    config(['services.settlement.paystack.secret_key' => 'sk_platform_mail_test']);

    $event = Event::factory()->published()->paid(5_000)->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->pendingPayment()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'amount' => 5_000,
    ]);

    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_mail_queue_1',
            'amount' => 5_000,
            'fees' => 75,
            'currency' => 'GHS',
            'metadata' => [
                'source' => 'miconvener',
                'tenant_id' => $tenant->id,
                'event_registration_id' => $registration->id,
                'type' => 'event_ticket',
            ],
        ],
    ];
    $body = json_encode($payload);
    $signature = hash_hmac('sha512', $body, config('services.settlement.paystack.secret_key'));

    $this->call('POST', '/webhooks/settlement/paystack', [], [], [], ['HTTP_x-paystack-signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
        ->assertOk();

    Mail::assertQueued(\App\Mail\Events\EventRegistrationConfirmed::class);
    Mail::assertNothingSent();
});
