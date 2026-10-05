<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sms\SmsAllowance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * A superadmin can give an organisation an add-on without charging for it
 * (goodwill credits, a launch partner's promotion). It works like a bought
 * one, records no payment, and keeps who gave it and why.
 */
beforeEach(function (): void {
    $this->seed(Database\Seeders\RoleSeeder::class);
    $this->seed(Database\Seeders\PermissionsSeeder::class);
    $this->tenant = Tenant::factory()->create(['name' => 'Accra Summit', 'isolation_mode' => 'shared']);
    $this->superadmin = User::factory()->create(['tenant_id' => null, 'first_name' => 'Selase']);
    setPermissionsTeamId(null);
    $this->superadmin->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
});

test('granted SMS credits can be spent and are not counted as a payment', function (): void {
    $this->actingAs($this->superadmin)
        ->post(route('tenants.addons.store', $this->tenant->uuid), [
            'addon_key' => 'sms_500',
            'packs' => 2,
            'reason' => 'Goodwill after the SMS outage',
        ])
        ->assertSessionHas('success');

    $addon = TenantAddon::query()->where('tenant_id', $this->tenant->id)->firstOrFail();

    expect($addon->quantity)->toBe(1000)
        ->and($addon->total_price)->toBe(0)
        ->and($addon->meta['grant_reason'])->toBe('Goodwill after the SMS outage')
        ->and(app(SmsAllowance::class)->packRemaining($this->tenant))->toBe(1000)
        ->and(Transaction::query()->count())->toBe(0);

    $this->assertDatabaseHas('activity_log', ['description' => 'Granted Prepaid SMS Pack (500 SMS) to Accra Summit'], 'landlord');
});

test('a grant needs a reason', function (): void {
    $this->actingAs($this->superadmin)
        ->post(route('tenants.addons.store', $this->tenant->uuid), ['addon_key' => 'sms_500', 'packs' => 1, 'reason' => ''])
        ->assertSessionHasErrors('reason');

    expect(TenantAddon::query()->count())->toBe(0);
});

test('a marketplace promotion is only granted to an organisation with a business', function (): void {
    $this->actingAs($this->superadmin)
        ->post(route('tenants.addons.store', $this->tenant->uuid), ['addon_key' => 'shop_boost', 'packs' => 1, 'reason' => 'Launch partner'])
        ->assertSessionHas('error');

    Shop::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->actingAs($this->superadmin)
        ->post(route('tenants.addons.store', $this->tenant->uuid), ['addon_key' => 'shop_boost', 'packs' => 1, 'reason' => 'Launch partner'])
        ->assertSessionHas('success');

    expect($this->tenant->shop->hasActivePromotion(TenantAddon::TYPE_SHOP_BOOST))->toBeTrue();
});

test('a withdrawn grant stops working at once; paid add-ons cannot be withdrawn here', function (): void {
    $this->actingAs($this->superadmin)
        ->post(route('tenants.addons.store', $this->tenant->uuid), ['addon_key' => 'sms_500', 'packs' => 1, 'reason' => 'Trial credits']);
    $granted = TenantAddon::query()->firstOrFail();
    $paid = TenantAddon::factory()->create(['tenant_id' => $this->tenant->id, 'addon_type' => TenantAddon::TYPE_EMAIL_PACK, 'status' => TenantAddon::STATUS_ACTIVE]);

    $this->actingAs($this->superadmin)
        ->delete(route('tenants.addons.destroy', [$this->tenant->uuid, $granted->id]))
        ->assertSessionHas('success');

    $this->actingAs($this->superadmin)
        ->delete(route('tenants.addons.destroy', [$this->tenant->uuid, $paid->id]))
        ->assertSessionHas('error');

    expect(app(SmsAllowance::class)->packRemaining($this->tenant))->toBe(0)
        ->and($paid->fresh()->status)->toBe(TenantAddon::STATUS_ACTIVE);
});

test('the add-ons page shows paid and granted add-ons; organisation admins cannot reach it', function (): void {
    $this->actingAs($this->superadmin)
        ->post(route('tenants.addons.store', $this->tenant->uuid), ['addon_key' => 'email_5000', 'packs' => 1, 'reason' => 'Launch partner']);

    $this->actingAs($this->superadmin)
        ->get(route('tenants.addons.index', $this->tenant->uuid))
        ->assertOk()
        ->assertSee('Email Blast Pack (5,000 Emails)')
        ->assertSee('Granted')
        ->assertSee('Launch partner');

    $member = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setActiveTenantForTest($member);
    $member->assignRole('Org Superadmin');

    $this->actingAs($member)->get(route('tenants.addons.index', $this->tenant->uuid))->assertForbidden();
    $this->actingAs($member)
        ->post(route('tenants.addons.store', $this->tenant->uuid), ['addon_key' => 'sms_5000', 'packs' => 50, 'reason' => 'Free credits please'])
        ->assertForbidden();
});
