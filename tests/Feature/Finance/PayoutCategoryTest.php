<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\Event;
use App\Models\EventPayout;
use App\Models\Tenant;
use App\Models\TenantPayoutAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->event = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->account = TenantPayoutAccount::factory()->create(['tenant_id' => $this->tenant->id]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payoutAttributes(Tenant $tenant, TenantPayoutAccount $account, array $overrides = []): array
{
    return $overrides + [
        'tenant_id' => $tenant->id,
        'payout_account_id' => $account->id,
        'amount' => 5_000,
        'status' => EventPayout::STATUS_SCHEDULED,
    ];
}

test('a payout for an event is an event payout without anyone saying so', function (): void {
    $payout = EventPayout::create(payoutAttributes($this->tenant, $this->account, ['event_id' => $this->event->id]));

    expect($payout->category)->toBe(LedgerCategoryEnum::Event);
});

test('a payout through an event relation is an event payout', function (): void {
    $payout = $this->event->payouts()->create(payoutAttributes($this->tenant, $this->account));

    expect($payout->category)->toBe(LedgerCategoryEnum::Event);
});

test('a payout with no event and no category is refused with a clear message', function (): void {
    expect(fn () => EventPayout::create(payoutAttributes($this->tenant, $this->account)))
        ->toThrow(LogicException::class, 'A payout without an event needs an accounting category.');
});

test('an event-less payout is allowed when it names its category', function (): void {
    $payout = EventPayout::create(payoutAttributes($this->tenant, $this->account, ['category' => LedgerCategoryEnum::Dues]));

    expect($payout->event_id)->toBeNull()
        ->and($payout->fresh()->category)->toBe(LedgerCategoryEnum::Dues)
        ->and($payout->event)->toBeNull();
});

test('the database rejects a dues payout that names an event', function (): void {
    $attempt = fn () => DB::connection('landlord')->transaction(
        fn () => EventPayout::create(payoutAttributes($this->tenant, $this->account, ['event_id' => $this->event->id, 'category' => LedgerCategoryEnum::Dues]))
    );

    expect($attempt)->toThrow(QueryException::class);
});

test('the database rejects an event payout that names no event', function (): void {
    $attempt = fn () => DB::connection('landlord')->transaction(
        fn () => EventPayout::create(payoutAttributes($this->tenant, $this->account, ['category' => LedgerCategoryEnum::Event]))
    );

    expect($attempt)->toThrow(QueryException::class);
});
