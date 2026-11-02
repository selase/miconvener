<?php

declare(strict_types=1);

use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentPolicy;
use App\Services\Marketplace\StoreFulfilmentOptions;
use App\Services\Marketplace\StoreQuotes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

function fulfilmentOffer(array $changes = []): array
{
    return array_replace(['methods' => ['collection', 'delivery'], 'collection_location' => 'Association office', 'collection_hours' => 'Weekdays 9–5', 'collection_instructions' => 'Ask at reception', 'delivery_countries' => ['GH'], 'delivery_instructions' => 'Delivery included in the displayed price', 'delivery_timing' => 'Within three working days'], $changes);
}

function deliveryChoice(string $policyId): array
{
    return ['policy_id' => $policyId, 'method' => 'delivery', 'destination' => ['recipient' => 'Test Buyer', 'address_line_1' => '12 Test Street', 'locality' => 'Accra', 'country' => 'GH']];
}

it('lets a verified no-dues buyer accept seller delivery without increasing the quote price', function () {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make();
    $service = app(StoreFulfilmentOptions::class);
    $policy = $service->offer($variant->shop, $actor, fulfilmentOffer(), (string) Str::uuid());
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $choice = $service->choose($order, $session, deliveryChoice($policy->id), (string) Str::uuid());
    expect($choice->method)->toBe('delivery')->and($choice->destination['locality'])->toBe('Accra')
        ->and($order->fresh()->charged_pesewas)->toBe(10000)->and($order->fresh()->member_id)->toBeNull();
    expect($choice->getRawOriginal('destination'))->not->toContain('Test Street');
    expect($choice->toArray())->not->toHaveKey('destination');
});

it('retains accepted policy terms after the seller changes the offer and replays the same choice', function () {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make();
    $service = app(StoreFulfilmentOptions::class);
    $policy = $service->offer($variant->shop, $actor, fulfilmentOffer(), (string) Str::uuid());
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $key = (string) Str::uuid();
    $choice = ['policy_id' => $policy->id, 'method' => 'collection'];
    $arrangement = $service->choose($order, $session, $choice, $key);
    $service->offer($variant->shop, $actor, fulfilmentOffer(['methods' => ['delivery']]), (string) Str::uuid());
    expect($service->choose($order, $session, $choice, $key)->id)->toBe($arrangement->id)
        ->and($arrangement->fresh()->policy_snapshot['collection_location'])->toBe('Association office');
});

it('denies foreign buyers and delivery outside the seller coverage', function () {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make();
    $service = app(StoreFulfilmentOptions::class);
    $policy = $service->offer($variant->shop, $actor, fulfilmentOffer(), (string) Str::uuid());
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    expect(fn () => $service->choose($order, StoreCheckoutScenario::session('other@example.test'), deliveryChoice($policy->id), (string) Str::uuid()))->toThrow(AuthorizationException::class);
    $choice = deliveryChoice($policy->id);
    $choice['destination']['country'] = 'US';
    expect(fn () => $service->choose($order, $session, $choice, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(StoreFulfilmentArrangement::query()->count())->toBe(0);
});

it('does not let catalog staff of another seller change fulfilment offers or charge delivery separately', function () {
    [$tenant, $variant, $actor] = StoreCheckoutScenario::make();
    [, $foreign] = StoreCheckoutScenario::make();
    $service = app(StoreFulfilmentOptions::class);
    expect(fn () => $service->offer($foreign->shop, $actor, fulfilmentOffer(), (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(fn () => $service->offer($variant->shop, $actor, fulfilmentOffer(['delivery_fee_pesewas' => 1000]), (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(StoreFulfilmentPolicy::query()->count())->toBe(0);
});

it('replaces a quoted arrangement without extending its stock hold or keeping duplicate reservations', function () {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make(1);
    $service = app(StoreFulfilmentOptions::class);
    $policy = $service->offer($variant->shop, $actor, fulfilmentOffer(), (string) Str::uuid());
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $service->choose($order, $session, ['policy_id' => $policy->id, 'method' => 'collection'], (string) Str::uuid());
    $this->travel(2)->minutes();
    $key = (string) Str::uuid();
    $replacement = $service->choose($order, $session, deliveryChoice($policy->id), $key);
    expect($replacement->order_id)->not->toBe($order->id)
        ->and($order->fresh()->state)->toBe('cancelled')
        ->and(App\Models\StoreOrder::query()->findOrFail($replacement->order_id)->expires_at->equalTo($order->expires_at))->toBeTrue()
        ->and(App\Models\StoreStockAllocation::query()->where('state', 'active')->sum('quantity'))->toBe(1)
        ->and($service->choose($order, $session, deliveryChoice($policy->id), $key)->id)->toBe($replacement->id);
});

it('requires buyer choice before a new payment and keeps paid historical orders readable for first choice', function () {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make();
    $policy = app(StoreFulfilmentOptions::class)->offer($variant->shop, $actor, fulfilmentOffer(), (string) Str::uuid());
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    expect(fn () => app(App\Services\Marketplace\StorePayments::class)->start($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect($order->fresh()->state)->toBe('quoted');
});

it('requires a collection location and delivery coverage only for the offered methods', function () {
    [$tenant, $variant, $actor] = StoreCheckoutScenario::make();
    $service = app(StoreFulfilmentOptions::class);
    expect(fn () => $service->offer($variant->shop, $actor, ['methods' => ['collection']], (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => $service->offer($variant->shop, $actor, ['methods' => ['delivery']], (string) Str::uuid()))->toThrow(ValidationException::class);
    $policy = $service->offer($variant->shop, $actor, ['methods' => ['delivery'], 'delivery_countries' => ['GH']], (string) Str::uuid());
    expect($policy->snapshot['methods'])->toBe(['delivery']);
});

it('publishes delivery-only merchandise without inventing collection instructions', function () {
    [$tenant, $variant, $actor] = StoreCheckoutScenario::make();
    app(StoreFulfilmentOptions::class)->offer($variant->shop, $actor, ['methods' => ['delivery'], 'delivery_countries' => ['GH']], (string) Str::uuid());
    $variant->merchandise->update(['collection_instructions' => null]);
    $listing = $variant->merchandise->listing()->withoutGlobalScopes()->firstOrFail();
    $listing->update(['status' => 'draft']);
    expect(app(App\Services\Marketplace\MerchandiseCatalog::class)->publish($tenant, $actor, $listing)->status)->toBe('published');
});

it('exposes seller offers and buyer choice through protected HTTP without leaking the delivery address', function () {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make();
    $tenant->users()->syncWithoutDetaching([$actor->id]);
    $this->withSession(['active_tenant_id' => $tenant->id])->actingAs($actor)->postJson('http://'.$tenant->slug.'.miconvener.test/store/fulfilment-policies', [
        'policy' => fulfilmentOffer(), 'operation_key' => (string) Str::uuid(),
    ], ['HTTP_HOST' => $tenant->slug.'.miconvener.test'])->assertSuccessful();
    $policy = StoreFulfilmentPolicy::query()->firstOrFail();
    $order = app(StoreQuotes::class)->create($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $this->withSession(proofFor('buyer@example.test'))->postJson('http://miconvener.test/my/store-orders/'.$order->id.'/fulfilment', [
        'choice' => deliveryChoice($policy->id), 'operation_key' => (string) Str::uuid(),
    ], ['HTTP_HOST' => 'miconvener.test'])->assertSuccessful()->assertJsonPath('method', 'delivery')->assertHeader('Cache-Control', 'no-store, private');
    $this->withSession(proofFor('other@example.test'))->postJson('http://miconvener.test/my/store-orders/'.$order->id.'/fulfilment', [
        'choice' => deliveryChoice($policy->id), 'operation_key' => (string) Str::uuid(),
    ], ['HTTP_HOST' => 'miconvener.test'])->assertNotFound();
});
