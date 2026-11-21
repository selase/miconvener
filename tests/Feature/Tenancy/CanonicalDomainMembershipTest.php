<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Community\MemberAccess;
use App\Services\Dues\DuesReminders;
use App\Services\Marketplace\VenueMoneyPermissions;

it('denies retained domain permissions after membership suspension or removal', function (string $membership, string $status): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);
    if ($membership !== 'absent') {
        $user->allTenants()->attach($tenant->id, ['status' => $membership]);
    }
    setPermissionsTeamId($tenant->id);
    foreach (['manage members', 'read finance', VenueMoneyPermissions::REFUNDS] as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['category' => 'test']);
        $user->givePermissionTo($permission);
    }

    expect(fn () => app(MemberAccess::class)->authorizeStaff($tenant, $user))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    expect(fn () => app(DuesReminders::class)->authorizeArrears($tenant, (int) $user->id))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(app(VenueMoneyPermissions::class)->allows($user, $tenant->id, VenueMoneyPermissions::REFUNDS))->toBeFalse();
})->with([['absent', 'active'], ['suspended', 'active'], ['revoked', 'active'], ['active', 'inactive']]);

it('allows secondary membership with its own domain permissions', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id);
    setPermissionsTeamId($tenant->id);
    Permission::query()->firstOrCreate(['name' => VenueMoneyPermissions::REFUNDS, 'guard_name' => 'web'], ['category' => 'venue']);
    $user->givePermissionTo(VenueMoneyPermissions::REFUNDS);

    expect(app(VenueMoneyPermissions::class)->allows($user, $tenant->id, VenueMoneyPermissions::REFUNDS))->toBeTrue();
});

it('rejects a stale primary tenant at finance giving and store write boundaries', function (): void {
    $tenant = Tenant::factory()->create(['isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    foreach (['manage payouts', 'manage giving', 'read finance', 'manage store catalog'] as $permission) {
        Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['category' => 'test']);
        $user->givePermissionTo($permission);
    }
    $fund = App\Models\GivingFund::factory()->create(['tenant_id' => $tenant->id]);
    $agreement = App\Models\StoreCommercialAgreement::factory()->create(['shop_id' => App\Models\Shop::factory()->create(['tenant_id' => $tenant->id])->id]);
    $order = App\Models\StoreOrder::factory()->create(['agreement_id' => $agreement->id]);

    expect(fn () => app(App\Services\Finance\CommunityPayouts::class)->reserve($tenant, $user, App\Enum\LedgerCategoryEnum::Dues, []))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => app(App\Services\Giving\GivingCash::class)->record($fund, $user, [], 'denied'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(app(App\Services\Marketplace\StoreOrderAccess::class)->staff($order, $tenant, $user, 'manage store catalog'))->toBeFalse();
    expect(app(App\Services\Marketplace\StoreSellerQuotes::class)->canManage($tenant, $user))->toBeFalse();
    expect(fn () => app(App\Services\Marketplace\StorePayouts::class)->reserve($tenant, $user, []))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});
