<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function platformRefundHost(string $slug): array
{
    $tenant = Tenant::factory()->create(['slug' => $slug, 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function platformRefundSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('cancelling a platform_default paid registration refunds via the platform Paystack account and reverses the ledger', function () {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'ref_platform_refund_1'],
        ]),
    ]);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_platform_secret']);

    [$tenant, $user] = platformRefundHost('acme');
    $host = platformRefundSubdomain('acme');
    // No TenantPaymentGateway row exists — settlement_mode defaults to platform_default.

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'platform_fee_percentage' => 5.0]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 10_000,
        'currency' => 'GHS',
        'payment_reference' => 'paid_ref_platform_1',
    ]);
    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'gross_amount' => 10_000,
        'gateway_fee_amount' => 150,
        'commission_amount' => 500,
        'net_amount' => 9_350,
        'currency' => 'GHS',
        'provider' => 'paystack',
        'provider_reference' => 'paid_ref_platform_1',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CANCELLED);

    $refundEntry = EventLedgerEntry::where('tenant_id', $tenant->id)->where('type', EventLedgerEntry::TYPE_REFUND)->firstOrFail();
    expect($refundEntry->gross_amount)->toBe(10_000);
    expect($refundEntry->commission_amount)->toBe(500);
    expect($refundEntry->net_amount)->toBe(-9_350);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/refund')
        && $request['transaction'] === 'paid_ref_platform_1');
});

test('cancelling a platform_default registration aborts with 422 when the platform has no settlement credentials configured', function () {
    config(['services.settlement.paystack.secret_key' => null]);

    [$tenant, $user] = platformRefundHost('acme');
    $host = platformRefundSubdomain('acme');

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'status' => EventRegistration::STATUS_CONFIRMED,
        'amount' => 10_000,
        'payment_reference' => 'paid_ref_platform_2',
    ]);

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/registrations/{$registration->id}/cancel", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect($registration->fresh()->status)->toBe(EventRegistration::STATUS_CONFIRMED);
});
