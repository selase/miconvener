<?php

declare(strict_types=1);

use App\Models\LedgerTransaction;
use App\Models\StorePaymentAttempt;
use App\Models\StoreStockAllocation;
use App\Models\StoreStockMovement;
use App\Services\Marketplace\StoreFulfilment;
use App\Services\Marketplace\StorePaymentConfirmer;
use App\Services\Marketplace\StorePaymentEvidence;
use App\Services\Marketplace\StorePayments;
use App\Services\Marketplace\StoreSellerQuotes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_SELLERQUOTE']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/seller-quote-test']])]);
});

it('starts a quoted production payment only after accepting the exact immutable terms', function (): void {
    [, $variant, , $session, $order] = StoreCheckoutScenario::sellerQuote();
    foreach ([null, str_repeat('0', 64)] as $hash) {
        expect(fn () => app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $hash))->toThrow(ValidationException::class);
    }
    Http::assertNothingSent();
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash);
    expect($attempt->status)->toBe('ready')->and($attempt->amount_pesewas)->toBe(47500)
        ->and(StoreStockAllocation::query()->count())->toBe(0)->and(StoreStockMovement::query()->count())->toBe(0)
        ->and($variant->fresh()->on_hand)->toBe(0);
});

it('keeps the payment link bound to the original verified email', function (): void {
    [, , , , $order] = StoreCheckoutScenario::sellerQuote();
    expect(fn () => app(StorePayments::class)->start($order, StoreCheckoutScenario::session('other@example.test'), (string) Str::uuid(), $order->terms_hash))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('posts one sale and receipt and enables production without manufacturing inventory', function (): void {
    [, $variant, $actor, $session, $order] = StoreCheckoutScenario::sellerQuote();
    $key = (string) Str::uuid();
    $attempt = app(StorePayments::class)->start($order, $session, $key, $order->terms_hash);
    expect(app(StorePayments::class)->start($order, $session, $key, $order->terms_hash)->id)->toBe($attempt->id);
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, ['amount' => 47500, 'fees' => 926]));
    app(StorePaymentConfirmer::class)->confirm($capture);
    app(StorePaymentConfirmer::class)->confirm($capture->fresh());
    $order->refresh();
    expect($order->payment_state)->toBe('paid')->and($order->ledger_state)->toBe('posted')
        ->and($order->receipt()->count())->toBe(1)->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count())->toBe(1)
        ->and(StoreStockMovement::query()->count())->toBe(0)->and($variant->fresh()->on_hand)->toBe(0)
        ->and(app(StoreFulfilment::class)->eligible($order))->toBeTrue();
    $receipt = $order->receipt;
    expect($receipt->snapshot['charges'][0]['amount_pesewas'])->toBe(5000)
        ->and($receipt->snapshot['charges'][1]['amount_pesewas'])->toBe(2500)
        ->and($receipt->snapshot['total_pesewas'])->toBe(47500);
    $email = (new App\Mail\StoreOrderReceipt($receipt))->render();
    expect($email)->toContain('Custom printing', 'GHS 50.00', 'GHS 25.00', 'Seven working days after payment.');

    app(StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid());
    expect($order->fresh()->fulfilment_state)->toBe('ready');
});

it('blocks withdrawn expired and payment-disabled quotes before contacting the gateway', function (string $change): void {
    [$tenant, , $actor, $session, $order, $quote] = StoreCheckoutScenario::sellerQuote();
    if ($change === 'withdrawn') {
        app(StoreSellerQuotes::class)->withdraw($quote, $actor, 'Replaced quote.');
    } elseif ($change === 'expired') {
        $this->travel(49)->hours();
    } else {
        $tenant->features()->where('feature_key', 'commerce_checkout')->update(['enabled' => false]);
    }
    expect(fn () => app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash))->toThrow(ValidationException::class);
    expect(StorePaymentAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['withdrawn', 'expired', 'disabled']);

it('will not withdraw an issued quote while payment is unresolved', function (): void {
    [, , $actor, $session, $order, $quote] = StoreCheckoutScenario::sellerQuote();
    app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash);
    expect(fn () => app(StoreSellerQuotes::class)->withdraw($quote, $actor, 'Change the price.'))->toThrow(ValidationException::class);
});

it('retains late captured money for review without starting production', function (): void {
    [, $variant, , $session, $order] = StoreCheckoutScenario::sellerQuote();
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash);
    $this->travel(49)->hours();
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, ['amount' => 47500, 'fees' => 926]));
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($order->fresh()->payment_state)->toBe('captured_review')->and($order->fresh()->ledger_state)->toBe('review')
        ->and(app(StoreFulfilment::class)->eligible($order->fresh()))->toBeFalse()->and($variant->fresh()->on_hand)->toBe(0);
});

it('does not treat fabricated paid flags as verified production funding', function (): void {
    [, , , , $order] = StoreCheckoutScenario::sellerQuote();
    $order->update(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted', 'accepted_at' => now()]);
    expect(app(StoreFulfilment::class)->eligible($order->fresh()))->toBeFalse();
});

it('keeps failed capture identity or totals out of production', function (array $change): void {
    [, $variant, , $session, $order] = StoreCheckoutScenario::sellerQuote();
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash);
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, array_replace(['amount' => 47500, 'fees' => 926], $change)));
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($order->fresh()->payment_state)->toBe('captured_review')
        ->and(app(StoreFulfilment::class)->eligible($order->fresh()))->toBeFalse()->and($variant->fresh()->on_hand)->toBe(0);
})->with([[['amount' => 1]], [['currency' => 'USD']], [['reference' => 'not-the-issued-reference']], [['account_fingerprint' => 'another-account']]]);

it('does not permit repricing delivery through the normal buyer fulfilment route', function (): void {
    [, , , $session, $order] = StoreCheckoutScenario::sellerQuote();
    $arrangement = $order->fulfilmentArrangement;
    expect(fn () => app(App\Services\Marketplace\StoreFulfilmentOptions::class)->choose($order, $session,
        ['policy_id' => $arrangement->policy_id, 'method' => 'collection'], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect($order->fresh()->fulfilmentArrangement->method)->toBe('delivery');
});

it('fully refunds a paid production order without adding fictitious warehouse stock', function (): void {
    [$tenant, $variant, $actor, $session, $order] = StoreCheckoutScenario::sellerQuote();
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash);
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, ['amount' => 47500, 'fees' => 926]));
    app(StorePaymentConfirmer::class)->confirm($capture);
    $arrangement = $order->fulfilmentArrangement;
    $buffer = app(StoreSellerQuotes::class)->issue($tenant, $actor, ['operation_key' => (string) Str::uuid(), 'buyer_email' => 'buffer@example.test', 'valid_for_hours' => 48,
        'production_terms' => 'Seven working days.', 'customisation' => 'Same print.', 'printing_pesewas' => 5000, 'delivery_pesewas' => 2500,
        'lines' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_price_pesewas' => 20000]],
        'choice' => ['policy_id' => $arrangement->policy_id, 'method' => 'delivery', 'destination' => $arrangement->destination]]);
    $bufferAttempt = app(StorePayments::class)->start($buffer->order, StoreCheckoutScenario::session('buffer@example.test'), (string) Str::uuid(), $buffer->order->terms_hash);
    $bufferCapture = app(StorePaymentEvidence::class)->record($bufferAttempt, StoreCheckoutScenario::evidence($bufferAttempt, ['amount' => 47500, 'fees' => 926]));
    app(StorePaymentConfirmer::class)->confirm($bufferCapture);
    $key = (string) Str::uuid();
    Http::fake(['*api.paystack.co/refund' => Http::response(['data' => ['id' => 123, 'status' => 'pending']])]);
    $refund = app(App\Services\Marketplace\StoreRefunds::class)->request($order->fresh(), $actor, $key, 'Production cancelled before preparation.');
    Http::fake([
        '*api.paystack.co/refund/123' => Http::response(['data' => ['id' => 123, 'status' => 'processed', 'amount' => 47500, 'currency' => 'GHS', 'merchant_note' => 'store:'.$key, 'transaction' => ['id' => 987]]]),
        '*api.paystack.co/transaction/verify/*' => Http::response(['data' => ['id' => 987, 'reference' => $attempt->reference, 'status' => 'reversed', 'amount' => 47500, 'fees' => 926, 'currency' => 'GHS']]),
    ]);
    app(App\Services\Marketplace\StoreRefunds::class)->reconcile($refund);
    app(App\Services\Marketplace\StoreRefunds::class)->reconcile($refund);
    expect($refund->fresh()->status)->toBe('confirmed')->and($order->fresh()->payment_state)->toBe('refunded')
        ->and($order->fresh()->fulfilment_state)->toBe('cancelled')->and($variant->fresh()->on_hand)->toBe(0)
        ->and(StoreStockMovement::query()->count())->toBe(0);
});

it('does not cancel a seller offer when the buyer starts ordinary stocked checkout', function (): void {
    [$tenant, , $actor, $session, $offered] = StoreCheckoutScenario::sellerQuote();
    $listing = App\Models\StoreListing::factory()->published()->create(['shop_id' => $tenant->shop->id, 'listing_kind' => 'merchandise']);
    App\Models\StoreMerchandise::factory()->create(['listing_id' => $listing->id, 'shop_id' => $tenant->shop->id, 'order_mode' => 'stock']);
    $stocked = App\Models\StoreVariant::factory()->create(['listing_id' => $listing->id, 'shop_id' => $tenant->shop->id]);
    app(App\Services\Marketplace\MerchandiseInventory::class)->adjust($stocked, 3, 'Opening stock', (string) Str::uuid(), $actor);
    app(App\Services\Marketplace\StoreQuotes::class)->create($tenant, $session, [['variant_id' => $stocked->id, 'quantity' => 1]], (string) Str::uuid());
    expect($offered->fresh()->state)->toBe('quoted')
        ->and(StoreStockAllocation::query()->where('order_id', $offered->id)->count())->toBe(0);
});

it('never substitutes a warehouse reservation for a production commitment', function (): void {
    [, $variant, $actor, , $order] = StoreCheckoutScenario::sellerQuote();
    app(App\Services\Marketplace\MerchandiseInventory::class)->adjust($variant, 3, 'Unrelated existing stock', (string) Str::uuid(), $actor);
    expect(fn () => app(App\Services\Marketplace\PurchaseReservations::class)->reserve($order))->toThrow(ValidationException::class);
    expect(StoreStockAllocation::query()->count())->toBe(0);
});

it('preserves paid production orders through quote expiry and the existing whole-order handover', function (bool $delivery): void {
    [, $variant, $actor, $session, $order] = StoreCheckoutScenario::sellerQuote($delivery);
    $amount = $delivery ? 47500 : 45000;
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid(), $order->terms_hash);
    $capture = app(StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, ['amount' => $amount, 'fees' => 900]));
    app(StorePaymentConfirmer::class)->confirm($capture);
    $this->travel(10)->days();
    $session = StoreCheckoutScenario::session();
    app(StoreFulfilment::class)->ready($order->fresh(), $actor, (string) Str::uuid());
    if ($delivery) {
        app(App\Services\Marketplace\StoreDeliveries::class)->dispatch($order->fresh(), $actor, (string) Str::uuid());
    }
    $pass = app(App\Services\Marketplace\StoreCollectionPasses::class)->issue($order->fresh(), $session, (string) Str::uuid());
    $key = (string) Str::uuid();
    $handover = app(App\Services\Marketplace\StoreCollections::class)->confirm($order->fresh(), $actor, ['manual_code' => $pass['manual_code']], $key);
    expect($order->fresh()->fulfilment_state)->toBe($delivery ? 'delivered' : 'collected')
        ->and(app(App\Services\Marketplace\StoreCollections::class)->confirm($order->fresh(), $actor, ['manual_code' => $pass['manual_code']], $key)->id)->toBe($handover->id)
        ->and($variant->fresh()->on_hand)->toBe(0)->and(StoreStockMovement::query()->count())->toBe(0)
        ->and(LedgerTransaction::withoutGlobalScopes()->where('transaction_type', 'commerce_sale')->count())->toBe(1);
})->with([true, false]);
