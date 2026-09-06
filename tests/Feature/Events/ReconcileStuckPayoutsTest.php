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
