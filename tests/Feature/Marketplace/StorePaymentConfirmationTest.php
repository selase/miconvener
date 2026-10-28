<?php

declare(strict_types=1);

use App\Models\LedgerTransaction;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreStockMovement;
use App\Services\Marketplace\PurchaseReservations;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use Tests\Support\StoreCheckoutScenario;

it('confirms the same capture once without duplicate stock or ledger posting', function (): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $data = StoreCheckoutScenario::evidence($attempt);
    $capture = app(StorePaymentEvidence::class)->record($attempt, $data);
    $same = app(StorePaymentEvidence::class)->record($attempt, $data);
    app(StorePaymentConfirmer::class)->confirm($capture);
    app(StorePaymentConfirmer::class)->confirm($same);
    expect($same->id)->toBe($capture->id)->and($order->fresh()->payment_state)->toBe('paid')
        ->and($variant->fresh()->on_hand)->toBe(2)
        ->and(LedgerTransaction::withoutGlobalScopes()->where('reference', $attempt->reference)->count())->toBe(1)
        ->and(StoreStockMovement::query()->where('source_id', $order->id)->count())->toBe(1);
});

it('retains mismatched or incomplete captured evidence without granting an order', function (array $change): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, $change));
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($capture->fresh()->processing_state)->toBe('review')->and($order->fresh()->payment_state)->toBe('captured_review')
        ->and($variant->fresh()->on_hand)->toBe(3)
        ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count())->toBe(0);
})->with([
    [['amount' => 9999]], [['fees' => null]], [['fees' => 20000]], [['currency' => 'USD']],
    [['paid_at' => null]], [['account_fingerprint' => 'wrong-account']], [['reference' => 'foreign-ref']],
    [['metadata' => ['source' => 'another-app']]],
]);

it('never treats a reversing payment as a new successful capture', function (string $status): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    expect(fn () => app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, ['status' => $status])))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(StorePaymentObservation::query()->count())->toBe(0)
        ->and(LedgerTransaction::withoutGlobalScopes()->count())->toBe(0)
        ->and($variant->fresh()->on_hand)->toBe(3)
        ->and($order->fresh()->payment_state)->not->toBe('paid');
})->with(['reversal-pending', 'reversed']);

it('retains late or released captures as review liabilities without consuming replacement stock', function (string $change): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    if ($change === 'released') {
        app(PurchaseReservations::class)->release($order, 'cancelled');
    } else {
        $this->travel(10)->minutes();
    }
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($variant->fresh()->on_hand)->toBe(3)->and($order->fresh()->payment_state)->toBe('captured_review')
        ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_capture_review')->count())->toBe(1);
})->with(['late', 'released']);

it('persists captured money through ledger failure and repairs exactly once on retry', function (): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    $db = $order->getConnection();
    $db->statement("ALTER TABLE ledger_transactions ADD CONSTRAINT test_store_ledger_failure CHECK (transaction_type <> 'commerce_sale')");
    expect(fn () => app(StorePaymentConfirmer::class)->confirm($capture))->toThrow(Illuminate\Database\QueryException::class);
    expect(StorePaymentObservation::query()->count())->toBe(1)->and($variant->fresh()->on_hand)->toBe(3)
        ->and($order->fresh()->payment_state)->toBe('captured_review');
    $db->statement('ALTER TABLE ledger_transactions DROP CONSTRAINT test_store_ledger_failure');
    app(StorePaymentConfirmer::class)->confirm($capture);
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($variant->fresh()->on_hand)->toBe(2)->and($order->fresh()->state)->toBe('confirmed')
        ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count())->toBe(1);
});

it('records an additional successful attempt for an already paid order for refund review without restocking', function (): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    app(StorePaymentConfirmer::class)->confirm($capture);
    $second = StorePaymentAttempt::factory()->create(['order_id' => $order->id]);
    $extra = app(StorePaymentEvidence::class)->record($second, StoreCheckoutScenario::evidence($second));
    app(StorePaymentConfirmer::class)->confirm($extra);
    expect($variant->fresh()->on_hand)->toBe(2)->and($extra->fresh()->processing_state)->toBe('review')
        ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_capture_review')->count())->toBe(1);
});

it('quarantines a changed fee observation after success without another sale or consumption', function (): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $data = StoreCheckoutScenario::evidence($attempt);
    $capture = app(StorePaymentEvidence::class)->record($attempt, $data);
    app(StorePaymentConfirmer::class)->confirm($capture);
    $data['fees'] = 196;
    $changed = app(StorePaymentEvidence::class)->record($attempt, $data);
    app(StorePaymentConfirmer::class)->confirm($changed);
    expect($changed->fresh()->processing_state)->toBe('review')->and($order->fresh()->ledger_state)->toBe('review')
        ->and($variant->fresh()->on_hand)->toBe(2)->and(LedgerTransaction::withoutGlobalScopes()->count())->toBe(1);
});

it('rolls back ledger and stock together when stock posting fails while evidence survives', function (): void {
    [, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    $db = $order->getConnection();
    $db->statement("ALTER TABLE store_stock_movements ADD CONSTRAINT test_store_stock_failure CHECK (source_kind <> 'order')");
    expect(fn () => app(StorePaymentConfirmer::class)->confirm($capture))->toThrow(Illuminate\Database\QueryException::class);
    expect($variant->fresh()->on_hand)->toBe(3)->and(LedgerTransaction::withoutGlobalScopes()->count())->toBe(0)
        ->and($capture->fresh()->ledger_transaction_id)->toBeNull();
    $db->statement('ALTER TABLE store_stock_movements DROP CONSTRAINT test_store_stock_failure');
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($variant->fresh()->on_hand)->toBe(2)->and(LedgerTransaction::withoutGlobalScopes()->count())->toBe(1);
});

it('records corrected missing evidence as a new version without erasing the original observation', function (): void {
    [, , , , , $attempt] = StoreCheckoutScenario::online();
    $data = StoreCheckoutScenario::evidence($attempt);
    $first = app(StorePaymentEvidence::class)->record($attempt, [...$data, 'fees' => null]);
    app(StorePaymentConfirmer::class)->confirm($first);
    $next = app(StorePaymentEvidence::class)->record($attempt, $data);
    app(StorePaymentConfirmer::class)->confirm($next);
    expect($first->fresh()->gateway_fee_pesewas)->toBeNull()->and($next->gateway_fee_pesewas)->toBe(195)
        ->and($next->supersedes_id)->toBe($first->id)->and($next->observation_version)->toBe(2)
        ->and(StorePaymentObservation::query()->count())->toBe(2);
});

it('repairs a pending recorded capture through the scheduled reconciliation command with checkout off', function (): void {
    [$tenant, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    $tenant->features()->where('feature_key', 'commerce_checkout')->update(['enabled' => false]);
    Illuminate\Support\Facades\Http::fake();
    $this->artisan('commerce:reconcile', ['--limit' => 10])->assertSuccessful();
    expect($order->fresh()->payment_state)->toBe('paid')->and($variant->fresh()->on_hand)->toBe(2);
    Illuminate\Support\Facades\Http::assertNothingSent();
});

it('releases an expired unpaid review after verified failure but retains late capture recovery', function (): void {
    [$tenant, $variant, , $session, $order, $attempt] = StoreCheckoutScenario::online();
    $this->travel(11)->minutes();
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::sequence()->push(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'pending']])->push(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'failed']])]);
    $this->artisan('commerce:reconcile', ['--limit' => 10])->assertSuccessful();
    expect($order->fresh()->state)->toBe('review');
    app(App\Services\Marketplace\StorePaymentNotifications::class)->reconcile($attempt);
    expect($order->fresh()->state)->toBe('cancelled')->and($order->fresh()->review_reason)->toBeNull();
    $next = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Illuminate\Support\Str::uuid());
    expect($next->id)->not->toBe($order->id);
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt));
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($order->fresh()->payment_state)->toBe('captured_review')->and($variant->fresh()->on_hand)->toBe(3);
});
