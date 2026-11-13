<?php

declare(strict_types=1);

use App\Models\BadgeTemplatePurchase;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Services\Badges\BadgeTemplateReserve;
use App\Services\Finance\LedgerService;
use App\Services\Marketplace\StorePaymentConfirmer;

beforeEach(function (): void {
    refreshTenantDatabases();
    Illuminate\Support\Facades\Queue::fake();
    $this->purchase = BadgeTemplatePurchase::factory()->create();
    $this->seller = $this->purchase->order->tenant;
    app(LedgerService::class)->postTransaction($this->seller, null, 'badge_template_reserve_funding', 'Synthetic reserve funding', 'reserve-fixture', [
        ['code' => '1020', 'direction' => 'debit', 'amount' => 5000], ['code' => '1010', 'direction' => 'credit', 'amount' => 5000],
    ], null, 'GHS', App\Enum\LedgerCategoryEnum::Commerce);
});

test('an open licence quote reserves the full refund capacity and releases untouched expiry', function (): void {
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(0);
    $this->travel(11)->minutes();
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(5000);
});

test('unknown attempts keep their capacity after quote expiry', function (): void {
    StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id, 'status' => 'unknown']);
    $this->travel(11)->minutes();
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(0);
});

test('nonrefundable quotes and settled licences do not reserve ordinary refund capacity', function (): void {
    $this->purchase->update(['state' => 'cancelled']);
    $this->purchase = BadgeTemplatePurchase::factory()->create([
        'order_id' => App\Models\StoreOrder::factory()->badgeTemplate()->state(['agreement_id' => $this->purchase->order->agreement_id]),
        'terms_snapshot' => ['refund_policy' => 'non_refundable', 'refund_days' => 0],
    ]);
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(5000);
    $this->purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
    app(StorePaymentConfirmer::class)->confirm(StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]));
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(5000);
});

test('an unused paid licence reserves its full ordinary refund and use releases that contingent capacity', function (): void {
    $this->purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]);
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(0);
    $this->purchase->licence->update(['first_used_at' => now()]);
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(5000);
});

test('ordinary refund exposure ends at the frozen seven-calendar-day deadline', function (): void {
    $this->purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
    app(StorePaymentConfirmer::class)->confirm(StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]));
    $this->travel(7)->days();
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(5000);
});

test('revised evidence of the same payment does not reserve the money twice', function (): void {
    $this->purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
    $original = StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]);
    app(StorePaymentConfirmer::class)->confirm($original);
    StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id, 'observation_version' => 2, 'payload_hash' => hash('sha256', 'revised'), 'processing_state' => 'review']);
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(0);
});

test('support refunds cannot spend cash backing another pending event licence', function (): void {
    $this->purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]);
    app(StorePaymentConfirmer::class)->confirm($capture);
    $this->purchase->licence->update(['first_used_at' => now()]);
    BadgeTemplatePurchase::factory()->create(['order_id' => App\Models\StoreOrder::factory()->badgeTemplate()->state(['agreement_id' => $this->purchase->order->agreement_id])]);
    $refund = App\Models\StoreRefund::factory()->create(['order_id' => $this->purchase->order_id, 'attempt_id' => $attempt->id, 'observation_id' => $capture->id,
        'amount_pesewas' => 5000, 'seller_debit_pesewas' => 0, 'commission_return_pesewas' => 0, 'review_liability_pesewas' => 0, 'restock' => false, 'fee_bearer' => 'platform']);
    expect(app(App\Services\Marketplace\StoreBalance::class)->refundBacked($refund))->toBeFalse();
});

test('a definitively failed attempt releases an expired unused purchase hold', function (): void {
    StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id, 'status' => 'failed']);
    $this->travel(11)->minutes();
    expect(app(BadgeTemplateReserve::class)->available($this->seller))->toBe(5000);
});
