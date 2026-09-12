<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Event;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * Two ledgers record the same money: EventLedgerEntry and the double-entry
 * LedgerService. Only the platform-settled sale writes both. Every other path
 * writes the old one alone, so the trial balance reports is_balanced while
 * entire classes of transaction are missing from it.
 */
beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('a payout posts to the double-entry ledger', function () {
    $tenant = Tenant::factory()->create(['slug' => 'ledger-payout', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $service = app(LedgerService::class);
    $service->ensureAccountsExist($tenant, $event, 'GHS');

    // The brief's literal fixture omitted `payout_account_id` (a required,
    // non-nullable foreign key on event_payouts) and passed `currency`, which
    // is neither fillable nor a column on that table -- Model::shouldBeStrict()
    // throws on the latter before the former is ever reached. Built via the
    // factory instead, matching the convention already used in
    // tests/Feature/Events/ReconcileStuckPayoutsTest.php.
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 50000,
        'status' => EventPayout::STATUS_SCHEDULED,
    ]);

    $service->recordPayout($event, $payout->amount, 'PAYOUT-REF-1', $payout);

    $transaction = LedgerTransaction::where('event_id', $event->id)->firstOrFail();
    $debits = $transaction->entries()->where('direction', 'debit')->sum('amount');
    $credits = $transaction->entries()->where('direction', 'credit')->sum('amount');

    expect((int) $debits)->toBe(50000);
    expect((int) $debits)->toBe((int) $credits);

    // The organizer payable must actually fall, or the balance drifts upward
    // and never clears.
    $payable = LedgerAccount::where('event_id', $event->id)
        ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)
        ->firstOrFail();

    expect((int) $payable->entries()->where('direction', 'debit')->sum('amount'))->toBe(50000);
});

test('the trial balance stays balanced after a payout', function () {
    $tenant = Tenant::factory()->create(['slug' => 'ledger-trial', 'isolation_mode' => 'shared']);
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);

    $service = app(LedgerService::class);
    $service->ensureAccountsExist($tenant, $event, 'GHS');
    $service->recordPayout($event, 25000, 'PAYOUT-REF-2');

    expect($service->getTrialBalance($event)['is_balanced'])->toBeTrue();
});

/**
 * The two tests above exercise LedgerService directly and prove the service
 * itself is sound. They cannot catch the actual defect: three real call
 * sites built EventLedgerEntry rows and never called the service at all, so
 * a test that only calls the service would pass whether or not those sites
 * were fixed. This one drives a real call site — the payouts:reconcile
 * console command, which runs with no tenant in TenantContext — end to end.
 */
test('payouts:reconcile posts the reconciled payout to the double-entry ledger', function () {
    config(['services.settlement.paystack.secret_key' => 'sk_settlement_test_123']);
    Http::fake(['api.paystack.co/transfer/verify/*' => Http::response(['data' => ['status' => 'success']])]);

    $tenant = Tenant::factory()->create(['slug' => 'ledger-reconcile', 'isolation_mode' => 'shared']);
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $payout = EventPayout::factory()->create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'payout_account_id' => $account->id,
        'amount' => 12_000,
        'status' => EventPayout::STATUS_PROCESSING,
        'provider_reference' => 'payout_ref_parity_1',
        'updated_at' => now()->subHour(),
    ]);

    Artisan::call('payouts:reconcile');

    expect($payout->fresh())->status->toBe(EventPayout::STATUS_PAID);

    // The old single-row ledger already recorded this payout before this fix.
    expect($payout->fresh()->ledgerEntries()->where('type', EventLedgerEntry::TYPE_PAYOUT)->count())->toBe(1);

    // The double-entry ledger must record it too, or the trial balance never
    // sees this money move at all.
    $transaction = LedgerTransaction::where('event_id', $event->id)
        ->where('reference', 'payout_ref_parity_1')
        ->first();

    expect($transaction)->not->toBeNull();
    expect((int) $transaction->entries()->where('direction', 'debit')->sum('amount'))->toBe(12_000);
});
