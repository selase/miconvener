<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\TransferFeeSchedule;
use App\Services\Settlement\PaystackSettlementGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('mobile money transfers cost one cedi', function () {
    expect(app(TransferFeeSchedule::class)->feeFor(TenantPayoutAccount::TYPE_MOBILE_MONEY))->toBe(100);
});

test('bank transfers cost eight cedis', function () {
    expect(app(TransferFeeSchedule::class)->feeFor(TenantPayoutAccount::TYPE_BANK))->toBe(800);
});

test('an unknown destination falls back to the dearer bank fee', function () {
    expect(app(TransferFeeSchedule::class)->feeFor('carrier_pigeon'))->toBe(800);
});

test('a fee reported by the provider overrides the schedule', function () {
    expect(app(TransferFeeSchedule::class)->feeFromProviderResponse([
        'transfer_code' => 'TRF_test_123',
        'status' => 'pending',
        'fee' => 150,
    ]))->toBe(150);
});

test('a provider response without a fee yields null so the schedule is used', function () {
    expect(app(TransferFeeSchedule::class)->feeFromProviderResponse([
        'transfer_code' => 'TRF_test_123',
        'status' => 'pending',
        'fee' => null,
    ]))->toBeNull();
});

test('the gateway surfaces the transfer fee Paystack reports', function () {
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);

    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_test_123', 'status' => 'pending', 'fee_charged' => 100],
        ]),
    ]);

    $result = app(PaystackSettlementGateway::class)
        ->initiateTransfer('RCP_test_123', 699900, 'GHS', 'PAYOUT-REF-1');

    expect($result['fee'])->toBe(100)
        ->and($result['transfer_code'])->toBe('TRF_test_123');
});

test('a passed-through payout sends the organizer the amount less the fee', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);

    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_test_1', 'status' => 'pending'],
        ]),
    ]);

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $account = TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY,
        'recipient_code' => 'RCP_test_1',
    ]);

    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 700000,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])
        ->assertOk();

    $payout->refresh();

    expect($payout->amount)->toBe(700000)
        ->and($payout->transfer_fee_amount)->toBe(100)
        ->and($payout->net_paid_amount)->toBe(699900);

    // The organizer is sent GHS 6,999 and Paystack keeps the GHS 1 fee, so the
    // platform balance falls by exactly the GHS 7,000 it owed.
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/transfer')
        && (int) $request['amount'] === 699900);
});

test('a payout smaller than the bank transfer fee is refused rather than sent negative', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
    Http::fake();

    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $account = TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantPayoutAccount::TYPE_BANK,
        'recipient_code' => 'RCP_test_2',
    ]);

    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 500,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    expect($payout->refresh()->status)->toBe(EventPayout::STATUS_SCHEDULED);
    Http::assertNothingSent();
});
