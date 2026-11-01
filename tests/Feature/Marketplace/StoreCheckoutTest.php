<?php

declare(strict_types=1);

use App\Models\StorePaymentAttempt;
use App\Services\Marketplace\StorePayments;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_STORE', 'email' => 'buyer@example.test']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/store-test']]),
    ]);
});

it('starts one server-priced attempt and returns the original attempt on retry', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $key = (string) Str::uuid();
    $attempt = app(StorePayments::class)->start($order, $session, $key);
    $retry = app(StorePayments::class)->start($order, $session, $key);
    expect($attempt->id)->toBe($retry->id)->and($attempt->status)->toBe('ready')
        ->and(StorePaymentAttempt::query()->count())->toBe(1)->and($order->fresh()->accepted_at)->not->toBeNull();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transaction/initialize') && $r['amount'] === 10000 && $r['reference'] === $attempt->reference && $r['metadata']['store_order_id'] === $order->id);
    expect($attempt->toArray())->not->toHaveKey('checkout_url');
});

function fakePaystackInitialization(mixed $initialization): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*api.paystack.co/customer*' => Http::response(['status' => true, 'data' => ['customer_code' => 'CUS_STORE', 'email' => 'buyer@example.test']]),
        '*api.paystack.co/transaction/initialize' => $initialization,
    ]);
}

it('lets the buyer retry after Paystack definitively refuses to initialise the checkout', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    fakePaystackInitialization(Http::sequence()
        ->push(['status' => false, 'message' => 'Invalid Email Address Passed'], 400)
        ->push(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/store-test']]));

    $refused = app(StorePayments::class)->start($order, $session, (string) Str::uuid());
    $retry = app(StorePayments::class)->start($order, $session, (string) Str::uuid());

    expect($refused->status)->toBe('failed')->and($refused->review_reason)->toBe('initialization_rejected')
        ->and($retry->id)->not->toBe($refused->id)->and($retry->reference)->not->toBe($refused->reference)->and($retry->status)->toBe('ready')
        ->and(StorePaymentAttempt::query()->count())->toBe(2);
});

it('keeps initialisation unresolved when the refusal does not prove nothing was created', function (int $status, mixed $body): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    fakePaystackInitialization(Http::response($body, $status));

    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid());
    $again = app(StorePayments::class)->start($order, $session, (string) Str::uuid());

    expect($attempt->status)->toBe('unknown')->and($again->id)->toBe($attempt->id)->and(StorePaymentAttempt::query()->count())->toBe(1);
})->with([
    'duplicate reference' => [400, ['status' => false, 'message' => 'Duplicate Transaction Reference']],
    'reference mentioned' => [400, ['status' => false, 'message' => 'Invalid reference supplied']],
    'a body that is not Paystack\'s' => [400, '<html>Bad gateway</html>'],
    'a refusal without a message' => [400, ['status' => false]],
    'a server error' => [500, ['status' => false, 'message' => 'Internal error']],
    'a rate limit' => [429, ['status' => false, 'message' => 'Too many requests']],
    'a success with no checkout page' => [200, ['status' => true, 'data' => []]],
    'a checkout page on another host' => [200, ['status' => true, 'data' => ['authorization_url' => 'https://evil.example/pay']]],
]);

it('retains unknown initialization without issuing a second charge after timeout', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    Http::fake(['*api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_STORE', 'email' => 'buyer@example.test']]),
        '*api.paystack.co/transaction/initialize' => fn () => throw new Illuminate\Http\Client\ConnectionException('Unknown provider response')]);
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid());
    $retry = app(StorePayments::class)->start($order, $session, (string) Str::uuid());
    expect($attempt->status)->toBe('unknown')->and($retry->id)->toBe($attempt->id)
        ->and(StorePaymentAttempt::query()->count())->toBe(1)->and($variant->fresh()->on_hand)->toBe(3);
    $initializations = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/transaction/initialize'));
    expect($initializations->count())->toBeLessThanOrEqual(1);
});

it('accepts a free order without sending a gateway request and consumes it once', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    $variant->update(['price_pesewas' => 0]);
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $key = (string) Str::uuid();
    expect(app(StorePayments::class)->start($order, $session, $key))->toBeNull()
        ->and(app(StorePayments::class)->start($order, $session, $key))->toBeNull()
        ->and($order->fresh()->state)->toBe('confirmed')->and($variant->fresh()->on_hand)->toBe(0)
        ->and(StorePaymentAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses an expired quote inactive rollout or another buyer without contacting a provider', function (string $change): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    if ($change === 'expired') {
        $this->travel(10)->minutes();
    } elseif ($change === 'disabled') {
        $tenant->features()->where('feature_key', 'commerce_checkout')->update(['enabled' => false]);
    } else {
        $session = StoreCheckoutScenario::session('other@example.test');
    }
    expect(fn () => app(StorePayments::class)->start($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(StorePaymentAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['expired', 'disabled', 'other_buyer']);

it('rejects caller-supplied prices identities and references at both HTTP boundaries', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    foreach (['amount' => 1, 'fee' => 0, 'buyer_email' => 'other@example.test', 'tenant_id' => $tenant->id, 'reference' => 'fake'] as $key => $value) {
        $this->withSession(proofFor('buyer@example.test'))->postJson(route('store.quotes.create', $variant->shop->slug), [
            'lines' => [['variant_id' => $variant->id, 'quantity' => 1]], 'submission_key' => (string) Str::uuid(), $key => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->withSession(proofFor('buyer@example.test'))->postJson(route('store.checkout.start', $order), ['submission_key' => (string) Str::uuid(), $key => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($key);
    }
    $this->withSession(proofFor('other@example.test'))->postJson(route('store.checkout.start', $order), ['submission_key' => (string) Str::uuid()])->assertNotFound();
    Http::assertNothingSent();
});

it('uses the same authoritative verification for callback and signed webhook even after rollout is disabled', function (): void {
    [$tenant, $variant, , , $order, $attempt] = StoreCheckoutScenario::online();
    $data = ['id' => 987, 'reference' => $attempt->reference, 'status' => 'success', 'amount' => 10000, 'fees' => 195,
        'currency' => 'GHS', 'paid_at' => now()->toIso8601String(), 'metadata' => json_encode(['source' => 'miconvener_store', 'store_order_id' => $order->id, 'store_attempt_id' => $attempt->id, 'seller_id' => $tenant->id])];
    Http::fake(['*api.paystack.co/transaction/verify/*' => Http::response(['data' => $data])]);
    $tenant->features()->where('feature_key', 'commerce_checkout')->update(['enabled' => false]);
    $this->get(route('store.checkout.callback', $attempt->reference))->assertRedirect();
    $payload = json_encode(['event' => 'charge.success', 'data' => $data], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha512', $payload, 'sk_test_store_only');
    $this->call('POST', route('webhooks.paystack'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $payload)->assertOk();
    expect($variant->fresh()->on_hand)->toBe(2)->and($order->fresh()->payment_state)->toBe('paid');
});

it('refuses a new payment after platform product takedown without revisiting quoted member eligibility', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $order = StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    App\Models\StoreListing::withoutGlobalScope(App\Scopes\MerchandiseVisibilityScope::class)->whereKey($variant->listing_id)->update(['taken_down_at' => now()]);
    expect(fn () => app(StorePayments::class)->start($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('completes the native quote checkout and protected receipt HTTP contract without dues', function (int $price): void {
    Illuminate\Support\Facades\Queue::fake();
    [$tenant, $variant] = StoreCheckoutScenario::make(1);
    $variant->update(['price_pesewas' => $price]);
    $quote = $this->withSession(proofFor('buyer@example.test'))->postJson(route('store.quotes.create', $variant->shop->slug), [
        'submission_key' => (string) Str::uuid(), 'lines' => [['variant_id' => $variant->id, 'quantity' => 1]],
    ])->assertCreated()->assertJsonPath('order.total_pesewas', $price)->json('order_id');
    $policy = App\Models\StoreFulfilmentPolicy::factory()->create(['shop_id' => $variant->shop_id, 'tenant_id' => $tenant->id]);
    $this->postJson('/my/store-orders/'.$quote.'/fulfilment', ['choice' => ['policy_id' => $policy->id, 'method' => 'collection'], 'operation_key' => (string) Str::uuid()])->assertOk();
    $this->postJson('/my/store-orders/'.$quote.'/checkout', ['submission_key' => (string) Str::uuid()])->assertOk();
    if ($price > 0) {
        $attempt = StorePaymentAttempt::query()->where('order_id', $quote)->sole();
        $data = ['id' => 987, ...StoreCheckoutScenario::evidence($attempt)];
        Http::fake(['*api.paystack.co/transaction/verify/*' => Http::response(['data' => $data])]);
        $this->get(route('store.checkout.callback', $attempt->reference))->assertRedirect();
    } else {
        Http::assertNothingSent();
    }
    $this->getJson('/my/store-orders/'.$quote)->assertOk()->assertJsonPath('order.state', 'confirmed')->assertJsonPath('order.total_pesewas', $price);
    $this->getJson('/my/store-orders/'.$quote.'/receipt')->assertOk()->assertJsonPath('receipt.total_pesewas', $price);
    expect($variant->fresh()->on_hand)->toBe(0)->and(App\Models\StoreReceipt::query()->count())->toBe(1);
})->with([0, 10000]);

it('refuses direct checkout of made-to-order items even if stock is present', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    App\Models\StoreMerchandise::query()->where('listing_id', $variant->listing_id)->firstOrFail()->forceFill(['order_mode' => 'made_to_order', 'enquiry_phone' => '+233208333151'])->save();
    expect(fn () => StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(App\Models\StoreOrder::query()->count())->toBe(0)
        ->and(StorePaymentAttempt::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('honours an existing stocked quote and buyer history after switching to enquiry ordering', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make();
    $key = (string) Str::uuid();
    $lines = [['variant_id' => $variant->id, 'quantity' => 1]];
    $order = StoreCheckoutScenario::quote($tenant, $session, $lines, $key);
    App\Models\StoreMerchandise::query()->where('listing_id', $variant->listing_id)->firstOrFail()->update(['order_mode' => 'made_to_order', 'enquiry_phone' => '+233208333151']);
    $replay = app(App\Services\Marketplace\StoreQuotes::class)->create($tenant, $session, $lines, $key);
    expect($replay->id)->toBe($order->id);
    $attempt = app(StorePayments::class)->start($order, $session, (string) Str::uuid());
    expect($attempt->status)->toBe('ready')
        ->and(App\Models\StoreOrder::query()->count())->toBe(1)
        ->and(app(App\Services\Marketplace\StoreOrderAccess::class)->buyer($order, $session))->toBeTrue();
});
