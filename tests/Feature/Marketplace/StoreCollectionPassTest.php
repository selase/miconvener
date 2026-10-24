<?php

declare(strict_types=1);

use App\Models\StoreCollectionPass;
use App\Services\Marketplace\StoreCollectionPasses;
use App\Services\Marketplace\StoreFulfilment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

function passScenario(): array
{
    return StoreCheckoutScenario::collectionReady();
}

it('issues a fifteen minute credential on explicit request without limiting how long the order stays ready', function () {
    [, , $actor, $session, $order] = passScenario();
    $this->travel(2)->days();
    $this->travelTo(now()->startOfSecond());
    $session = StoreCheckoutScenario::session();
    $key = (string) Str::uuid();
    $service = app(StoreCollectionPasses::class);
    $proof = $service->issue($order, $session, $key);
    expect($service->issue($order, $session, $key))->toBe($proof)
        ->and(mb_strlen(str_replace('-', '', $proof['manual_code'])))->toBe(12)
        ->and(now()->diffInSeconds(Carbon\CarbonImmutable::parse($proof['expires_at'])))->toBe(900.0);
    $pass = $service->validate($order, $actor, ['manual_code' => $proof['manual_code']]);
    expect($pass->getRawOriginal('secret_material'))->not->toContain(str_replace('-', '', $proof['manual_code']))
        ->and($pass->toArray())->not->toHaveKeys(['secret_material', 'qr_hash', 'manual_hash']);
    $this->travel(16)->minutes();
    expect(fn () => $service->validate($order, $actor, ['manual_code' => $proof['manual_code']]))->toThrow(ValidationException::class);
    expect($order->fresh()->fulfilment_state)->toBe('ready');
});

it('rotates the old pass and cannot resurrect it through an old operation key', function () {
    [, , $actor, $session, $order] = passScenario();
    $service = app(StoreCollectionPasses::class);
    $key = (string) Str::uuid();
    $old = $service->issue($order, $session, $key);
    $new = $service->issue($order, $session, (string) Str::uuid());
    expect($new['pass_id'])->not->toBe($old['pass_id']);
    expect(fn () => $service->issue($order, $session, $key))->toThrow(ValidationException::class);
    expect(fn () => $service->validate($order, $actor, ['manual_code' => $old['manual_code']]))->toThrow(ValidationException::class);
    expect($service->validate($order, $actor, ['manual_code' => mb_strtolower($new['manual_code'])])->id)->toBe($new['pass_id']);
});

it('requires the original email and rejects event QR or extra credential properties', function () {
    [, , $actor, $session, $order] = passScenario();
    $service = app(StoreCollectionPasses::class);
    expect(fn () => $service->issue($order, StoreCheckoutScenario::session('foreign@example.test'), (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $proof = $service->issue($order, $session, (string) Str::uuid());
    foreach ([['manual_code' => $proof['manual_code'], 'paid' => true], ['purpose' => 'event', 'pass_id' => $proof['pass_id'], 'secret' => str_repeat('a', 64)], ['manual_code' => str_repeat('X', 2049)]] as $bad) {
        expect(fn () => $service->validate($order, $actor, $bad))->toThrow(ValidationException::class);
    }
    expect(StoreCollectionPass::query()->whereNull('consumed_at')->count())->toBe(1);
});

it('revokes proof on a reasoned preparation reversal and retains immutable history', function () {
    [, , $actor, $session, $order] = passScenario();
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $key = (string) Str::uuid();
    $service = app(StoreFulfilment::class);
    $event = $service->prepare($order, $actor, $key, 'Correct item packing');
    expect($service->prepare($order, $actor, $key, 'Correct item packing')->id)->toBe($event->id)
        ->and($order->fresh()->fulfilment_state)->toBe('awaiting_preparation');
    expect(fn () => app(StoreCollectionPasses::class)->validate($order, $actor, ['manual_code' => $proof['manual_code']]))->toThrow(ValidationException::class);
    app(StoreCollectionPasses::class)->purge();
    expect(StoreCollectionPass::query()->findOrFail($proof['pass_id'])->secret_material)->toBeNull();
});

it('issues proof only through the native protected POST route with no-store responses', function () {
    [, , , , $order] = passScenario();
    $this->withSession(proofFor('buyer@example.test'))->postJson('/my/store-orders/'.$order->id.'/collection-pass', ['operation_key' => (string) Str::uuid()])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonStructure(['pass_id', 'qr_svg', 'manual_code', 'expires_at']);
    $this->withSession(proofFor('foreign@example.test'))->postJson('/my/store-orders/'.$order->id.'/collection-pass', ['operation_key' => (string) Str::uuid()])->assertNotFound();
});

it('limits pass rotation and failed guesses without cancelling the ready order', function () {
    [,,$actor,$session,$order] = passScenario();
    $service = app(StoreCollectionPasses::class);
    for ($i = 0; $i < 5; $i++) {
        $proof = $service->issue($order, $session, (string) Str::uuid());
    }
    expect(fn () => $service->issue($order, $session, (string) Str::uuid()))->toThrow(ValidationException::class);
    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $service->validate($order, $actor, ['manual_code' => 'INVALID00000']))->toThrow(ValidationException::class);
    }
    expect(fn () => $service->validate($order, $actor, ['manual_code' => $proof['manual_code']]))->toThrow(ValidationException::class);
    expect($order->fresh()->fulfilment_state)->toBe('ready');
});

it('cannot extend a stored pass expiry and does not issue after buyer email proof expires', function () {
    [,,$actor,$session,$order] = passScenario();
    $service = app(StoreCollectionPasses::class);
    $proof = $service->issue($order, $session, (string) Str::uuid());
    $session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY.'.expires_at', now()->subSecond()->getTimestamp());
    expect(fn () => $service->issue($order, $session, (string) Str::uuid()))->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $pass = StoreCollectionPass::query()->findOrFail($proof['pass_id']);
    expect(fn () => $pass->update(['expires_at' => now()->addDay()]))->toThrow(Illuminate\Database\QueryException::class);
});

it('accepts the actual issued collection QR secret and rejects a changed purpose without consuming it', function (): void {
    [, , $actor, $session, $order] = passScenario();
    $service = app(StoreCollectionPasses::class);
    $proof = $service->issue($order, $session, (string) Str::uuid());
    $pass = StoreCollectionPass::query()->findOrFail($proof['pass_id']);
    $qr = ['purpose' => 'miconvener.store.collection.v1', 'pass_id' => $pass->id, 'secret' => $pass->secret_material['qr']];
    expect(fn () => $service->validate($order, $actor, [...$qr, 'purpose' => 'event']))->toThrow(ValidationException::class);
    expect(fn () => $service->validate($order, $actor, [...$qr, 'purpose' => 'miconvener.store.delivery.v1']))->toThrow(ValidationException::class);
    expect($pass->fresh()->consumed_at)->toBeNull()->and($order->fresh()->fulfilment_state)->toBe('ready');
    expect($service->validate($order, $actor, $qr)->id)->toBe($pass->id);
    app(App\Services\Marketplace\StoreCollections::class)->confirm($order, $actor, $qr, (string) Str::uuid());
    expect($order->fresh()->fulfilment_state)->toBe('collected')->and($pass->fresh()->consumed_at)->not->toBeNull();
});

it('expires and rotates delivery QR proof and invalidates its generation after return and redispatch', function (): void {
    [, $variant, $actor, $session, $order] = StoreCheckoutScenario::deliveryReady();
    $deliveries = app(App\Services\Marketplace\StoreDeliveries::class);
    $service = app(StoreCollectionPasses::class);
    $deliveries->dispatch($order, $actor, (string) Str::uuid());
    $issued = $service->issue($order, $session, (string) Str::uuid());
    $first = StoreCollectionPass::query()->findOrFail($issued['pass_id']);
    $qr = ['purpose' => 'miconvener.store.delivery.v1', 'pass_id' => $first->id, 'secret' => $first->secret_material['qr']];
    expect(fn () => $service->validate($order, $actor, [...$qr, 'purpose' => 'miconvener.store.collection.v1']))->toThrow(ValidationException::class);
    expect($service->validate($order, $actor, $qr)->id)->toBe($first->id);
    $this->travel(16)->minutes();
    expect(fn () => $service->validate($order, $actor, $qr))->toThrow(ValidationException::class);
    expect($order->fresh()->fulfilment_state)->toBe('dispatched')->and($first->fresh()->consumed_at)->toBeNull();
    $issued = $service->issue($order, $session, (string) Str::uuid());
    $second = StoreCollectionPass::query()->findOrFail($issued['pass_id']);
    $rotated = ['purpose' => 'miconvener.store.delivery.v1', 'pass_id' => $second->id, 'secret' => $second->secret_material['qr']];
    expect(fn () => $service->validate($order, $actor, $qr))->toThrow(ValidationException::class);
    expect($service->validate($order, $actor, $rotated)->id)->toBe($second->id);
    $deliveries->returned($order, $actor, (string) Str::uuid(), 'All goods physically back');
    $deliveries->dispatch($order->fresh(), $actor, (string) Str::uuid());
    expect(fn () => $service->validate($order, $actor, $rotated))->toThrow(ValidationException::class);
    $issued = $service->issue($order, $session, (string) Str::uuid());
    $third = StoreCollectionPass::query()->findOrFail($issued['pass_id']);
    $current = ['purpose' => 'miconvener.store.delivery.v1', 'pass_id' => $third->id, 'secret' => $third->secret_material['qr']];
    expect($third->event_id)->not->toBe($second->event_id);
    app(App\Services\Marketplace\StoreCollections::class)->confirm($order, $actor, $current, (string) Str::uuid());
    expect($order->fresh()->fulfilment_state)->toBe('delivered')->and($variant->fresh()->on_hand)->toBe(2);
});
