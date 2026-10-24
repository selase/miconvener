<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Support\StoreCheckoutScenario;

it('requires original live email proof for history and returns generic denied order ids', function (): void {
    [, , , , $order] = StoreCheckoutScenario::paid();
    $this->get('/my/store-orders/'.$order->id)->assertRedirect();
    $this->getJson('/my/store-orders/'.$order->id)->assertUnauthorized();
    $this->withSession(proofFor('other@example.test'))->getJson('/my/store-orders/'.$order->id)->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/my/store-orders/'.Str::uuid())->assertNotFound();
    $this->withSession(proofFor('buyer@example.test'))->getJson('/my/store-orders/'.$order->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('order.total_pesewas', 10000)
        ->assertJsonMissingPath('order.platform_fee_pesewas')->assertJsonMissingPath('order.account_fingerprint')->assertJsonMissingPath('order.buyer_email');
    $this->getJson('/my/store-orders')->assertOk()->assertJsonCount(1, 'orders.data');
    $this->getJson('/my/store-orders/'.$order->id.'/receipt')->assertOk()->assertJsonPath('receipt.total_pesewas', 10000);
    $this->travel(13)->hours();
    $this->getJson('/my/store-orders')->assertUnauthorized();
});

it('shows pending and review orders without offering a false receipt and preserves history when catalog is disabled', function (): void {
    [$tenant, , , , $order] = StoreCheckoutScenario::online();
    $tenant->features()->where('feature_key', 'commerce_checkout')->update(['enabled' => false]);
    $this->withSession(proofFor('buyer@example.test'))->getJson('/my/store-orders/'.$order->id)->assertOk()
        ->assertJsonPath('order.payment_state', 'awaiting')->assertJsonPath('order.receipt_url', null);
    $this->getJson('/my/store-orders/'.$order->id.'/receipt')->assertNotFound();
});

it('gates seller orders and finance separately and denies another organisation', function (): void {
    [$tenant, , $actor, , $order] = StoreCheckoutScenario::paid();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store orders', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store orders');
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $this->actingAs($actor)->getJson('http://'.$host.'/store/orders', ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('orders.data.0.id', $order->id);
    $this->getJson('http://'.$host.'/store/finance', ['HTTP_HOST' => $host])->assertForbidden();
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $this->getJson('http://'.$host.'/store/finance', ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('finance', true)->assertJsonPath('available_pesewas', 9605);
});

it('allows the verified buyer to release only an unpaid quote without an unresolved payment attempt', function (): void {
    [$tenant, $variant, , $session] = StoreCheckoutScenario::make(1);
    $order = \Tests\Support\StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $this->withSession(proofFor('buyer@example.test'))->getJson('/my/store-orders')->assertOk()->assertJsonCount(1, 'orders.data')->assertJsonPath('orders.data.0.payment_label', 'Quote ready');
    $this->postJson('/my/store-orders/'.$order->id.'/cancel')->assertOk();
    expect($order->fresh()->state)->toBe('cancelled')->and(App\Models\StoreStockAllocation::query()->where('order_id', $order->id)->sole()->state)->toBe('released');
});

it('lets authorised finance staff reconcile an original payment without creating another charge', function (): void {
    [$tenant, $variant, $actor, , $order, $attempt] = StoreCheckoutScenario::online();
    setPermissionsTeamId($tenant->id);
    App\Models\Permission::query()->firstOrCreate(['name' => 'manage store finance', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store finance');
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $data = ['id' => 987, ...StoreCheckoutScenario::evidence($attempt)];
    Illuminate\Support\Facades\Http::fake(['*api.paystack.co/transaction/verify/*' => Illuminate\Support\Facades\Http::response(['data' => $data])]);
    $this->actingAs($actor)->postJson('http://'.$host.'/store/orders/'.$order->id.'/reconcile', [], ['HTTP_HOST' => $host])->assertOk()->assertJsonPath('status', 'confirmed');
    expect($variant->fresh()->on_hand)->toBe(2);
    Illuminate\Support\Facades\Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

it('shares Store discovery only for enabled catalog or retained history', function (): void {
    [$tenant, $variant, $actor, $session] = StoreCheckoutScenario::make();
    $actor->tenants()->attach($tenant->id);
    $host = $tenant->slug.'.'.mb_ltrim((string) config('session.domain'), '.');
    $request = Illuminate\Http\Request::create('http://'.$host.'/store/orders');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $actor);
    app(App\Services\Tenancy\TenantContext::class)->setTenant($tenant);
    $share = fn () => app(App\Http\Middleware\HandleInertiaRequests::class)->share($request)['tenant']['features'];
    expect($share()['commerce_history'])->toBeTrue();
    $tenant->features()->where('feature_key', 'commerce_catalog')->update(['enabled' => false]);
    expect($share()['commerce_history'])->toBeFalse();
    $tenant->features()->where('feature_key', 'commerce_catalog')->update(['enabled' => true]);
    \Tests\Support\StoreCheckoutScenario::quote($tenant, $session, [['variant_id' => $variant->id, 'quantity' => 1]], (string) Str::uuid());
    $tenant->features()->update(['enabled' => false]);
    expect($share()['commerce_history'])->toBeTrue()->and($share()['commerce_catalog'])->toBeFalse();
});
