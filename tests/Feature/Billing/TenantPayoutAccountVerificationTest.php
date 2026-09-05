<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function payoutAccountHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('adding a payout account resolves it against the settlement gateway and stores the verified name', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'Kwame Asante'],
        ]),
    ]);

    [$tenant, $user] = payoutAccountHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Absa Bank Ghana — current',
        'account_name' => 'Purpledot Limited',
        'account_number' => '1234567894417',
        'bank_code' => '030',
    ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    expect($response->json('is_verified'))->toBeTrue();

    $account = TenantPayoutAccount::where('tenant_id', $tenant->id)->firstOrFail();
    expect($account->is_verified)->toBeTrue();
    expect($account->resolved_account_name)->toBe('Kwame Asante');
    expect($account->bank_code)->toBe('030');
});

test('adding a payout account is rejected when the account cannot be resolved', function () {
    Http::fake([
        'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
    ]);

    [$tenant, $user] = payoutAccountHost();
    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/payout-accounts", [
        'type' => 'bank',
        'label' => 'Absa Bank Ghana — current',
        'account_name' => 'Purpledot Limited',
        'account_number' => '0000000000',
        'bank_code' => '030',
    ], ['HTTP_HOST' => $host]);

    $response->assertStatus(422);
    expect(TenantPayoutAccount::where('tenant_id', $tenant->id)->count())->toBe(0);
});
