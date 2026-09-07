<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventRegistration;
use App\Models\MerchantTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

/**
 * @return array{0: Tenant, 1: User}
 */
function financeIndexHost(string $settlementMode): array
{
    $tenant = Tenant::factory()->create([
        'slug' => 'acme',
        'isolation_mode' => 'shared',
        'settlement_mode' => $settlementMode,
    ]);
    $tenant->features()->create(['feature_key' => 'commerce', 'enabled' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function financeIndexSubdomain(string $slug): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "{$slug}.{$baseDomain}";
}

test('a platform_default tenant sees its ledger-collected payments in the tenant-wide finance figures', function () {
    [$tenant, $user] = financeIndexHost(Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT);
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $registration = EventRegistration::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'full_name' => 'Kwame Asante',
        'email' => 'kwame@example.com',
    ]);

    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'gross_amount' => 10_000,
        'provider_reference' => 'ref_charge_1',
    ]);
    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'registration_id' => $registration->id,
        'type' => EventLedgerEntry::TYPE_REFUND,
        'gross_amount' => 4_000,
        'provider_reference' => 'ref_refund_1',
    ]);
    // A payout entry must never be counted as a customer transaction.
    EventLedgerEntry::factory()->payout()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'gross_amount' => 3_000,
    ]);

    $host = financeIndexSubdomain('acme');

    $response = $this->actingAs($user)->get("http://{$host}/finance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Finance/Index')
        ->where('stats.total_volume', 10_000)
        ->where('stats.transaction_count', 1)
        ->where('stats.refund_volume', 4_000)
        ->where('transactions.data.0.customer_name', 'Kwame Asante')
        ->where('transactions.data.0.customer_email', 'kwame@example.com')
        ->where('transactions.data.0.can_refund', false)
    );
});

test('an own_gateway tenant keeps reading MerchantTransaction and its figures are unchanged by the ledger fix', function () {
    [$tenant, $user] = financeIndexHost(Tenant::SETTLEMENT_MODE_OWN_GATEWAY);

    MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'ref_mt_charge',
        'amount' => 5_000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'payment',
        'customer_name' => 'Ama Owusu',
        'customer_email' => 'ama@example.com',
    ]);
    MerchantTransaction::create([
        'tenant_id' => $tenant->id,
        'provider' => 'paystack',
        'provider_transaction_id' => 'ref_mt_refund',
        'amount' => 1_000,
        'currency' => 'GHS',
        'status' => 'succeeded',
        'type' => 'refund',
    ]);

    // Same tenant also has an EventLedgerEntry (e.g. a ticket sold through this
    // own_gateway tenant's Paystack webhook also gets mirrored into the ledger).
    // It must not be added on top of the MerchantTransaction totals below.
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'type' => EventLedgerEntry::TYPE_CHARGE,
        'gross_amount' => 99_999,
    ]);

    $host = financeIndexSubdomain('acme');

    $response = $this->actingAs($user)->get("http://{$host}/finance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Tenant/Finance/Index')
        // total_volume sums every succeeded MerchantTransaction row regardless of
        // type (5_000 charge + 1_000 refund), exactly as it did before this fix —
        // preserved verbatim rather than "corrected" here, since own_gateway
        // behavior must stay unchanged.
        ->where('stats.total_volume', 6_000)
        ->where('stats.transaction_count', 1)
        ->where('stats.refund_volume', 1_000)
        // ->latest() orders by created_at desc, so the refund (created second)
        // is data.0 and the charge is data.1.
        ->where('transactions.data.1.customer_name', 'Ama Owusu')
    );
});
