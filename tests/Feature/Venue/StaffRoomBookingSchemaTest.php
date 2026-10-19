<?php

declare(strict_types=1);

use App\Models\Member;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;

/**
 * Everything that exists today keeps its behaviour: the new columns default to
 * the old world (public rooms, marketplace bookings, members who are not staff).
 */
beforeEach(function (): void {
    refreshTenantDatabases();
});

test('new columns default to existing behaviour', function (): void {
    $tenant = Tenant::factory()->create();
    $shop = Shop::factory()->create(['tenant_id' => $tenant->id]);
    $listing = StoreListing::factory()->create(['shop_id' => $shop->id]);
    $member = Member::factory()->create(['tenant_id' => $tenant->id]);

    expect($listing->fresh()->audience)->toBe(StoreListing::AUDIENCE_PUBLIC)
        ->and($listing->fresh()->requires_approval)->toBeTrue()
        ->and($member->fresh()->is_staff)->toBeFalse()
        ->and($tenant->fresh()->staff_email_domains)->toBe([])
        ->and($tenant->fresh()->booking_limits)->toBeNull()
        ->and(VenueBooking::KIND_INTERNAL)->toBe('internal')
        ->and(VenueBooking::STATUS_PENDING_APPROVAL)->toBe('pending_approval')
        ->and(VenueBooking::STATUS_DECLINED)->toBe('declined');
});

test('a booking is a marketplace booking unless it says otherwise', function (): void {
    $booking = VenueBooking::factory()->create();

    expect($booking->fresh()->kind)->toBe(VenueBooking::KIND_MARKETPLACE)
        ->and($booking->fresh()->requested_for_member_id)->toBeNull();
});
