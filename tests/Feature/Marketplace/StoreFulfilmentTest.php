<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreFulfilmentEvent;
use App\Services\Marketplace\StoreFulfilment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\StoreCheckoutScenario;

function collectionScenario(): array
{
    $data = StoreCheckoutScenario::paid();
    [$tenant, , $actor] = $data;
    $tenant->features()->create(['feature_key' => 'commerce_collection', 'enabled' => true]);
    setPermissionsTeamId($tenant->id);
    Permission::query()->firstOrCreate(['name' => 'manage store collection', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store collection');

    return $data;
}

it('records readiness once without a second stock or money movement and never expires the ready order', function () {
    [$tenant, $variant, $actor, , $order] = collectionScenario();
    $service = app(StoreFulfilment::class);
    $key = (string) Str::uuid();
    $count = App\Models\LedgerTransaction::withoutGlobalScopes()->count();
    $event = $service->ready($order, $actor, $key);
    expect($service->ready($order, $actor, $key)->id)->toBe($event->id)
        ->and(StoreFulfilmentEvent::query()->count())->toBe(1)->and($order->fresh()->fulfilment_state)->toBe('ready')
        ->and($variant->fresh()->on_hand)->toBe(2)->and(App\Models\LedgerTransaction::withoutGlobalScopes()->count())->toBe($count);
    $this->travel(14)->days();
    expect($order->fresh()->fulfilment_state)->toBe('ready')->and($service->eligible($order->fresh()))->toBeTrue();
});

it('denies readiness to another seller and revoked staff without exposing order details', function () {
    [, , $actor, , $order] = collectionScenario();
    [, , $foreign] = StoreCheckoutScenario::make();
    expect(fn () => app(StoreFulfilment::class)->ready($order, $foreign, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    setPermissionsTeamId($order->tenant_id);
    $actor->revokePermissionTo('manage store collection');
    expect(fn () => app(StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    expect(StoreFulfilmentEvent::query()->count())->toBe(0);
});

it('keeps collection separate from catalog checkout and finance permissions', function () {
    [$tenant, , $actor, , $order] = collectionScenario();
    $actor->revokePermissionTo('manage store catalog');
    $tenant->features()->whereIn('feature_key', ['commerce_catalog', 'commerce_checkout'])->update(['enabled' => false]);
    app(StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid());
    expect($order->fresh()->fulfilment_state)->toBe('ready')->and($actor->fresh()->can('manage store finance'))->toBeFalse();
});

it('blocks readiness while money allocations or the collection switch are unsafe', function (string $change) {
    [$tenant, , $actor, , $order] = collectionScenario();
    match ($change) {
        'flag' => $tenant->features()->where('feature_key', 'commerce_collection')->update(['enabled' => false]),
        'review' => $order->update(['review_reason' => 'support_review']),
        'ledger' => $order->update(['ledger_state' => 'review']),
        'refund' => $order->update(['payment_state' => 'refund_pending']),
    };
    expect(fn () => app(StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(StoreFulfilmentEvent::query()->count())->toBe(0);
})->with(['flag', 'review', 'ledger', 'refund']);

it('rejects a purported confirmed order without consumed purchase allocations', function () {
    $order = App\Models\StoreOrder::factory()->create(['state' => 'confirmed', 'payment_state' => 'paid', 'ledger_state' => 'posted']);
    expect(app(StoreFulfilment::class)->eligible($order))->toBeFalse();
});

it('keeps an immutable readiness snapshot when product or staff names change', function () {
    [, $variant, $actor, , $order] = collectionScenario();
    $event = app(StoreFulfilment::class)->ready($order, $actor, (string) Str::uuid());
    $name = $event->actor_name;
    $title = $event->lines_snapshot[0]['title'];
    $actor->update(['first_name' => 'Changed']);
    $variant->merchandise->listing()->withoutGlobalScopes()->update(['title' => 'Changed product']);
    expect($event->fresh()->actor_name)->toBe($name)->and($event->fresh()->lines_snapshot[0]['title'])->toBe($title);
    expect(fn () => $event->update(['reason' => 'Erase history']))->toThrow(Illuminate\Database\QueryException::class);
});
