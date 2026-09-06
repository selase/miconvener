<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function refundedStatHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('finance stats expose the refunded gross total so the dashboard can show it', function () {
    [$tenant, $user] = refundedStatHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'gross_amount' => 10_000, 'gateway_fee_amount' => 150, 'commission_amount' => 500, 'net_amount' => 9_350,
    ]);
    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'gross_amount' => 20_000, 'gateway_fee_amount' => 300, 'commission_amount' => 1_000, 'net_amount' => 18_700,
    ]);
    EventLedgerEntry::factory()->refund()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'gross_amount' => 10_000, 'gateway_fee_amount' => 150, 'commission_amount' => 500, 'net_amount' => -9_350,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/finance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('stats.collected', 30_000);
    $response->assertJsonPath('stats.refunded', 10_000);
    // net_collected already nets the refund out; refunded is the gross that came back.
    $response->assertJsonPath('stats.net_collected', 18_700);
});

test('refunded is zero when nothing has been refunded', function () {
    [$tenant, $user] = refundedStatHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    EventLedgerEntry::factory()->create([
        'tenant_id' => $tenant->id, 'event_id' => $event->id,
        'gross_amount' => 10_000, 'gateway_fee_amount' => 150, 'commission_amount' => 500, 'net_amount' => 9_350,
    ]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/finance", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('stats.refunded', 0);
});

test('the event page tells the frontend which settlement mode the tenant is on', function () {
    [$tenant, $user] = refundedStatHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('settlementMode', Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT));

    $tenant->update(['settlement_mode' => Tenant::SETTLEMENT_MODE_OWN_GATEWAY]);

    $this->actingAs($user)->get("http://{$host}/events/{$event->id}", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('settlementMode', Tenant::SETTLEMENT_MODE_OWN_GATEWAY));
});
