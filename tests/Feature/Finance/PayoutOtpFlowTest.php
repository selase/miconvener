<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\EventPayout;
use App\Models\TenantPayoutAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

function otpPayoutFixture(string $slug = 'acme'): array
{
    [$tenant, $user] = eventHost($slug);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $account = TenantPayoutAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => TenantPayoutAccount::TYPE_MOBILE_MONEY,
        'recipient_code' => 'RCP_otp_1',
    ]);
    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 700000,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    return [$tenant, $user, $event, $payout, eventSubdomainHost($slug)];
}

test('a transfer held for a one-time code parks the payout instead of failing it', function () {
    [$tenant, $user, $event, $payout, $host] = otpPayoutFixture();

    Http::fake([
        'api.paystack.co/transfer' => Http::response([
            'status' => true,
            'data' => ['transfer_code' => 'TRF_otp_1', 'status' => 'otp'],
        ]),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('status', EventPayout::STATUS_AWAITING_OTP);

    $payout->refresh();

    expect($payout->status)->toBe(EventPayout::STATUS_AWAITING_OTP)
        ->and($payout->provider_transfer_code)->toBe('TRF_otp_1')
        // No money has moved, so nothing has been charged for moving it.
        ->and($payout->transfer_fee_amount)->toBe(0)
        ->and($payout->net_paid_amount)->toBeNull();
});

test('entering the code releases the payout and records what the transfer cost', function () {
    [$tenant, $user, $event, $payout, $host] = otpPayoutFixture();

    $payout->update([
        'status' => EventPayout::STATUS_AWAITING_OTP,
        'provider_transfer_code' => 'TRF_otp_1',
    ]);

    Http::fake([
        'api.paystack.co/transfer/finalize_transfer' => Http::response([
            'status' => true,
            'data' => ['status' => 'success'],
        ]),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/finalize", ['otp' => '123456'], ['HTTP_HOST' => $host])
        ->assertOk();

    $payout->refresh();

    expect($payout->status)->toBe(EventPayout::STATUS_PROCESSING)
        ->and($payout->transfer_fee_amount)->toBe(100)
        ->and($payout->net_paid_amount)->toBe(699900);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'finalize_transfer')
        && $request['otp'] === '123456'
        && $request['transfer_code'] === 'TRF_otp_1');
});

test('a rejected code leaves the payout waiting so it can be entered again', function () {
    [$tenant, $user, $event, $payout, $host] = otpPayoutFixture();

    $payout->update([
        'status' => EventPayout::STATUS_AWAITING_OTP,
        'provider_transfer_code' => 'TRF_otp_1',
    ]);

    Http::fake([
        'api.paystack.co/transfer/finalize_transfer' => Http::response(['status' => false, 'message' => 'Invalid OTP'], 400),
    ]);

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/finalize", ['otp' => '000000'], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    // A wrong code is not a failed payout — the transfer is still parked.
    expect($payout->refresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
});

test('a payout that is not waiting for a code cannot be finalized', function () {
    [$tenant, $user, $event, $payout, $host] = otpPayoutFixture();

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/finalize", ['otp' => '123456'], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    Http::assertNothingSent();
});

test('a parked payout cannot be sent again, which would make a second transfer', function () {
    [$tenant, $user, $event, $payout, $host] = otpPayoutFixture();

    $payout->update([
        'status' => EventPayout::STATUS_AWAITING_OTP,
        'provider_transfer_code' => 'TRF_otp_1',
    ]);

    Http::fake();

    $this->actingAs($user)
        ->post("http://{$host}/events/{$event->id}/finance/payouts/{$payout->id}/send", [], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    Http::assertNothingSent();
});
