<?php

declare(strict_types=1);

use App\Models\StoreOutboxMessage;
use App\Models\StoreReceipt;
use App\Services\Marketplace\StoreReceipts;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\StoreCheckoutScenario;

beforeEach(function (): void {
    Queue::fake();
});

it('retains one immutable payment receipt and outbox despite repeated confirmation or product edits', function (): void {
    [, $variant, , , $order] = StoreCheckoutScenario::paid();
    $first = app(StoreReceipts::class)->capture($order);
    $variant->update(['price_pesewas' => 5]);
    $second = app(StoreReceipts::class)->capture($order->fresh());
    expect($first->id)->toBe($second->id)->and(StoreReceipt::query()->count())->toBe(1)
        ->and(StoreOutboxMessage::query()->count())->toBe(1)->and($second->snapshot['total_pesewas'])->toBe(10000);
    expect(fn () => StoreReceipt::query()->whereKey($first->id)->update(['snapshot' => ['total_pesewas' => 1]]))->toThrow(Illuminate\Database\QueryException::class);
});

it('creates the same zero-value receipt without a provider or monetary ledger', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $variant->update(['price_pesewas' => 0]);
    $order = app(App\Services\Marketplace\StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    Illuminate\Support\Facades\Http::fake();
    app(App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Str::uuid());
    expect(StoreReceipt::query()->where('order_id', $order->id)->sole()->snapshot['total_pesewas'])->toBe(0);
    expect(App\Models\LedgerTransaction::withoutGlobalScopes()->count())->toBe(0);
    Illuminate\Support\Facades\Http::assertNothingSent();
});

it('recovers delivery failures with a stable receipt identity and sweeps abandoned sending claims', function (): void {
    [, , , , $order] = StoreCheckoutScenario::paid();
    $receipt = app(StoreReceipts::class)->capture($order);
    $outbox = StoreOutboxMessage::query()->sole();
    $manager = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('temporary'));
    expect(fn () => app(StoreReceipts::class)->deliver($outbox->id))->toThrow(RuntimeException::class);
    expect($outbox->fresh()->state)->toBe('retryable');
    Mail::swap($manager);
    Mail::fake();
    $this->travel(5)->minutes();
    app(StoreReceipts::class)->deliver($outbox->id);
    app(StoreReceipts::class)->deliver($outbox->id);
    Mail::assertSent(App\Mail\StoreOrderReceipt::class, 1);
    expect($outbox->fresh()->state)->toBe('sent')->and(StoreReceipt::query()->sole()->id)->toBe($receipt->id);
    $outbox->update(['state' => 'sending', 'claimed_at' => now()->subMinutes(15), 'attempts' => 1]);
    app(StoreReceipts::class)->retryQueued();
    Queue::assertPushed(App\Jobs\DeliverStoreReceipt::class);
});

it('retains a queued outbox when queue dispatch fails and retries the same receipt later', function (): void {
    [, , , , $order] = StoreCheckoutScenario::paid();
    app(StoreReceipts::class)->capture($order);
    Illuminate\Support\Facades\Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
    app(StoreReceipts::class)->retryQueued();
    expect(StoreOutboxMessage::query()->sole()->state)->toBe('queued');
    Illuminate\Support\Facades\Bus::fake();
    app(StoreReceipts::class)->retryQueued();
    Illuminate\Support\Facades\Bus::assertDispatched(App\Jobs\DeliverStoreReceipt::class);
    expect(StoreReceipt::query()->count())->toBe(1);
});

it('renders the actual receipt email from its immutable snapshot without a model property collision', function (): void {
    [, , , , $order] = StoreCheckoutScenario::paid();
    $receipt = app(StoreReceipts::class)->capture($order);
    $mail = new App\Mail\StoreOrderReceipt($receipt);
    expect($mail->render())->toContain($receipt->snapshot['seller'])->toContain('100.00')->toContain('Read your order');
});

it('includes only buyer-borne fees in the rendered payment receipt', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $agreement = App\Models\StoreCommercialAgreement::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $agreement->replicate()->fill(['version' => 2, 'fee_bearer' => 'attendee'])->save();
    $order = app(App\Services\Marketplace\StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/initialize' => Illuminate\Support\Facades\Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/store-test']])]);
    $attempt = app(App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Str::uuid());
    $capture = app(App\Services\Marketplace\StorePaymentEvidence::class)->record($attempt, StoreCheckoutScenario::evidence($attempt, ['amount' => 10200, 'fees' => 199]));
    app(App\Services\Marketplace\StorePaymentConfirmer::class)->confirm($capture);
    $receipt = StoreReceipt::query()->where('order_id', $order->id)->sole();
    expect($receipt->snapshot['buyer_fee_pesewas'])->toBe(200)->and($receipt->snapshot)->not->toHaveKey('percentage');
    expect((new App\Mail\StoreOrderReceipt($receipt))->render())->toContain('Checkout fee: GHS 2.00')->toContain('102.00');
});

it('rejects an outbox message without its retained receipt at the database boundary', function (): void {
    expect(fn () => StoreOutboxMessage::query()->create(['receipt_id' => (string) Str::uuid(), 'destination' => 'buyer@example.test']))
        ->toThrow(Illuminate\Database\QueryException::class);
});
