<?php

declare(strict_types=1);

use App\Mail\StoreOrderReady;
use App\Models\StoreFulfilmentNotice;
use App\Services\Marketplace\StoreFulfilment;
use App\Services\Marketplace\StoreFulfilmentNotices;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\StoreCheckoutScenario;

it('retains one ready notice per ready event without pass material or delivery addresses', function (): void {
    Queue::fake();
    [, , $actor, , $order] = StoreCheckoutScenario::deliveryReady();
    $notice = StoreFulfilmentNotice::query()->sole();
    expect($notice->snapshot)->not->toHaveKeys(['destination', 'manual_code', 'secret', 'qr_svg']);
    expect(json_encode($notice->snapshot))->not->toContain('Test Street');
    $event = $order->fulfilmentEvents()->where('kind', 'ready')->sole();
    expect(app(StoreFulfilmentNotices::class)->queue($event)->id)->toBe($notice->id);
    expect(StoreFulfilmentNotice::query()->count())->toBe(1);
    $html = (new StoreOrderReady($notice))->render();
    expect($html)->toContain('Delivery is included')->toContain('Check your order')->not->toContain('Test Street');
});

it('suppresses a superseded readiness notice and creates a fresh notice on the next ready event', function (): void {
    Queue::fake();
    Mail::fake();
    [, , $actor, , $order] = StoreCheckoutScenario::collectionReady();
    $first = StoreFulfilmentNotice::query()->sole();
    app(StoreFulfilment::class)->prepare($order, $actor, (string) Str::uuid(), 'Need to check size');
    app(StoreFulfilmentNotices::class)->deliver($first->id);
    expect($first->fresh()->state)->toBe('superseded');
    Mail::assertNothingSent();
    app(StoreFulfilment::class)->ready($order->fresh(), $actor, (string) Str::uuid());
    expect(StoreFulfilmentNotice::query()->count())->toBe(2);
});

it('keeps temporary pauses retryable without spending a send attempt and sends once after resuming', function (): void {
    Queue::fake();
    Mail::fake();
    [$tenant, , , , $order] = StoreCheckoutScenario::collectionReady();
    $notice = StoreFulfilmentNotice::query()->sole();
    $tenant->features()->where('feature_key', 'commerce_collection')->update(['enabled' => false]);
    app(StoreFulfilmentNotices::class)->deliver($notice->id);
    expect($notice->fresh()->attempts)->toBe(0)->and($notice->fresh()->state)->toBe('retryable');
    $tenant->features()->where('feature_key', 'commerce_collection')->update(['enabled' => true]);
    $this->travel(5)->minutes();
    app(StoreFulfilmentNotices::class)->deliver($notice->id);
    app(StoreFulfilmentNotices::class)->deliver($notice->id);
    Mail::assertSent(StoreOrderReady::class, 1);
    expect($notice->fresh()->state)->toBe('sent');
});

it('bounds failed sends and recovers abandoned claims without changing order or receipt state', function (): void {
    Queue::fake();
    [, , , , $order] = StoreCheckoutScenario::collectionReady();
    $notice = StoreFulfilmentNotice::query()->sole();
    $manager = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->times(5)->andThrow(new RuntimeException('transport unavailable'));
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fn () => app(StoreFulfilmentNotices::class)->deliver($notice->id))->toThrow(RuntimeException::class);
        $this->travel(5)->minutes();
    }
    Mail::swap($manager);
    expect($notice->fresh()->state)->toBe('exhausted')->and($order->fresh()->fulfilment_state)->toBe('ready');
    $notice->update(['state' => 'sending', 'attempts' => 1, 'claimed_at' => now()->subMinutes(11)]);
    app(StoreFulfilmentNotices::class)->retryQueued();
    Queue::assertPushed(App\Jobs\DeliverStoreReadyNotice::class);
});
