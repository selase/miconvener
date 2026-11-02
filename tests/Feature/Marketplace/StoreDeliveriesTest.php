<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreCommercialAgreement;
use App\Services\Marketplace\StoreCollectionPasses;
use App\Services\Marketplace\StoreCollections;
use App\Services\Marketplace\StoreDeliveries;
use App\Services\Marketplace\StoreFulfilment;
use App\Services\Marketplace\StoreRefunds;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

function deliveryReadyScenario(?int $holdback = null): array
{
    return StoreCheckoutScenario::deliveryReady($holdback);
}

it('keeps delivery ready until dispatch and requires recipient verification to mark delivered', function () {
    [, $variant,$actor,$session,$order] = deliveryReadyScenario();
    expect(fn () => app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    $this->travel(3)->days();
    $session = StoreCheckoutScenario::session();
    $key = (string) Str::uuid();
    $service = app(StoreDeliveries::class);
    $dispatch = $service->dispatch($order, $actor, $key);
    expect($service->dispatch($order, $actor, $key)->id)->toBe($dispatch->id)->and($order->fresh()->fulfilment_state)->toBe('dispatched');
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    app(StoreCollections::class)->confirm($order, $actor, ['manual_code' => $proof['manual_code']], (string) Str::uuid());
    expect($order->fresh()->fulfilment_state)->toBe('delivered')->and($variant->fresh()->on_hand)->toBe(2)->and($order->fresh()->charged_pesewas)->toBe(10000);
});

it('requires actual return to the seller before pre-handover refund and does not restock on a failed attempt', function () {
    [, $variant,$actor,$session,$order] = deliveryReadyScenario();
    Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $service = app(StoreDeliveries::class);
    $service->dispatch($order, $actor, (string) Str::uuid());
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $service->attemptFailed($order, $actor, (string) Str::uuid(), 'Recipient unavailable');
    expect($order->fresh()->fulfilment_state)->toBe('dispatched')->and($variant->fresh()->on_hand)->toBe(2);
    expect(fn () => app(StoreRefunds::class)->request($order, $actor, (string) Str::uuid(), 'Still in transit'))->toThrow(ValidationException::class);
    $service->returned($order, $actor, (string) Str::uuid(), 'All goods physically returned');
    expect($order->fresh()->fulfilment_state)->toBe('ready')->and($variant->fresh()->on_hand)->toBe(2);
    expect(fn () => app(StoreCollectionPasses::class)->validate($order, $actor, ['manual_code' => $proof['manual_code']]))->toThrow(ValidationException::class);
    app(StoreRefunds::class)->request($order, $actor, (string) Str::uuid(), 'Buyer cancelled before receipt');
    expect($order->fresh()->payment_state)->toBe('refund_pending');
});

it('does not turn a pickup order into delivery or withdraw dispatched goods back into preparation', function () {
    [,,$actor,,$order] = StoreCheckoutScenario::collectionReady();
    expect(fn () => app(StoreDeliveries::class)->dispatch($order, $actor, (string) Str::uuid()))->toThrow(ValidationException::class);
    [,,$actor,,$order] = deliveryReadyScenario();
    app(StoreDeliveries::class)->dispatch($order, $actor, (string) Str::uuid());
    expect(fn () => app(StoreFulfilment::class)->prepare($order, $actor, (string) Str::uuid(), 'Still on the road'))->toThrow(ValidationException::class);
});

it('records a physical return during financial review without clearing the review or changing money', function () {
    [,,$actor,,$order] = deliveryReadyScenario();
    $service = app(StoreDeliveries::class);
    $service->dispatch($order, $actor, (string) Str::uuid());
    $order->update(['review_reason' => 'additional_capture']);
    $service->attemptFailed($order, $actor, (string) Str::uuid(), 'Address inaccessible');
    $service->returned($order, $actor, (string) Str::uuid(), 'Goods back at office');
    expect($order->fresh()->fulfilment_state)->toBe('ready')->and($order->fresh()->review_reason)->toBe('additional_capture')->and($order->fresh()->payment_state)->toBe('paid');
    expect(fn () => app(StoreDeliveries::class)->dispatch($order, $actor, (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('treats delivered orders as completed when applying snapshotted payout reserve floors', function () {
    [$tenant,$variant,$actor,$session,$order] = deliveryReadyScenario(5000);
    $prior = StoreCommercialAgreement::query()->where('shop_id', $variant->shop_id)->orderByDesc('version')->firstOrFail();
    StoreCommercialAgreement::factory()->create(['tenant_id' => $tenant->id, 'shop_id' => $variant->shop_id, 'version' => 3, 'account_fingerprint' => $prior->account_fingerprint, 'payout_policy' => ['holdback_pesewas' => 0, 'manual_release' => true]]);
    $before = app(App\Services\Marketplace\StoreBalance::class)->available($tenant);
    app(StoreDeliveries::class)->dispatch($order, $actor, (string) Str::uuid());
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    app(StoreCollections::class)->confirm($order, $actor, ['manual_code' => $proof['manual_code']], (string) Str::uuid());
    expect(app(App\Services\Marketplace\StoreBalance::class)->available($tenant))->toBe($before + 5000);
});
