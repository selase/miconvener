<?php

declare(strict_types=1);

use App\Models\StoreCollection;
use App\Models\StoreCollectionPass;
use App\Services\Marketplace\StoreCollectionPasses;
use Illuminate\Support\Str;
use Tests\Support\StoreCheckoutScenario;

it('gives collection-only staff a private workspace without finance or buyer-address list data', function () {
    [$tenant,,$actor,,$order] = StoreCheckoutScenario::collectionReady();
    $actor->tenants()->attach($tenant->id);
    $actor->revokePermissionTo('manage store catalog');
    $host = $tenant->slug.'.miconvener.test';
    $this->actingAs($actor)->getJson('http://'.$host.'/store/collections', ['HTTP_HOST' => $host])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('orders.data.0.id', $order->id)->assertDontSee('buyer@example.test', false)->assertDontSee('total_pesewas', false)->assertDontSee('captures', false);
    $this->getJson('http://'.$host.'/store/finance', ['HTTP_HOST' => $host])->assertForbidden();
    $this->getJson('http://'.$host.'/store/orders', ['HTTP_HOST' => $host])->assertForbidden();
});

it('matches without consuming and requires explicit full-handover confirmation at the HTTP boundary', function () {
    [$tenant,,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    $actor->tenants()->attach($tenant->id);
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $host = $tenant->slug.'.miconvener.test';
    $base = 'http://'.$host.'/store/collections/'.$order->id;
    $headers = ['HTTP_HOST' => $host];
    $this->actingAs($actor);
    $credential = ['manual_code' => $proof['manual_code']];
    $this->postJson($base.'/validate', ['credential' => $credential], $headers)->assertOk()->assertJsonPath('order.can_confirm', true);
    expect(StoreCollectionPass::query()->findOrFail($proof['pass_id'])->consumed_at)->toBeNull();
    $key = (string) Str::uuid();
    $this->postJson($base.'/confirm', ['credential' => $credential, 'operation_key' => $key], $headers)->assertUnprocessable();
    $this->postJson($base.'/confirm', ['credential' => $credential, 'operation_key' => $key, 'all_items_handed_over' => true], $headers)->assertOk()->assertJsonPath('order.fulfilment_state', 'collected');
    $this->postJson($base.'/confirm', ['credential' => $credential, 'operation_key' => $key, 'all_items_handed_over' => true], $headers)->assertOk();
    expect(StoreCollection::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('keeps history readable while paused and denies new pass validation', function () {
    [$tenant,,$actor,$session,$order] = StoreCheckoutScenario::collectionReady();
    $actor->tenants()->attach($tenant->id);
    $proof = app(StoreCollectionPasses::class)->issue($order, $session, (string) Str::uuid());
    $tenant->features()->where('feature_key', 'commerce_collection')->update(['enabled' => false]);
    $host = $tenant->slug.'.miconvener.test';
    $this->actingAs($actor)->getJson('http://'.$host.'/store/collections', ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('enabled', false);
    $this->postJson('http://'.$host.'/store/collections/'.$order->id.'/validate', ['credential' => ['manual_code' => $proof['manual_code']]], ['HTTP_HOST' => $host])->assertUnprocessable();
});

it('reveals delivery destination only in an authorised order detail and validates physical-return consent', function () {
    [$tenant,,$actor,,$order] = StoreCheckoutScenario::deliveryReady();
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.miconvener.test';
    $base = 'http://'.$host.'/store/collections';
    $headers = ['HTTP_HOST' => $host];
    $this->actingAs($actor);
    $this->getJson($base, $headers)->assertOk()->assertDontSee('Test Street', false);
    $this->getJson($base.'/'.$order->id, $headers)->assertOk()->assertJsonPath('order.destination.address_line_1', '12 Test Street');
    $this->postJson($base.'/'.$order->id.'/dispatch', ['operation_key' => (string) Str::uuid()], $headers)->assertOk()->assertJsonPath('order.fulfilment_state', 'dispatched');
    $this->postJson($base.'/'.$order->id.'/returned', ['operation_key' => (string) Str::uuid(), 'reason' => 'Back at office'], $headers)->assertUnprocessable();
    $this->postJson($base.'/'.$order->id.'/returned', ['operation_key' => (string) Str::uuid(), 'reason' => 'Back at office', 'all_items_returned_to_seller' => true], $headers)->assertOk()->assertJsonPath('order.fulfilment_state', 'ready');
});

it('clears access when collection permission is revoked and denies foreign order ids', function () {
    [$tenant,,$actor,,$order] = StoreCheckoutScenario::collectionReady();
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.miconvener.test';
    $this->actingAs($actor)->getJson('http://'.$host.'/store/collections/'.Str::uuid(), ['HTTP_HOST' => $host])->assertNotFound();
    $actor->revokePermissionTo('manage store collection');
    $this->getJson('http://'.$host.'/store/collections/'.$order->id, ['HTTP_HOST' => $host])->assertForbidden();
});

it('shows an explicit handover action only to the original buyer after readiness and never creates a pass on reading', function (): void {
    [, , , , $order] = StoreCheckoutScenario::collectionReady();
    $this->withSession(proofFor('buyer@example.test'))->getJson('/my/store-orders/'.$order->id)
        ->assertOk()->assertJsonPath('order.can_issue_pass', true)->assertJsonPath('order.fulfilment_label', 'Ready for collection')
        ->assertJsonMissingPath('order.manual_code')->assertJsonMissingPath('order.secret');
    expect(StoreCollectionPass::query()->count())->toBe(0);
    $this->withSession(proofFor('other@example.test'))->getJson('/my/store-orders/'.$order->id)->assertNotFound();
});

it('records readiness reversal and failed-delivery actions through their guarded native routes', function (): void {
    [$tenant, , $actor, , $order] = StoreCheckoutScenario::collectionReady();
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.miconvener.test';
    $headers = ['HTTP_HOST' => $host];
    $base = 'http://'.$host.'/store/collections/'.$order->id;
    $this->actingAs($actor)->postJson($base.'/prepare', ['operation_key' => (string) Str::uuid(), 'reason' => 'Check every item'], $headers)->assertOk()->assertJsonPath('order.fulfilment_state', 'awaiting_preparation');
    $this->postJson($base.'/ready', ['operation_key' => (string) Str::uuid()], $headers)->assertOk()->assertJsonPath('order.fulfilment_state', 'ready');
});

it('records an unsuccessful delivery attempt without implying return or receipt', function (): void {
    [$other, , $deliveryActor, , $delivery] = StoreCheckoutScenario::deliveryReady();
    $deliveryActor->tenants()->attach($other->id);
    $host = $other->slug.'.miconvener.test';
    $headers = ['HTTP_HOST' => $host];
    $base = 'http://'.$host.'/store/collections/'.$delivery->id;
    app(App\Services\Marketplace\StoreDeliveries::class)->dispatch($delivery, $deliveryActor, (string) Str::uuid());
    $this->actingAs($deliveryActor)->postJson($base.'/delivery-failed', ['operation_key' => (string) Str::uuid(), 'reason' => 'Recipient unavailable'], $headers)->assertOk()->assertJsonPath('order.fulfilment_state', 'dispatched');
});

it('labels cancelled and reviewed orders honestly rather than calling them in preparation', function (): void {
    [, , , , $order] = StoreCheckoutScenario::collectionReady();
    $order->state = 'review';
    $order->review_reason = 'capture_needs_review';
    expect(app(App\Services\Marketplace\StoreCollectionPayload::class)->summary($order)['label'])->toBe('Needs review');
    $order->state = 'cancelled';
    $order->review_reason = null;
    $order->fulfilment_state = 'cancelled';
    expect(app(App\Services\Marketplace\StoreCollectionPayload::class)->summary($order)['label'])->toBe('Cancelled');
});

it('opens a direct native order URL with a protected selected-order loader rather than an empty workspace', function (): void {
    [$tenant, , $actor, , $order] = StoreCheckoutScenario::collectionReady();
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.miconvener.test';
    $this->actingAs($actor)->get('http://'.$host.'/store/collections/'.$order->id, ['HTTP_HOST' => $host])
        ->assertOk()->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->component('Tenant/Store/Collections/Index')->where('orderId', $order->id)->missing('order.destination'));
});
