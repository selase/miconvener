<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\TenantPaymentGateway;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function cancelRefundHost(string $slug): array
{
    // This suite exercises the tenant's own Paystack gateway refund path, so
    // every tenant it creates opts out of platform_default settlement.
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared', 'settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function cancelRefundSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('cancelling a confirmed paid registration automatically refunds via the tenant Paystack gateway', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_cancel_1'],
        ]),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_123',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.paystack.co/refund'
        && $request['transaction'] === 'paid_ref_123');
});

test('cancellation is aborted and the registration stays confirmed when the refund call fails', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Transaction not found'], 400),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_456',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(502);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('cancellation is aborted with 422 when a paid registration has no active payment gateway configured', function () {
    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_789',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});

test('cancelling a free confirmed registration does not attempt a refund', function () {
    Http::preventStrayRequests();

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 0,
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
});

test('cancelling a waitlisted registration never attempts a refund, even with a nonzero amount', function () {
    Http::preventStrayRequests();

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_WAITLISTED,
        'waitlist_position' => 1,
        'amount' => 5000,
        'payment_reference' => null,
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
});

test('an automatic refund on cancel marks the original charge refunded and writes a refund ledger row', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_ledger_1'],
        ]),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_ledger',
    ]);

    $charge = MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'paid_ref_ledger',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_email' => 'guest@example.com',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect($charge->fresh()->status)->toBe('refunded');

    $refundRow = MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->firstOrFail();
    expect($refundRow->provider_transaction_id)->toBe('ref_ledger_1')
        ->and($refundRow->provider)->toBe('paystack')
        ->and($refundRow->amount)->toBe(5000)
        ->and($refundRow->currency)->toBe('GHS')
        ->and($refundRow->status)->toBe('succeeded')
        ->and($refundRow->customer_email)->toBe('guest@example.com')
        ->and($refundRow->meta)->toBe(['refund_id' => 'ref_ledger_1']);
});

test('a pending-shaped paystack refund response still cancels and records the REF_ fallback id', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => []]),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_pending',
    ]);

    $charge = MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'paid_ref_pending',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_email' => 'guest@example.com',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect($charge->fresh()->status)->toBe('refunded');

    $refundRow = MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->firstOrFail();
    expect($refundRow->provider_transaction_id)->toBe('REF_paid_ref_pending')
        ->and($refundRow->meta)->toBe(['refund_id' => 'pending']);
});

test('cancellation completes and writes the ledger reversal when Paystack reports the transaction was already reversed', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => false,
            'message' => 'Transaction has been fully reversed',
            'code' => 'transaction_reversed',
        ], 400),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_already_reversed',
    ]);

    $charge = MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'paid_ref_already_reversed',
        'amount' => 5000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_email' => 'guest@example.com',
    ]);

    $chargeEntry = EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'provider_reference' => 'paid_ref_already_reversed',
        // Coherent with the GHS 50 registration above: 5000 - 150 - 150 = 4700.
        // The reversal is derived from these components, so a net that did not
        // match them would describe a charge that never happened.
        'gross_amount' => 5000,
        'gateway_fee_amount' => 150,
        'commission_amount' => 150,
        'net_amount' => 4700,
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect($charge->fresh()->status)->toBe('refunded');

    $refundEntry = EventLedgerEntry::where('tenant_id', $tenant->id)
        ->where('type', EventLedgerEntry::TYPE_REFUND)
        ->firstOrFail();
    expect($refundEntry->registration_id)->toBe($registration->id)
        ->and($refundEntry->net_amount)->toBe(-4700)
        ->and($refundEntry->provider_reference)->toBe('REF_paid_ref_already_reversed');

    $refundRow = MerchantTransaction::where('tenant_id', $tenant->id)->where('type', 'refund')->firstOrFail();
    expect($refundRow->provider_transaction_id)->toBe('REF_paid_ref_already_reversed');
});

test('cancellation is still aborted when the refund fails for a reason other than an already-reversed transaction', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => false,
            'message' => 'Insufficient balance to fulfill this refund. Please top up.',
            'code' => 'insufficient_balance',
        ], 400),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_other_failure',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(502);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
    expect(EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_REFUND)->count())->toBe(0);
});

test('a refund with no matching merchant transaction still cancels and logs a reconciliation warning', function () {
    Log::spy();
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_orphan_1'],
        ]),
    ]);

    [$tenant, $user] = cancelRefundHost('acme');
    $host = cancelRefundSubdomain('acme');

    TenantPaymentGateway::factory()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'api_key_encrypted' => 'sk_test_123',
        'is_active' => true,
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 5000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_orphan',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);
    expect(MerchantTransaction::where('tenant_id', $tenant->id)->count())->toBe(0);

    Log::shouldHaveReceived('warning')->once();
});
