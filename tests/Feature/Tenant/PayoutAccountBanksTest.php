<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function fetchPayoutBanks(string $secretKey): array
{
    config(['services.settlement.paystack.secret_key' => $secretKey]);
    Http::fake(['*/bank*' => Http::response(['data' => [['name' => 'Absa Bank Ghana Limited', 'code' => '030100']]])]);

    [, $user] = eventHost('acme');

    return test()->actingAs($user)
        ->withServerVariables(['HTTP_HOST' => eventSubdomainHost('acme')])
        ->getJson(route('tenant.payout-accounts.banks', ['subdomain' => 'acme']))
        ->assertOk()
        ->json();
}

test('a test Paystack key adds the Paystack test bank to the bank list', function () {
    $banks = fetchPayoutBanks('sk_test_abc123');

    expect($banks)->toContain(['name' => 'Paystack test bank (account 0000000000)', 'code' => '001'])
        ->and(collect($banks)->firstWhere('code', '030100'))->not->toBeNull();
});

test('a live Paystack key never offers the test bank', function () {
    $banks = fetchPayoutBanks('sk_live_abc123');

    expect(collect($banks)->firstWhere('code', '001'))->toBeNull();
});
