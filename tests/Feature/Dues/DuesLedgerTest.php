<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Member;
use App\Services\Finance\CommercialTerms;
use App\Services\Finance\LedgerService;
use App\Services\Finance\PlatformEarnings;
use App\Services\Finance\ResolvedTerms;

beforeEach(fn () => refreshTenantDatabases());

if (! function_exists('duesTestCharge')) {
    function duesTestCharge(int $amount = 10000): DuesCharge
    {
        $member = Member::factory()->create();

        return DuesCharge::factory()->create([
            'tenant_id' => $member->tenant_id, 'member_id' => $member->id,
            'membership_type_id' => $member->membership_type_id, 'amount' => $amount,
        ]);
    }
}

if (! function_exists('duesTestPayableFor')) {
    function duesTestPayableFor(string $tenantId): int
    {
        return LedgerAccount::where('tenant_id', $tenantId)->where('category', LedgerCategoryEnum::Dues)
            ->where('code', LedgerAccount::CODE_ORGANIZER_PAYABLE)->firstOrFail()->currentBalance();
    }
}

if (! function_exists('duesTestTotals')) {
    /**
     * @return array{debits: int, credits: int}
     */
    function duesTestTotals(LedgerTransaction $tx): array
    {
        return [
            'debits' => (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'debit')->sum('amount'),
            'credits' => (int) LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'credit')->sum('amount'),
        ];
    }
}

test('the dues commission is one percent, rounded, and zero for zero', function () {
    $fee = app(CommercialTerms::class);
    $terms = new ResolvedTerms(1.0, null, 'organizer', 'platform');

    expect($fee->commission($terms, 10000))->toBe(100)->and($fee->commission($terms, 150))->toBe(2)->and($fee->commission($terms, 0))->toBe(0);
});

test('a platform-default dues payment posts a balanced dues-category transaction', function () {
    $charge = duesTestCharge(10000);

    $tx = app(LedgerService::class)->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1', 150);

    expect($tx->category)->toBe(LedgerCategoryEnum::Dues)->and($tx->event_id)->toBeNull()
        ->and($tx->transaction_type)->toBe(LedgerTransaction::TYPE_DUES_PAYMENT);
    expect(duesTestTotals($tx))->toBe(['debits' => 9850, 'credits' => 9850]);
    expect(duesTestPayableFor($charge->tenant_id))->toBe(9750);
});

test('posting the same reference twice does not double the payable', function () {
    $charge = duesTestCharge(10000);
    $ledger = app(LedgerService::class);

    $first = $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1', 0);
    $second = $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1', 0);

    expect($second->id)->toBe($first->id);
    expect(duesTestPayableFor($charge->tenant_id))->toBe(9900);
});

test('dues payable is kept apart from every other category', function () {
    $charge = duesTestCharge();
    app(LedgerService::class)->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1');

    expect(LedgerAccount::where('tenant_id', $charge->tenant_id)->where('category', '<>', LedgerCategoryEnum::Dues)->count())->toBe(0);
});

test('a zero dues payment posts nothing and a gateway fee as large as the payment is refused', function () {
    $charge = duesTestCharge();
    $ledger = app(LedgerService::class);

    expect($ledger->recordDuesPayment($charge->tenant, $charge, 0, 0, 'ref_0'))->toBeNull();
    expect(fn () => $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_x', 10000))
        ->toThrow(InvalidArgumentException::class);
});

test('an own-gateway dues payment books only the commission and no payable to the organisation', function () {
    $charge = duesTestCharge(10000);

    $tx = app(LedgerService::class)->recordOwnGatewayDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_2', 0, 'paystack', 100, 0);

    expect($tx->category)->toBe(LedgerCategoryEnum::Dues)->and($tx->event_id)->toBeNull()
        ->and($tx->transaction_type)->toBe(LedgerTransaction::TYPE_DUES_PAYMENT);
    expect(duesTestTotals($tx))->toBe(['debits' => 100, 'credits' => 100]);
    expect(duesTestPayableFor($charge->tenant_id))->toBe(0);
});

test('an own-gateway dues commission not collected at payment is booked as receivable', function () {
    $charge = duesTestCharge(10000);

    $tx = app(LedgerService::class)->recordOwnGatewayDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_3', 0, 'paystack', 0, 0);

    expect($tx->transaction_type)->toBe(LedgerTransaction::TYPE_COMMISSION_RECEIVABLE)
        ->and($tx->category)->toBe(LedgerCategoryEnum::Dues);
    expect(duesTestTotals($tx))->toBe(['debits' => 100, 'credits' => 100]);
    expect(duesTestPayableFor($charge->tenant_id))->toBe(-100);
});

test('an own-gateway dues commission netted against credit stays a dues payment', function () {
    $charge = duesTestCharge(10000);

    $tx = app(LedgerService::class)->recordOwnGatewayDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_4', 0, 'paystack', 40, 60);

    expect($tx->transaction_type)->toBe(LedgerTransaction::TYPE_DUES_PAYMENT);
    expect(duesTestTotals($tx))->toBe(['debits' => 100, 'credits' => 100]);
    expect(LedgerEntry::where('transaction_id', $tx->id)->where('direction', 'debit')->pluck('amount')->map(fn ($a): int => (int) $a)->sort()->values()->all())
        ->toBe([40, 60]);
});

test('a dues payout clears the payable and posts in the dues category', function () {
    $charge = duesTestCharge(10000);
    $ledger = app(LedgerService::class);
    $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1');
    $payout = EventPayout::factory()->create(['tenant_id' => $charge->tenant_id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues, 'amount' => 9900]);

    $tx = $ledger->recordDuesPayout($charge->tenant, 9900, 'po_1', $payout, 'GHS');

    expect($tx->category)->toBe(LedgerCategoryEnum::Dues)->and($tx->transaction_type)->toBe(LedgerTransaction::TYPE_PAYOUT)
        ->and($tx->event_id)->toBeNull();
    expect(duesTestPayableFor($charge->tenant_id))->toBe(0);
});

test('a dues payout whose transfer fee the platform absorbs books the fee as an expense', function () {
    $charge = duesTestCharge(10000);
    $ledger = app(LedgerService::class);
    $ledger->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1');
    $payout = EventPayout::factory()->create(['tenant_id' => $charge->tenant_id, 'event_id' => null, 'category' => LedgerCategoryEnum::Dues, 'amount' => 9900]);

    $tx = $ledger->recordDuesPayout($charge->tenant, 9900, 'po_2', $payout, 'GHS', 100, true);

    expect(duesTestTotals($tx))->toBe(['debits' => 10000, 'credits' => 10000]);
    expect(duesTestPayableFor($charge->tenant_id))->toBe(0);
});

test('platform earnings show dues commission on its own line', function () {
    $charge = duesTestCharge(10000);
    app(LedgerService::class)->recordDuesPayment($charge->tenant, $charge, 10000, 100, 'ref_1');

    $lines = collect(app(PlatformEarnings::class)->between(now()->subDay(), now()->addDay())['lines'])->keyBy('label');

    expect($lines['Dues commission']['amount'])->toBe(100)
        ->and($lines['Dues commission']['count'])->toBe(1);
});
