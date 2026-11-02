<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreOrder;
use App\Models\StoreStockAllocation;
use App\Models\StoreStockMovement;
use App\Services\Marketplace\StoreSellerQuotes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

beforeEach(function (): void {
    [$this->seller, $this->variant, $this->actor, $this->buyerSession] = StoreCheckoutScenario::make(0);
    $this->variant->merchandise->update(['order_mode' => 'made_to_order', 'enquiry_phone' => '+233208333151']);
    Permission::query()->firstOrCreate(['name' => 'manage store orders', 'guard_name' => 'web'], ['category' => 'store']);
    $this->actor->givePermissionTo('manage store orders');
    $this->policy = App\Models\StoreFulfilmentPolicy::factory()->create(['shop_id' => $this->variant->shop_id, 'tenant_id' => $this->seller->id,
        'snapshot' => ['methods' => ['collection', 'delivery'], 'collection_location' => 'Purpledot office by appointment', 'delivery_countries' => ['GH'], 'delivery_instructions' => 'Seller-arranged delivery.']]);
    $this->quoteInput = ['operation_key' => (string) Str::uuid(), 'buyer_email' => 'buyer@example.test', 'valid_for_hours' => 48,
        'production_terms' => '7–10 working days after payment; subject to the agreed print specification.',
        'customisation' => 'Red association name printed on the front.', 'printing_pesewas' => 5000, 'delivery_pesewas' => 2500,
        'lines' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price_pesewas' => 20000]],
        'choice' => ['policy_id' => $this->policy->id, 'method' => 'delivery', 'destination' => ['recipient' => 'Test Buyer', 'address_line_1' => '12 Test Street', 'locality' => 'Accra', 'country' => 'GH']]];
});

it('issues an immutable owner-scoped offer using the common order without creating stock', function (): void {
    $quote = app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $this->quoteInput);
    $order = $quote->order;
    expect($order->principal_pesewas)->toBe(47500)->and($order->charged_pesewas)->toBe(47500)
        ->and($order->buyer_email)->toBe('buyer@example.test')->and($order->state)->toBe('quoted')
        ->and($order->expires_at->diffInSeconds(now()->addHours(48)))->toBeLessThan(5)
        ->and($order->fulfilmentArrangement->method)->toBe('delivery')
        ->and($order->lines()->firstOrFail()->line_amount_pesewas)->toBe(40000)
        ->and($quote->snapshot['printing_pesewas'])->toBe(5000)->and($quote->snapshot['delivery_pesewas'])->toBe(2500)
        ->and(StoreStockAllocation::query()->count())->toBe(0)->and(StoreStockMovement::query()->count())->toBe(0)
        ->and($this->variant->fresh()->on_hand)->toBe(0);
});

it('replays the same issue key and rejects changed terms for that key', function (): void {
    $service = app(StoreSellerQuotes::class);
    $quote = $service->issue($this->seller, $this->actor, $this->quoteInput);
    expect($service->issue($this->seller, $this->actor, $this->quoteInput)->id)->toBe($quote->id)
        ->and(StoreOrder::query()->count())->toBe(1);
    $changed = $this->quoteInput;
    $changed['printing_pesewas'] = 9000;
    expect(fn () => $service->issue($this->seller, $this->actor, $changed))->toThrow(ValidationException::class);
});

it('rejects unsafe or incomplete quote input', function (string $field, mixed $value): void {
    $input = $this->quoteInput;
    data_set($input, $field, $value);
    expect(fn () => app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $input))->toThrow(ValidationException::class);
    expect(StoreOrder::query()->count())->toBe(0);
})->with([
    ['buyer_email', 'not-an-address'], ['production_terms', ''], ['printing_pesewas', -1], ['printing_pesewas', 1.2],
    ['delivery_pesewas', -1], ['valid_for_hours', 0], ['valid_for_hours', 721], ['lines.0.quantity', 0], ['lines.0.quantity', 10001],
    ['lines.0.unit_price_pesewas', -1], ['choice.destination.address_line_1', ''],
]);

it('keeps stock-only items on their existing checkout path', function (): void {
    $this->variant->merchandise->update(['order_mode' => 'stock']);
    expect(fn () => app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $this->quoteInput))->toThrow(ValidationException::class);
});

it('requires the actual seller and order-management permission', function (): void {
    $this->actor->revokePermissionTo('manage store orders');
    expect(fn () => app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $this->quoteInput))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $other = App\Models\User::factory()->create();
    expect(fn () => app(StoreSellerQuotes::class)->issue($this->seller, $other, $this->quoteInput))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});

it('keeps catalogue and payment activation gates in place', function (string $feature): void {
    $this->seller->features()->where('feature_key', $feature)->update(['enabled' => false]);
    expect(fn () => app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $this->quoteInput))->toThrow(ValidationException::class);
    expect(StoreOrder::query()->count())->toBe(0);
})->with(['commerce_catalog', 'commerce_checkout']);

it('rejects another seller variant and foreign fulfilment policy', function (string $field): void {
    $input = $this->quoteInput;
    data_set($input, $field, (string) Str::uuid());
    expect(fn () => app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $input))->toThrow(ValidationException::class);
})->with(['lines.0.variant_id', 'choice.policy_id']);

it('does not invent warehouse stock for a large production quantity', function (): void {
    $input = $this->quoteInput;
    $input['lines'][0]['quantity'] = 1000;
    $quote = app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $input);
    expect($quote->order->lines()->firstOrFail()->quantity)->toBe(1000)
        ->and($quote->order->principal_pesewas)->toBe(20007500)
        ->and(StoreStockAllocation::query()->count())->toBe(0)->and($this->variant->fresh()->on_hand)->toBe(0);
});

it('preserves issued quote snapshots at the database boundary', function (): void {
    $quote = app(StoreSellerQuotes::class)->issue($this->seller, $this->actor, $this->quoteInput);
    expect(fn () => $quote->getConnection()->transaction(fn () => $quote->update(['snapshot' => ['printing_pesewas' => 0]])))->toThrow(Illuminate\Database\QueryException::class);
    expect($quote->fresh()->snapshot['printing_pesewas'])->toBe(5000);
});

it('withdraws an unpaid offer once without altering its agreed terms', function (): void {
    $service = app(StoreSellerQuotes::class);
    $quote = $service->issue($this->seller, $this->actor, $this->quoteInput);
    $before = $quote->snapshot;
    $withdrawn = $service->withdraw($quote, $this->actor, 'Buyer requested a revised delivery address.');
    expect($withdrawn->withdrawn_by)->toBe($this->actor->id)->and($withdrawn->withdrawn_at)->not->toBeNull()
        ->and($withdrawn->snapshot)->toBe($before)->and($withdrawn->order->state)->toBe('cancelled');
    expect($service->withdraw($quote, $this->actor, 'Repeated request.')->withdrawn_at->equalTo($withdrawn->withdrawn_at))->toBeTrue();
});
