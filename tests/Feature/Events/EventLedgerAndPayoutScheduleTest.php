<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventPayoutSchedule;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Models\User;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function ledgerScheduleHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'ledgercorp', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('ledger service enforces strict double-entry equilibrium and rejects unbalanced entries', function () {
    [$tenant] = ledgerScheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $service = app(LedgerService::class);

    // Unbalanced entries must throw InvalidArgumentException
    expect(function () use ($service, $tenant, $event) {
        $service->postTransaction(
            $tenant,
            $event,
            'test_unbalanced',
            'Unbalanced attempt',
            'REF-123',
            [
                ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 1000],
                ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 900],
            ]
        );
    })->toThrow(InvalidArgumentException::class, 'Unbalanced ledger transaction');

    // Balanced entries must succeed
    $tx = $service->postTransaction(
        $tenant,
        $event,
        'test_balanced',
        'Balanced attempt',
        'REF-456',
        [
            ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => 1000],
            ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 950],
            ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 50],
        ]
    );

    expect($tx)->not->toBeNull();
    expect($tx->entries)->toHaveCount(3);
});

test('ticket sales and refunds maintain double-entry trial balance equilibrium', function () {
    [$tenant] = ledgerScheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $registration = EventRegistration::factory()->create(['event_id' => $event->id, 'tenant_id' => $tenant->id]);
    $service = app(LedgerService::class);

    // Record ticket sale: 200 GHS gross, 10 GHS platform fee
    $tx = $service->recordTicketSale($event, $registration, 20_000, 1_000, 'PAY-TEST-001');
    expect($tx)->not->toBeNull();

    $tb = $service->getTrialBalance($event);
    expect($tb['is_balanced'])->toBeTrue();
    expect($tb['total_debits'])->toBe(20_000);
    expect($tb['total_credits'])->toBe(20_000);

    // Record refund
    $refundTx = $service->recordRefund($event, $registration, 20_000, 1_000, 'REF-TEST-001');
    expect($refundTx)->not->toBeNull();

    $tbAfterRefund = $service->getTrialBalance($event);
    expect($tbAfterRefund['is_balanced'])->toBeTrue();
    expect($tbAfterRefund['total_debits'])->toBe(40_000);
    expect($tbAfterRefund['total_credits'])->toBe(40_000);
});

test('holdback retention and release post balanced double-entry entries', function () {
    [$tenant] = ledgerScheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $service = app(LedgerService::class);

    // Retain 10% holdback reserve of 500 GHS = 50 GHS
    $service->recordHoldbackRetention($event, 5_000, 'HB-RET-001');
    $tb = $service->getTrialBalance($event);
    expect($tb['is_balanced'])->toBeTrue();

    // Release holdback reserve
    $service->recordHoldbackRelease($event, 5_000, 'HB-REL-001');
    $tbAfter = $service->getTrialBalance($event);
    expect($tbAfter['is_balanced'])->toBeTrue();
});

test('finance controller returns trial balance and allows updating payout schedule', function () {
    [$tenant, $user] = ledgerScheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "ledgercorp.{$baseDomain}";

    // Index endpoint
    $response = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/finance", ['HTTP_HOST' => $host]);
    $response->assertOk();
    $response->assertJsonStructure([
        'stats',
        'trial_balance' => ['is_balanced', 'total_debits', 'total_credits', 'accounts'],
        'payout_schedule',
        'accounts',
        'payouts',
    ]);
    expect($response->json('trial_balance.is_balanced'))->toBeTrue();

    // Update payout schedule
    $updateResponse = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/finance/payout-schedule", [
        'schedule_type' => 'post_event',
        'days_after_event' => 7,
        'holdback_percentage' => 15.0,
        'holdback_release_days' => 30,
        'minimum_payout_amount' => 5000,
        'auto_payout_enabled' => true,
        'preferred_account_id' => $account->id,
    ], ['HTTP_HOST' => $host]);

    $updateResponse->assertOk();
    $updateResponse->assertJsonPath('payout_schedule.schedule_type', 'post_event');
    $updateResponse->assertJsonPath('payout_schedule.days_after_event', 7);
    expect($updateResponse->json('payout_schedule.holdback_percentage'))->toEqual(15.0);
    $updateResponse->assertJsonPath('payout_schedule.holdback_release_days', 30);
    $updateResponse->assertJsonPath('payout_schedule.auto_payout_enabled', true);

    $schedule = EventPayoutSchedule::where('event_id', $event->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->days_after_event)->toBe(7);
});

test('reconcile payout schedules command processes matured payouts and holdback escrow', function () {
    [$tenant] = ledgerScheduleHost();
    $event = Event::factory()->create([
        'tenant_id' => $tenant->id,
        'currency' => 'GHS',
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(8),
    ]);
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $tenant->id, 'is_verified' => true]);

    $schedule = EventPayoutSchedule::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'schedule_type' => 'post_event',
        'days_after_event' => 3, // event ended 8 days ago, so matured!
        'holdback_percentage' => 10,
        'holdback_release_days' => 14,
        'minimum_payout_amount' => 1000,
        'auto_payout_enabled' => true,
        'preferred_account_id' => $account->id,
    ]);

    // Simulate ticket sale in double-entry ledger: 10,000 GHS (100.00 GHS)
    $reg = EventRegistration::factory()->create(['event_id' => $event->id, 'tenant_id' => $tenant->id]);
    app(LedgerService::class)->recordTicketSale($event, $reg, 10_000, 500, 'PAY-SIM-1');

    // Run reconciliation command
    $exitCode = Artisan::call('app:reconcile-payout-schedules');
    expect($exitCode)->toBe(0);

    // Check that holdback retention was recorded (10% of 9,500 net = 950)
    $tb = app(LedgerService::class)->getTrialBalance($event);
    expect($tb['is_balanced'])->toBeTrue();

    // Verify payout was scheduled for remaining available balance
    $payouts = $event->payouts()->get();
    expect($payouts)->not->toBeEmpty();
    $payout = $payouts->first();
    expect($payout->payout_account_id)->toBe($account->id);
    expect($payout->amount)->toBe(8_500); // 9,500 net - 1,000 (10% of 10,000 gross) holdback
});

test('settlement statement export includes double-entry general ledger trial balance', function () {
    [$tenant, $user] = ledgerScheduleHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'GHS']);
    $reg = EventRegistration::factory()->create(['event_id' => $event->id, 'tenant_id' => $tenant->id]);
    app(LedgerService::class)->recordTicketSale($event, $reg, 10_000, 500, 'CSV-TEST-1');

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "ledgercorp.{$baseDomain}";

    $response = $this->actingAs($user)->get("http://{$host}/events/{$event->id}/finance/settlement-statement", ['HTTP_HOST' => $host]);
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $content = $response->streamedContent();
    expect($content)->toContain('Double-Entry General Ledger (Trial Balance)');
    expect($content)->toContain('EQUILIBRIUM BALANCED');
});
