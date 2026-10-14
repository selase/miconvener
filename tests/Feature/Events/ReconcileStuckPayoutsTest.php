<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    refreshTenantDatabases();
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
});

function stuckPayout(array $attributes = []): EventPayout
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    return EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'payout_ref_stuck_1',
        'updated_at' => now()->subHour(),
        ...$attributes,
    ]);
}

test('a stuck payout whose transfer succeeded is marked paid and reaches the ledger', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $payout = stuckPayout();

    Artisan::call('payouts:reconcile');

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PAID)->paid_at->not->toBeNull();
    expect($payout->fresh()->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->count())->toBe(1);
});

test('a stuck payout whose transfer failed is marked failed and becomes re-sendable', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'failed']])]);

    $payout = stuckPayout();

    Artisan::call('payouts:reconcile');

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_FAILED);
    expect($payout->fresh()->failure_reason)->toContain('failed');
});

test('a payout still in flight is left alone', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'pending']])]);

    $payout = stuckPayout();

    Artisan::call('payouts:reconcile');

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PROCESSING);
});

test('a provider outage leaves the payout untouched rather than recording a failure', function () {
    // A verification that cannot be completed says nothing about the transfer.
    // Recording it as failed would re-arm a payout whose money may already be gone.
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['message' => 'boom'], 500)]);

    $payout = stuckPayout();

    Artisan::call('payouts:reconcile');

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PROCESSING);
});

test('a payout that has only just started processing is not chased yet', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $payout = stuckPayout(['updated_at' => now()]);

    Artisan::call('payouts:reconcile');

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PROCESSING);
    Http::assertNothingSent();
});

function stuckDuesPayout(array $attributes = []): EventPayout
{
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    return EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => null,
        'category' => \App\Enum\LedgerCategoryEnum::Dues,
        'payout_account_id' => $account->id,
        'amount' => 4_000,
        'currency' => 'GHS',
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'payout_ref_dues_stuck',
        'updated_at' => now()->subHours(2),
        ...$attributes,
    ]);
}

test('a stuck dues payout whose transfer succeeded is marked paid with one dues ledger posting, and a re-run changes nothing', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $payout = stuckDuesPayout();
    $postings = fn () => \App\Models\LedgerTransaction::where('tenant_id', $payout->tenant_id)
        ->where('reference', 'payout_ref_dues_stuck')
        ->where('transaction_type', \App\Models\LedgerTransaction::TYPE_PAYOUT)
        ->get();

    expect(Artisan::call('payouts:reconcile'))->toBe(0);

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PAID)->paid_at->not->toBeNull();
    expect($postings())->toHaveCount(1)
        ->and($postings()->first()->category)->toBe(\App\Enum\LedgerCategoryEnum::Dues);

    Artisan::call('payouts:reconcile');

    expect($postings())->toHaveCount(1);
});

test('a dues payout does not stop an event payout later in the same run from being reconciled', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $dues = stuckDuesPayout();
    $event = stuckPayout();

    expect(Artisan::call('payouts:reconcile'))->toBe(0);

    expect($dues->fresh()->status)->toBe(EventPayout::STATUS_PAID)
        ->and($event->fresh()->status)->toBe(EventPayout::STATUS_PAID)
        ->and($event->fresh()->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->count())->toBe(1);
});

test('a stuck dues payout whose transfer failed is marked failed and posts nothing', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'reversed']])]);

    $payout = stuckDuesPayout();

    Artisan::call('payouts:reconcile');

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_FAILED)
        ->and($payout->fresh()->failure_reason)->toContain('reversed')
        ->and(\App\Models\LedgerTransaction::where('tenant_id', $payout->tenant_id)->count())->toBe(0);
});

test('a stuck event-less payout outside the dues category is marked paid but posts nothing', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $payout = stuckDuesPayout(['category' => \App\Enum\LedgerCategoryEnum::CommissionCredit, 'provider_reference' => 'payout_ref_other']);

    expect(Artisan::call('payouts:reconcile'))->toBe(0);

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_PAID)
        ->and(\App\Models\LedgerTransaction::where('tenant_id', $payout->tenant_id)->count())->toBe(0);
});

test('payouts left waiting for a code past its lifetime are failed when the transfer died, fresh ones are left alone', function () {
    Http::fake([
        'api.paystack.co/transfer/verify/payout_ref_dues_otp' => Http::response(['data' => ['status' => 'abandoned']]),
        'api.paystack.co/transfer/verify/payout_ref_event_otp' => Http::response(['data' => ['status' => 'abandoned']]),
        'api.paystack.co/transfer/verify/payout_ref_fresh_otp' => Http::response(['data' => ['status' => 'abandoned']]),
    ]);

    $dues = stuckDuesPayout(['status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'payout_ref_dues_otp', 'updated_at' => now()->subMinutes(40)]);
    $event = stuckPayout(['status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'payout_ref_event_otp', 'updated_at' => now()->subMinutes(40)]);
    $fresh = stuckPayout(['status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'payout_ref_fresh_otp', 'updated_at' => now()->subMinutes(10)]);

    expect(Artisan::call('payouts:reconcile'))->toBe(0);

    expect($dues->fresh()->status)->toBe(EventPayout::STATUS_FAILED)
        ->and($dues->fresh()->failure_reason)->toBe('The one-time code expired before it was entered. Send the payout again.')
        ->and($event->fresh()->status)->toBe(EventPayout::STATUS_FAILED)
        ->and($fresh->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'payout_ref_fresh_otp'));
});

test('a payout waiting for a code whose transfer is still waiting, or cannot be checked, is left alone', function () {
    Http::fake([
        'api.paystack.co/transfer/verify/payout_ref_still_otp' => Http::response(['data' => ['status' => 'otp']]),
        'api.paystack.co/transfer/verify/payout_ref_outage' => Http::response(['message' => 'boom'], 500),
    ]);

    $waiting = stuckPayout(['status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'payout_ref_still_otp', 'updated_at' => now()->subHour()]);
    $outage = stuckDuesPayout(['status' => EventPayout::STATUS_AWAITING_OTP, 'provider_reference' => 'payout_ref_outage', 'updated_at' => now()->subHour()]);

    Artisan::call('payouts:reconcile');

    expect($waiting->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP)
        ->and($outage->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
});

test('a payout waiting for a code is never marked paid by the sweep', function () {
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $payout = stuckPayout(['status' => EventPayout::STATUS_AWAITING_OTP, 'updated_at' => now()->subHour()]);

    Artisan::call('payouts:reconcile');

    expect($payout->fresh()->status)->toBe(EventPayout::STATUS_AWAITING_OTP);
});
