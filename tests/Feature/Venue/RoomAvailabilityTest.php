<?php

declare(strict_types=1);

use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueAvailabilityService;
use Carbon\CarbonImmutable;

/**
 * One calendar for every kind of booking: a staff request, a paid booking and a
 * blackout all occupy a room, and the public sees only that it is busy.
 */
beforeEach(function (): void {
    refreshTenantDatabases();

    $this->tenant = Tenant::factory()->create();
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->room = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED]);
    $this->nine = CarbonImmutable::now()->addDays(2)->setTime(9, 0);
    $this->book = fn (array $attributes): VenueBooking => VenueBooking::factory()->create([
        'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id,
        'starts_at' => $this->nine, 'ends_at' => $this->nine->addHours(2),
    ] + $attributes);
    $this->free = fn (?CarbonImmutable $from = null, ?CarbonImmutable $to = null): bool => app(VenueAvailabilityService::class)
        ->isSlotAvailable($this->room, $from ?? $this->nine->addMinutes(30), $to ?? $this->nine->addHour());
});

test('a confirmed internal booking blocks a public booking', function (): void {
    ($this->book)(['kind' => VenueBooking::KIND_INTERNAL, 'status' => VenueBooking::STATUS_CONFIRMED]);

    expect(($this->free)())->toBeFalse();
});

test('a pending staff request holds the slot', function (): void {
    ($this->book)(['kind' => VenueBooking::KIND_INTERNAL, 'status' => VenueBooking::STATUS_PENDING_APPROVAL]);

    expect(($this->free)())->toBeFalse();
});

test('a declined or cancelled request frees the slot', function (string $status): void {
    ($this->book)(['kind' => VenueBooking::KIND_INTERNAL, 'status' => $status]);

    expect(($this->free)())->toBeTrue();
})->with([VenueBooking::STATUS_DECLINED, VenueBooking::STATUS_CANCELLED]);

test('a blackout blocks the room for both kinds', function (): void {
    ($this->book)(['status' => VenueBooking::STATUS_BLOCKED]);

    expect(($this->free)())->toBeFalse();
});

test('back-to-back slots do not overlap', function (): void {
    ($this->book)(['kind' => VenueBooking::KIND_INTERNAL, 'status' => VenueBooking::STATUS_CONFIRMED]);

    expect(($this->free)($this->nine->addHours(2), $this->nine->addHours(3)))->toBeTrue()
        ->and(($this->free)($this->nine->subHour(), $this->nine))->toBeTrue();
});

test('busy times carry only the times and a neutral status, never who or why', function (): void {
    ($this->book)(['kind' => VenueBooking::KIND_INTERNAL, 'status' => VenueBooking::STATUS_PENDING_APPROVAL, 'department' => 'Finance', 'purpose' => 'Budget review', 'planner_name' => 'Ama Mensah']);
    ($this->book)(['status' => VenueBooking::STATUS_CONFIRMED, 'starts_at' => $this->nine->addDay(), 'ends_at' => $this->nine->addDay()->addHours(2)]);

    $slots = app(VenueAvailabilityService::class)->getBookedSlots($this->room, $this->nine->subDay(), $this->nine->addDays(3));

    expect($slots)->toHaveCount(2)
        ->and($slots->first())->toHaveKeys(['starts_at', 'ends_at', 'status'])->and(array_keys($slots->first()))->toBe(['starts_at', 'ends_at', 'status'])
        ->and($slots->pluck('status')->unique()->all())->toBe(['confirmed'])
        ->and(json_encode($slots))->not->toContain('Finance')->not->toContain('Budget review')->not->toContain('Ama Mensah');
});

test('a blackout is refused when a staff request already holds the time', function (): void {
    ($this->book)(['kind' => VenueBooking::KIND_INTERNAL, 'status' => VenueBooking::STATUS_PENDING_APPROVAL]);

    expect(app(VenueAvailabilityService::class)->isSlotAvailable($this->room, $this->nine, $this->nine->addHours(1)))->toBeFalse();
});
