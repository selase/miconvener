<?php

declare(strict_types=1);

use App\Models\BadgeTemplateLicenceEvent;
use App\Models\BadgeTemplatePurchase;
use App\Models\StorePaymentAttempt;
use App\Models\StorePaymentObservation;
use App\Models\StoreReceipt;
use App\Services\Marketplace\StorePaymentConfirmer;

beforeEach(function (): void {
    refreshTenantDatabases();
    Illuminate\Support\Facades\Queue::fake();
    config(['services.settlement.paystack.secret_key' => 'sk_test_badge_payments']);
    $fingerprint = hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity());
    $agreement = App\Models\StoreCommercialAgreement::factory()->state(['offering_scope' => 'badge_template', 'percentage' => '0.00', 'refund_gateway_fee_bearer' => 'platform', 'account_fingerprint' => $fingerprint]);
    $this->purchase = BadgeTemplatePurchase::factory()->create(['order_id' => App\Models\StoreOrder::factory()->badgeTemplate()->state(['agreement_id' => $agreement, 'account_fingerprint' => $fingerprint])]);
    $this->purchase->order->update(['accepted_at' => now()]);
    $this->attempt = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id]);
});

test('verified payment atomically issues one event licence and one durable receipt', function (): void {
    $this->capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id, 'gateway_fee_pesewas' => 100]);
    $service = app(StorePaymentConfirmer::class);
    $order = $service->confirm($this->capture);
    expect($order->payment_state)->toBe('paid')
        ->and($this->purchase->fresh()->state)->toBe('paid')
        ->and($this->purchase->licence->fresh()->state)->toBe('active')
        ->and($this->purchase->licence->fresh()->current_purchase_id)->toBe($this->purchase->id)
        ->and($this->purchase->licence->fresh()->first_used_at)->toBeNull();
    $service->confirm($this->capture->fresh());
    expect(StoreReceipt::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(BadgeTemplateLicenceEvent::query()->where('licence_id', $this->purchase->licence_id)->where('action', 'issued')->count())->toBe(1);
});

test('a capture held for review cannot activate the licence on callback replay', function (): void {
    $this->capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id, 'integrity_errors' => ['amount_mismatch']]);
    app(StorePaymentConfirmer::class)->confirm($this->capture);
    $this->capture->refresh();
    app(StorePaymentConfirmer::class)->confirm($this->capture);
    expect($this->purchase->licence->fresh()->state)->toBe('pending')
        ->and($this->capture->fresh()->processing_state)->toBe('review')
        ->and(StoreReceipt::query()->where('order_id', $this->purchase->order_id)->exists())->toBeFalse();
});

test('a failed ledger post cannot leave a paid licence or receipt behind', function (): void {
    $this->capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id, 'gateway_fee_pesewas' => 6000]);
    expect(fn () => app(StorePaymentConfirmer::class)->confirm($this->capture))->toThrow(InvalidArgumentException::class);
    expect($this->purchase->fresh()->state)->toBe('pending')
        ->and($this->purchase->licence->fresh()->state)->toBe('pending')
        ->and(StoreReceipt::query()->where('order_id', $this->purchase->order_id)->exists())->toBeFalse();
});

test('verified evidence mismatches never issue a digital licence', function (array $overrides): void {
    $verified = ['status' => 'success', 'reference' => $this->attempt->reference, 'reference_reported' => true,
        'provider' => 'paystack', 'account_fingerprint' => $this->attempt->account_fingerprint, 'amount' => 5000, 'fees' => 100,
        'currency' => 'GHS', 'paid_at' => now()->toIso8601String(),
        'metadata' => app(App\Services\Marketplace\BadgeTemplateOffering::class)->expectedMetadata($this->attempt)];
    $capture = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($this->attempt, array_replace($verified, $overrides));
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($this->purchase->fresh()->state)->not->toBe('paid')
        ->and($this->purchase->licence->fresh()->state)->not->toBe('active')
        ->and(StoreReceipt::query()->where('order_id', $this->purchase->order_id)->exists())->toBeFalse();
})->with([
    'amount' => [['amount' => 100]], 'currency' => [['currency' => 'USD']], 'reference' => [['reference' => 'another-reference']],
    'account' => [['account_fingerprint' => 'another-account']], 'metadata' => [['metadata' => ['source' => 'miconvener_store']]],
    'missing fees' => [['fees' => null]],
]);

test('an extra capture cannot create a second licence or receipt', function (): void {
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id]);
    app(StorePaymentConfirmer::class)->confirm($capture);
    $extra = StorePaymentAttempt::factory()->create(['order_id' => $this->purchase->order_id, 'status' => 'failed']);
    $extraCapture = StorePaymentObservation::factory()->create(['attempt_id' => $extra->id]);
    app(StorePaymentConfirmer::class)->confirm($extraCapture);
    expect($extraCapture->fresh()->processing_state)->toBe('review')
        ->and($this->purchase->licence->fresh()->current_purchase_id)->toBe($this->purchase->id)
        ->and(StoreReceipt::query()->where('order_id', $this->purchase->order_id)->count())->toBe(1);
});

test('signed webhook and callback delivery in either order issue one licence', function (bool $webhookFirst): void {
    $data = ['id' => 4242, 'status' => 'success', 'reference' => $this->attempt->reference, 'amount' => 5000, 'fees' => 100,
        'currency' => 'GHS', 'paid_at' => now()->toIso8601String(),
        'metadata' => json_encode(app(App\Services\Marketplace\BadgeTemplateOffering::class)->expectedMetadata($this->attempt))];
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['data' => $data])]);
    $payload = json_encode(['event' => 'charge.success', 'data' => $data], JSON_THROW_ON_ERROR);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'sk_test_badge_payments')];
    if (! $webhookFirst) {
        $this->get(route('store.checkout.callback', ['reference' => $this->attempt->reference]))->assertRedirect(route('badge-template-purchases.show', $this->purchase->id));
    }
    $this->call('POST', route('webhooks.paystack'), [], [], [], $headers, $payload)->assertOk();
    $this->get(route('store.checkout.callback', ['reference' => $this->attempt->reference]))->assertRedirect(route('badge-template-purchases.show', $this->purchase->id));
    expect($this->purchase->licence->fresh()->state)->toBe('active')
        ->and(StoreReceipt::query()->where('order_id', $this->purchase->order_id)->count())->toBe(1)
        ->and(App\Models\LedgerTransaction::withoutGlobalScopes()->where('reference', $this->attempt->reference)->where('transaction_type', 'badge_template_sale')->count())->toBe(1);
})->with([true, false]);

test('the exact payment deadline is held for review and grants no licence', function (): void {
    $this->travelTo($this->purchase->order->expires_at);
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id]);
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($capture->fresh()->processing_state)->toBe('review')->and($this->purchase->licence->fresh()->state)->toBe('pending');
});

test('ledger recovery retries the original capture without duplicate licence or receipt', function (): void {
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $this->attempt->id]);
    $db = $capture->getConnection();
    $db->statement("ALTER TABLE ledger_transactions ADD CONSTRAINT test_template_sale_failure CHECK (transaction_type <> 'badge_template_sale')");
    expect(fn () => app(StorePaymentConfirmer::class)->confirm($capture))->toThrow(Illuminate\Database\QueryException::class);
    expect($this->purchase->licence->fresh()->state)->toBe('pending');
    $db->statement('ALTER TABLE ledger_transactions DROP CONSTRAINT test_template_sale_failure');
    app(StorePaymentConfirmer::class)->confirm($capture);
    app(StorePaymentConfirmer::class)->confirm($capture->fresh());
    expect($this->purchase->licence->fresh()->state)->toBe('active')->and(StoreReceipt::query()->where('order_id', $this->purchase->order_id)->count())->toBe(1);
});

test('a historical refunded receipt never borrows the state or use of its replacement licence', function (): void {
    $this->purchase->update(['state' => 'refunded']);
    $replacement = BadgeTemplatePurchase::factory()->create(['licence_id' => $this->purchase->licence_id, 'version_id' => $this->purchase->version_id, 'state' => 'paid']);
    $replacement->licence->update(['state' => 'active', 'current_purchase_id' => $replacement->id, 'first_used_at' => now()]);
    App\Models\BadgeTemplateUse::factory()->create(['purchase_id' => $replacement->id]);
    $payload = app(App\Services\Badges\BadgeTemplatePurchasePayload::class);
    expect($payload->forPurchase($this->purchase)['licence_state'])->toBe('refunded')
        ->and($payload->forPurchase($this->purchase)['used'])->toBeFalse()
        ->and($payload->forPurchase($replacement)['licence_state'])->toBe('active')
        ->and($payload->forPurchase($replacement)['used'])->toBeTrue();
});

test('removing the original requesting account cannot strand an already captured licence payment', function (): void {
    $actor = App\Models\User::factory()->create();
    $fingerprint = hash('sha256', app(App\Services\Giving\GivingGateways::class)->platformIdentity());
    $purchase = BadgeTemplatePurchase::factory()->create(['order_id' => App\Models\StoreOrder::factory()->badgeTemplate()->state(['account_fingerprint' => $fingerprint]), 'terms_snapshot' => ['requested_by' => $actor->id, 'total_pesewas' => 5000, 'currency' => 'GHS']]);
    $purchase->order->update(['accepted_at' => now()]);
    $attempt = StorePaymentAttempt::factory()->create(['order_id' => $purchase->order_id]);
    $capture = StorePaymentObservation::factory()->create(['attempt_id' => $attempt->id]);
    $actor->delete();
    app(StorePaymentConfirmer::class)->confirm($capture);
    expect($purchase->licence->fresh()->state)->toBe('active')->and($capture->fresh()->processing_state)->toBe('posted');
});
