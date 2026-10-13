<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayoutSchedule;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create([
        'tenant_id' => $this->tenant->id,
        'currency' => 'GHS',
        'starts_at' => now()->subDays(10),
        'ends_at' => now()->subDays(8),
    ]);
    $this->ledger = app(LedgerService::class);
});

function marketplaceBookingFor(Event $event, Tenant $tenant, int $gross): void
{
    app(LedgerService::class)->postTransaction($tenant, $event, 'marketplace_booking', 'Venue booking', 'MKT-'.$gross, [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $gross],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $gross - 500],
        ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => 500],
    ], null, 'GHS', LedgerCategoryEnum::Marketplace);
}

test('marketplace money booked against an event never feeds that event payout', function (): void {
    $account = TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id, 'is_verified' => true]);
    EventPayoutSchedule::create([
        'tenant_id' => $this->tenant->id,
        'event_id' => $this->event->id,
        'schedule_type' => 'post_event',
        'days_after_event' => 3,
        'holdback_percentage' => 10,
        'holdback_release_days' => 14,
        'minimum_payout_amount' => 1000,
        'auto_payout_enabled' => true,
        'preferred_account_id' => $account->id,
    ]);
    $registration = EventRegistration::factory()->create(['event_id' => $this->event->id, 'tenant_id' => $this->tenant->id]);

    // The marketplace booking goes first, so its accounts are created first and
    // an unfiltered lookup would find them instead of the event's.
    marketplaceBookingFor($this->event, $this->tenant, 5_000);
    $this->ledger->recordTicketSale($this->event, $registration, 10_000, 500, 'PAY-1');

    Artisan::call('app:reconcile-payout-schedules');

    // 10,000 gross, 500 commission, 10% of gross held back: the same 8,500 as
    // without the marketplace booking.
    expect($this->event->payouts()->firstOrFail()->amount)->toBe(8_500);
});

test('an event lists only its own ledger transactions', function (): void {
    $registration = EventRegistration::factory()->create(['event_id' => $this->event->id, 'tenant_id' => $this->tenant->id]);
    $this->ledger->recordTicketSale($this->event, $registration, 10_000, 500, 'PAY-2');
    marketplaceBookingFor($this->event, $this->tenant, 5_000);

    $references = $this->event->ledgerTransactions()->pluck('reference')->all();

    expect($references)->toContain('PAY-2')
        ->and($references)->not->toContain('MKT-5000');
});
