<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\StoreOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\StoreOrderAccess;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function storeBuyerSession(?string $email = null): Store
{
    $session = new Store('store-access-test', new ArraySessionHandler(120));
    if ($email !== null) {
        $session->put(proofFor($email));
    }

    return $session;
}

it('grants history only to the original currently verified email without requiring dues or membership', function (): void {
    $order = StoreOrder::factory()->create(['buyer_email' => 'buyer@example.test']);
    $access = app(StoreOrderAccess::class);
    expect($access->buyer($order, storeBuyerSession(' BUYER@example.test ')))->toBeTrue()
        ->and($access->buyer($order, storeBuyerSession('new-contact@example.test')))->toBeFalse()
        ->and($access->buyer($order, storeBuyerSession()))->toBeFalse();
});

it('does not inherit history through phone or representative credentials or expired email proof', function (): void {
    $order = StoreOrder::factory()->create(['buyer_email' => 'buyer@example.test']);
    $session = storeBuyerSession('buyer@example.test');
    $session->put(PlatformAttendeeVerification::SESSION_KEY.'.expires_at', now()->getTimestamp());
    $session->put('community_verified_phone', ['phone' => '+233241234567', 'expires_at' => now()->addHour()->timestamp]);
    expect(app(StoreOrderAccess::class)->buyer($order, $session))->toBeFalse();
});

it('does not transfer a buyers receipt when a member contact changes or lapses', function (): void {
    $order = StoreOrder::factory()->create(['buyer_email' => 'original@example.test']);
    $member = App\Models\Member::factory()->create(['tenant_id' => $order->tenant_id, 'email' => 'original@example.test']);
    $member->update(['email' => 'replacement@example.test', 'status' => App\Enum\MemberStatusEnum::Lapsed]);
    expect(app(StoreOrderAccess::class)->buyer($order, storeBuyerSession('original@example.test')))->toBeTrue()
        ->and(app(StoreOrderAccess::class)->buyer($order, storeBuyerSession('replacement@example.test')))->toBeFalse();
});

it('bounds staff permissions to both the current seller and its actual membership', function (): void {
    $order = StoreOrder::factory()->create();
    $tenant = $order->tenant;
    $actor = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    Permission::query()->firstOrCreate(['name' => 'manage store catalog', 'guard_name' => 'web'], ['category' => 'store']);
    $actor->givePermissionTo('manage store catalog');
    $access = app(StoreOrderAccess::class);
    expect($access->staff($order, $tenant, $actor, 'manage store catalog'))->toBeTrue()
        ->and($access->staff($order, Tenant::factory()->create(), $actor, 'manage store catalog'))->toBeFalse()
        ->and($access->staff($order, $tenant, User::factory()->create(), 'manage store catalog'))->toBeFalse();
});
