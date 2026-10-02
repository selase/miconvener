<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Models\Shop;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('non-superadmin users are forbidden from marketplace verification queue', function (): void {
    $user = User::factory()->create();
    $tenant = setActiveTenantForTest($user);
    $user->assignRole('Org Admin');

    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Demo Hotel',
        'slug' => 'demo-hotel',
        'email' => 'demo@hotel.com',
        'phone' => '123',
        'address' => 'Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_PENDING,
    ]);

    $this->actingAs($user)
        ->get(route('admin.marketplace-verifications.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('admin.marketplace-verifications.show', $shop))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('admin.marketplace-verifications.approve', $shop))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('admin.marketplace-verifications.reject', $shop), [
            'rejection_reason' => 'Invalid business registration.',
        ])
        ->assertForbidden();
});

test('superadmin can review and approve a pending venue merchant', function (): void {
    // Platform Superadmin (tenant_id = null)
    $superadmin = User::factory()->create(['tenant_id' => null]);
    $superadmin->assignRole('Superadmin');

    $tenant = Tenant::factory()->create(['name' => 'Labadi Beach Hotel']);
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Labadi Beach Hotel',
        'slug' => 'labadi-beach-hotel',
        'email' => 'sales@labadibeach.com',
        'phone' => '+233244111222',
        'address' => '1 La Bypass, Accra',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_PENDING,
    ]);

    // Access queue
    $this->actingAs($superadmin)
        ->get(route('admin.marketplace-verifications.index'))
        ->assertOk()
        ->assertSee('Venue Host Verification Queue')
        ->assertSee('Labadi Beach Hotel');

    // View detail
    $this->actingAs($superadmin)
        ->get(route('admin.marketplace-verifications.show', $shop))
        ->assertOk()
        ->assertSee('Labadi Beach Hotel')
        ->assertSee('1 La Bypass, Accra');

    // Approve
    $response = $this->actingAs($superadmin)
        ->post(route('admin.marketplace-verifications.approve', $shop));

    $response->assertRedirect(route('admin.marketplace-verifications.index'));

    $freshShop = $shop->fresh();
    expect($freshShop->isVerified())->toBeTrue()
        ->and($freshShop->verification_status)->toBe(Shop::VERIFICATION_VERIFIED)
        ->and((string) $freshShop->verified_by_user_id)->toBe((string) $superadmin->id)
        ->and($freshShop->verified_at)->not->toBeNull();
});

test('superadmin can reject a pending venue merchant with a reason', function (): void {
    $superadmin = User::factory()->create(['tenant_id' => null]);
    $superadmin->assignRole('Superadmin');

    $tenant = Tenant::factory()->create();
    $shop = Shop::create([
        'tenant_id' => $tenant->id,
        'name' => 'Unverified Space',
        'slug' => 'unverified-space',
        'email' => 'fake@space.com',
        'phone' => '000',
        'address' => 'Unknown',
        'city' => 'Accra',
        'region' => 'Greater Accra',
        'verification_status' => Shop::VERIFICATION_PENDING,
    ]);

    $response = $this->actingAs($superadmin)
        ->post(route('admin.marketplace-verifications.reject', $shop), [
            'rejection_reason' => 'Business license could not be verified by registry.',
        ]);

    $response->assertRedirect(route('admin.marketplace-verifications.index'));

    $freshShop = $shop->fresh();
    expect($freshShop->isVerified())->toBeFalse()
        ->and($freshShop->verification_status)->toBe(Shop::VERIFICATION_REJECTED)
        ->and($freshShop->rejection_reason)->toBe('Business license could not be verified by registry.')
        ->and($freshShop->verified_at)->toBeNull();
});
