<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function payoutDeletionHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

function payoutDeletionHostUrl(): string
{
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');

    return "acme.{$baseDomain}";
}

test('an account with a paid payout cannot be deleted', function () {
    [$tenant, $user] = payoutDeletionHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::factory()->paid()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 12_000,
    ]);

    $host = payoutDeletionHostUrl();

    $response = $this->actingAs($user)->deleteJson("http://{$host}/payout-accounts/{$account->id}", [], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect(TenantPayoutAccount::find($account->id))->not->toBeNull();
    expect(EventPayout::where('payout_account_id', $account->id)->count())->toBe(1);
});

test('an account with a processing payout cannot be deleted', function () {
    [$tenant, $user] = payoutDeletionHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 12_000,
        'status' => EventPayout::STATUS_PROCESSING,
    ]);

    $host = payoutDeletionHostUrl();

    $this->actingAs($user)->deleteJson("http://{$host}/payout-accounts/{$account->id}", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect(TenantPayoutAccount::find($account->id))->not->toBeNull();
});

test('an account with a scheduled payout cannot be deleted', function () {
    [$tenant, $user] = payoutDeletionHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $host = payoutDeletionHostUrl();

    $this->actingAs($user)->deleteJson("http://{$host}/payout-accounts/{$account->id}", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect(TenantPayoutAccount::find($account->id))->not->toBeNull();
});

test('an account with only failed payouts can still be deleted', function () {
    [$tenant, $user] = payoutDeletionHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);
    EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'status' => EventPayout::STATUS_FAILED,
    ]);

    $host = payoutDeletionHostUrl();

    $this->actingAs($user)->deleteJson("http://{$host}/payout-accounts/{$account->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect(TenantPayoutAccount::find($account->id))->toBeNull();
});

test('an account with no payouts at all can be deleted', function () {
    [$tenant, $user] = payoutDeletionHost();
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $host = payoutDeletionHostUrl();

    $this->actingAs($user)->deleteJson("http://{$host}/payout-accounts/{$account->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();

    expect(TenantPayoutAccount::find($account->id))->toBeNull();
});
