<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/**
 * Paystack allows a single webhook URL per business account, but the platform
 * receives two unrelated streams on its own accounts: event ticket settlement
 * and SaaS subscription billing. Both platform URLs therefore reach one handler
 * that routes by payload, so the same URL serves either stream regardless of
 * whether they share one Paystack account or use two.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);

    config(['services.settlement.paystack.secret_key' => 'sk_settlement_key']);
    config(['services.paystack.secret_key' => 'sk_billing_key']);
    config(['services.paystack.metadata_source' => 'miconvener']);
});

function signedPost(string $uri, array $payload, string $secret)
{
    $body = json_encode($payload);

    return test()->call('POST', $uri, [], [], [], [
        'HTTP_x-paystack-signature' => hash_hmac('sha512', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('a subscription event is handled rather than silently discarded', function (): void {
    // This is the regression that mattered: the settlement endpoint answered 200
    // and did nothing for any non-settlement event, so pointing the account's one
    // webhook URL at it dropped every subscription event on the floor.
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);

    $paid = Package::query()->where('slug', 'growth')->firstOrFail();
    $free = Package::query()->where('slug', 'free')->firstOrFail();

    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'package_id' => $paid->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'owner@example.com']);
    $tenant->users()->attach($user->id);

    $payload = [
        'event' => 'subscription.disable',
        'data' => [
            'customer' => ['email' => 'owner@example.com'],
            'metadata' => ['source' => 'miconvener'],
        ],
    ];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_billing_key')->assertOk();

    expect((string) $tenant->fresh()->package_id)->toBe((string) $free->id);
});

test('both platform webhook urls reach the same handler', function (): void {
    $payload = ['event' => 'subscription.not_renew', 'data' => ['customer' => ['email' => 'nobody@example.com'], 'metadata' => ['source' => 'miconvener']]];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_billing_key')->assertOk();
    signedPost('/webhooks/paystack', $payload, 'sk_billing_key')->assertOk();
});

test('a settlement event signed with the billing key is rejected', function (): void {
    // The keys identify which account an event came from. A ticket charge that
    // did not come from the settlement account must not be trusted to move money.
    $payload = [
        'event' => 'charge.success',
        'data' => [
            'reference' => 'ref_wrong_key',
            'amount' => 1000,
            'currency' => 'GHS',
            'metadata' => ['type' => 'event_ticket', 'source' => 'miconvener', 'tenant_id' => 'whatever', 'event_registration_id' => 'whatever'],
        ],
    ];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_billing_key')->assertStatus(400);
});

test('an unsigned or wrongly signed payload is rejected', function (): void {
    $payload = ['event' => 'subscription.disable', 'data' => ['metadata' => ['source' => 'miconvener']]];

    signedPost('/webhooks/settlement/paystack', $payload, 'sk_not_a_real_key')->assertStatus(400);

    $this->postJson('/webhooks/settlement/paystack', $payload)->assertStatus(400);
});

test('an event from another application on the shared account is ignored', function (): void {
    // The platform's Paystack account is shared with other applications, so this
    // one webhook URL receives their events. The billing handler resolves a tenant
    // by customer email, so without an ownership check another application's
    // payment from someone who also has an account here would provision them a
    // subscription they never bought.
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);

    $paid = Package::query()->where('slug', 'growth')->firstOrFail();
    $free = Package::query()->where('slug', 'free')->firstOrFail();

    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared', 'package_id' => $paid->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'shared@example.com']);
    $tenant->users()->attach($user->id);

    $payload = [
        'event' => 'subscription.disable',
        'data' => [
            'customer' => ['email' => 'shared@example.com'],
            'metadata' => ['source' => 'some-other-app'],
        ],
    ];

    $response = signedPost('/webhooks/paystack', $payload, 'sk_billing_key');

    $response->assertOk();
    expect($response->json('status'))->toBe('ignored');

    // Untouched: the other application's event must not have downgraded them.
    expect((string) $tenant->fresh()->package_id)->toBe((string) $paid->id);
    expect((string) $tenant->fresh()->package_id)->not->toBe((string) $free->id);
});

test('an event carrying no source at all is ignored', function (): void {
    $payload = ['event' => 'subscription.disable', 'data' => ['customer' => ['email' => 'x@example.com']]];

    $response = signedPost('/webhooks/paystack', $payload, 'sk_billing_key');

    $response->assertOk();
    expect($response->json('status'))->toBe('ignored');
});

test('a transfer is accepted without a source marker', function (): void {
    // Transfers carry no metadata of ours, but the reference is one this platform
    // generated and an unmatched reference is already a no-op.
    $payload = ['event' => 'transfer.failed', 'data' => ['reference' => 'payout_not_ours', 'reason' => 'x']];

    $response = signedPost('/webhooks/settlement/paystack', $payload, 'sk_settlement_key');

    $response->assertOk();
    expect($response->json('status'))->toBe('success');
});
