<?php

declare(strict_types=1);

use App\Libraries\RolePermissions;
use App\Models\Package;
use App\Models\Permission;
use App\Services\Tenancy\FeatureService;
use Illuminate\Support\Facades\Artisan;

/**
 * Staff room booking is an Enterprise entitlement; the permission to run it
 * belongs to an organisation's team, never to platform staff by default.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
});

test('only enterprise has staff_room_booking', function (): void {
    expect(FeatureService::FEATURE_STAFF_ROOM_BOOKING)->toBe('staff_room_booking');

    foreach (Package::query()->whereIn('slug', ['free', 'starter', 'growth', 'enterprise'])->get() as $package) {
        $enabled = (bool) $package->features()->where('slug', 'staff_room_booking')->first()?->pivot->value;

        expect($enabled)->toBe($package->slug === 'enterprise');
    }
});

test('manage room bookings is tenant safe and held by the organisation owner role', function (): void {
    expect(Permission::TENANT_SAFE)->toContain('manage room bookings')
        ->and(RolePermissions::defaults()['Org Superadmin'])->toContain('manage room bookings');
});

test('the booking limit defaults are 8 hours, 90 days and 5 open requests', function (): void {
    expect(config('bookings.staff.defaults'))->toBe(['max_hours' => 8, 'window_days' => 90, 'open_requests' => 5]);
});
