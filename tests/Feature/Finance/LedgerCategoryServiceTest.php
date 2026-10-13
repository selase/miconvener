<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use App\Services\Finance\CommissionCredit;
use App\Services\Finance\LedgerService;

/**
 * @return array<int, array{code: string, direction: string, amount: int}>
 */
function balancedEntries(int $gross = 1000): array
{
    return [
        ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $gross],
        ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $gross],
    ];
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id, 'currency' => 'GHS']);
    $this->ledger = app(LedgerService::class);
});

test('a transaction with an event is an event transaction on event accounts', function (): void {
    $transaction = $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'REF-1', balancedEntries());

    expect($transaction->category)->toBe(LedgerCategoryEnum::Event);

    $categories = LedgerAccount::query()->withoutGlobalScopes()->where('event_id', $this->event->id)->pluck('category')->unique()->all();
    expect($categories)->toHaveCount(1)->and($categories[0])->toBe(LedgerCategoryEnum::Event);
});

test('a transaction with no event and no category is refused with a clear message', function (): void {
    expect(fn () => $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'x', 'REF-2', balancedEntries()))
        ->toThrow(InvalidArgumentException::class, 'A ledger category is required when there is no event.');
});

test('a category that does not fit the event is refused', function (): void {
    expect(fn () => $this->ledger->postTransaction($this->tenant, $this->event, 'adjustment', 'x', 'REF-3', balancedEntries(), null, 'GHS', LedgerCategoryEnum::Dues))
        ->toThrow(InvalidArgumentException::class, 'The dues category cannot be posted with an event.');

    expect(fn () => $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'x', 'REF-4', balancedEntries(), null, 'GHS', LedgerCategoryEnum::Event))
        ->toThrow(InvalidArgumentException::class, 'The event category cannot be posted without an event.');
});

test('dues money posts to event-less dues accounts', function (): void {
    $transaction = $this->ledger->postTransaction($this->tenant, null, 'adjustment', 'Dues', 'REF-5', balancedEntries(), null, 'GHS', LedgerCategoryEnum::Dues);

    expect($transaction->category)->toBe(LedgerCategoryEnum::Dues)
        ->and($transaction->event_id)->toBeNull();

    expect(LedgerAccount::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->whereNull('event_id')->where('category', LedgerCategoryEnum::Dues->value)->count())->toBe(6);
});

test('marketplace money tied to an event stays out of that event\'s accounts', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'T-1', balancedEntries(1000));
    $this->ledger->postTransaction($this->tenant, $this->event, 'marketplace_booking', 'Venue', 'M-1', balancedEntries(500), null, 'GHS', LedgerCategoryEnum::Marketplace);

    $trial = collect($this->ledger->getTrialBalance($this->event)['accounts'])->keyBy('code');

    expect((int) $trial[LedgerAccount::CODE_ORGANIZER_PAYABLE]['credits'])->toBe(1000)
        ->and((int) $trial[LedgerAccount::CODE_GATEWAY_CLEARING]['debits'])->toBe(1000);
});

test('commission credit payouts post under their own category', function (): void {
    app(CommissionCredit::class)->grant($this->tenant, 'GHS', 500);

    app(CommissionCredit::class)->settle($this->tenant, 'GHS', 200);

    $transaction = LedgerTransaction::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('transaction_type', LedgerTransaction::TYPE_PAYOUT)->firstOrFail();
    expect($transaction->category)->toBe(LedgerCategoryEnum::CommissionCredit);
});

test('an existing event trial balance is unchanged by the category', function (): void {
    $this->ledger->postTransaction($this->tenant, $this->event, 'ticket_sale', 'Sale', 'T-2', balancedEntries(1000));

    $trial = $this->ledger->getTrialBalance($this->event);

    expect($trial['is_balanced'])->toBeTrue();
});
