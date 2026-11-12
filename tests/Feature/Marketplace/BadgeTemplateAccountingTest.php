<?php

declare(strict_types=1);

use App\Enum\LedgerCategoryEnum;
use App\Models\BadgeTemplatePurchase;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\StoreOrder;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Services\Finance\LedgerService;
use App\Services\Marketplace\BadgeTemplateOffering;
use App\Services\Marketplace\CommerceInventoryLocks;
use App\Services\Marketplace\CommerceOfferingRegistry;
use App\Services\Marketplace\CommerceOrderLocks;
use App\Services\Marketplace\StoreBalance;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->purchase = BadgeTemplatePurchase::factory()->create();
    $this->order = $this->purchase->order;
    $this->attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->order->id]);
    $this->capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id, 'gateway_fee_pesewas' => 100]);
});

test('a template sale books platform revenue and the gateway cost without seller payable', function (): void {
    $transaction = app(LedgerService::class)->recordBadgeTemplateSale($this->order, $this->attempt, $this->capture);
    $lines = $transaction->entries()->with('account')->get();
    expect($transaction->transaction_type)->toBe('badge_template_sale')
        ->and($transaction->category)->toBe(LedgerCategoryEnum::Commerce)
        ->and($transaction->event_id)->toBeNull()
        ->and($lines->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount'))->toBe(5000)
        ->and($lines->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount'))->toBe(5000);
    $amounts = $lines->mapWithKeys(fn ($line) => [$line->account->code => $line->amount])->sortKeys()->all();
    expect($amounts)->toBe(['1000' => 4900, '4010' => 5000, '5000' => 100])
        ->and(app(StoreBalance::class)->available($this->order->tenant))->toBe(0);
    expect(app(LedgerService::class)->recordBadgeTemplateSale($this->order, $this->attempt, $this->capture)->id)->toBe($transaction->id);
});

test('template accounting refuses money from another account or a different amount', function (): void {
    $wrongAccount = StorePaymentObservation::factory()->create(['attempt_id' => StorePaymentAttempt::factory()->create(['order_id' => $this->order->id, 'status' => 'failed'])->id, 'account_matches' => false]);
    expect(fn () => app(LedgerService::class)->recordBadgeTemplateSale($this->order, $this->attempt, $wrongAccount))->toThrow(InvalidArgumentException::class);
    $wrongAmount = StorePaymentObservation::factory()->create(['attempt_id' => StorePaymentAttempt::factory()->create(['order_id' => $this->order->id, 'status' => 'failed'])->id, 'amount_pesewas' => 1]);
    expect(fn () => app(LedgerService::class)->recordBadgeTemplateSale($this->order, $wrongAmount->attempt, $wrongAmount))->toThrow(InvalidArgumentException::class);
});

test('the persisted digital type requires its own offering and lock route', function (): void {
    expect(app(CommerceOfferingRegistry::class)->for($this->order))->toBeInstanceOf(BadgeTemplateOffering::class);
    $this->order->offering_type = 'purchase';
    expect(app(CommerceOfferingRegistry::class)->for($this->order))->toBeInstanceOf(BadgeTemplateOffering::class);
    $this->order->getConnection()->transaction(function (): void {
        expect(app(CommerceOrderLocks::class)->order($this->order)->id)->toBe($this->order->id)
            ->and(fn () => app(CommerceInventoryLocks::class)->order($this->order))->toThrow(LogicException::class);
    });
});

test('a digital order with no typed purchase source cannot enter its money path', function (): void {
    $orphan = StoreOrder::factory()->badgeTemplate()->create();
    expect(fn () => app(CommerceOfferingRegistry::class)->for($orphan))->toThrow(LogicException::class)
        ->and(fn () => $orphan->getConnection()->transaction(fn () => app(CommerceOrderLocks::class)->order($orphan)))->toThrow(LogicException::class);
});

test('a confirmed digital refund reverses revenue against the protected reserve and retains the processing expense', function (): void {
    $ledger = app(LedgerService::class);
    $sale = $ledger->recordBadgeTemplateSale($this->order, $this->attempt, $this->capture);
    $this->capture->update(['ledger_transaction_id' => $sale->id, 'processing_state' => 'posted']);
    $ledger->postTransaction($this->order->tenant, null, 'badge_template_reserve_funding', 'Test reserve funding', 'test-reserve', [
        ['code' => '1020', 'direction' => 'debit', 'amount' => 5000],
        ['code' => '1010', 'direction' => 'credit', 'amount' => 5000],
    ], null, 'GHS', LedgerCategoryEnum::Commerce);
    $refund = App\Models\StoreRefund::factory()->create(['order_id' => $this->order->id, 'attempt_id' => $this->attempt->id, 'observation_id' => $this->capture->id,
        'amount_pesewas' => 5000, 'seller_debit_pesewas' => 0, 'commission_return_pesewas' => 0, 'gateway_fee_pesewas' => 100,
        'restock' => false, 'fee_bearer' => 'platform', 'provider_status' => 'processed', 'provider_processed_at' => now()]);
    expect(app(StoreBalance::class)->refundBacked($refund))->toBeTrue();
    $transaction = $ledger->recordBadgeTemplateRefund($refund);
    $lines = $transaction->entries()->with('account')->get();
    expect($lines->mapWithKeys(fn ($line) => [$line->account->code => [$line->direction, $line->amount]])->sortKeys()->all())
        ->toBe(['1020' => ['credit', 5000], '4010' => ['debit', 5000]])
        ->and($ledger->recordBadgeTemplateRefund($refund)->id)->toBe($transaction->id)
        ->and(app(StoreBalance::class)->available($this->order->tenant))->toBe(0);
    $fee = LedgerAccount::withoutGlobalScopes()->where('tenant_id', $this->order->tenant_id)->where('category', 'commerce')->where('code', '5000')->firstOrFail();
    expect($fee->currentBalance())->toBe(100);
});

test('the digital refund policy never debits a merchant or restocks physical goods', function (): void {
    $policy = app(CommerceOfferingRegistry::class)->for($this->order)->refundAllocation($this->order, $this->capture, true);
    expect($policy->sellerDebitPesewas)->toBe(0)
        ->and($policy->commissionReturnPesewas)->toBe(0)
        ->and($policy->reviewLiabilityPesewas)->toBe(0)
        ->and($policy->restock)->toBeFalse()
        ->and($policy->feeBearer)->toBe('platform');
});
