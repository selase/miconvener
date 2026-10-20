<?php

declare(strict_types=1);

use App\Mail\Venue\RoomRequestDecided;
use App\Models\Member;
use App\Models\Package;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VenueBooking;
use App\Services\Venue\InternalBookings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Staff ask for the organisation's own rooms for free. Everything the organisation
 * decided is enforced here: Enterprise only, its own rooms only, length, window,
 * open requests, no double booking, and no money anywhere.
 */
beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'EventPackageSeeder']);
    Mail::fake();

    $this->tenant = Tenant::factory()->create(['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->room = StoreListing::factory()->create(['shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED, 'audience' => StoreListing::AUDIENCE_STAFF, 'requires_approval' => true]);
    $this->staff = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ama@ugmc.example', 'is_staff' => true]);
    $this->nine = CarbonImmutable::now()->addDays(3)->setTime(9, 0);
    $this->request = fn (array $overrides = []): VenueBooking => app(InternalBookings::class)->request(...($overrides + [
        'tenant' => $this->tenant, 'room' => $this->room, 'for' => $this->staff,
        'from' => $this->nine, 'to' => $this->nine->addHours(2), 'purpose' => 'Budget review', 'department' => 'Finance',
    ]));
});

test('staff request a free room and it is pending, with no money anywhere', function (): void {
    $booking = ($this->request)();

    expect($booking->kind)->toBe(VenueBooking::KIND_INTERNAL)
        ->and($booking->status)->toBe(VenueBooking::STATUS_PENDING_APPROVAL)
        ->and($booking->requested_for_member_id)->toBe($this->staff->id)
        ->and($booking->department)->toBe('Finance')
        ->and($booking->purpose)->toBe('Budget review')
        ->and($booking->fresh()->total_amount_pesewas)->toBe(0)
        ->and($booking->payment_status)->toBe(VenueBooking::PAYMENT_UNPAID)
        ->and(Transaction::query()->count())->toBe(0);
});

test('a room that needs no approval confirms at once', function (): void {
    $this->room->update(['requires_approval' => false]);

    expect(($this->request)()->status)->toBe(VenueBooking::STATUS_CONFIRMED);
});

test('a team member books on behalf of a staff member, with a note, and may confirm at once', function (): void {
    $user = User::factory()->create();

    $booking = ($this->request)(['enteredBy' => $user, 'confirmNow' => true]);

    expect($booking->entered_by_user_id)->toBe($user->id)
        ->and($booking->requested_for_member_id)->toBe($this->staff->id)
        ->and($booking->status)->toBe(VenueBooking::STATUS_CONFIRMED);
});

test('only the organisation own rooms can be requested, by its own staff', function (): void {
    $other = Tenant::factory()->create(['package_id' => Package::query()->where('slug', 'enterprise')->value('id')]);
    $other->syncFeaturesFromPackage();

    expect(fn () => ($this->request)(['tenant' => $other, 'for' => Member::factory()->create(['tenant_id' => $other->id, 'is_staff' => true])]))->toThrow(ValidationException::class);
    expect(fn () => ($this->request)(['for' => Member::factory()->create(['tenant_id' => Tenant::factory()->create()->id, 'is_staff' => true])]))->toThrow(ValidationException::class);
    expect(fn () => ($this->request)(['for' => Member::factory()->create(['tenant_id' => $this->tenant->id, 'is_staff' => false])]))->toThrow(ValidationException::class);
});

test('an organisation without the feature cannot', function (): void {
    $this->tenant->update(['package_id' => Package::query()->where('slug', 'growth')->value('id')]);
    $this->tenant->syncFeaturesFromPackage();

    expect(fn () => ($this->request)())->toThrow(ValidationException::class);
});

test('8 hours is allowed and 8 hours 1 minute is refused', function (): void {
    expect(($this->request)(['to' => $this->nine->addHours(8)])->status)->toBe(VenueBooking::STATUS_PENDING_APPROVAL);
    expect(fn () => ($this->request)(['from' => $this->nine->addDay(), 'to' => $this->nine->addDay()->addHours(8)->addMinute()]))->toThrow(ValidationException::class);
});

test('day 90 is allowed and day 91 is refused', function (): void {
    $day90 = CarbonImmutable::now()->addDays(90)->setTime(10, 0);

    expect(($this->request)(['from' => $day90, 'to' => $day90->addHour()])->status)->toBe(VenueBooking::STATUS_PENDING_APPROVAL);
    expect(fn () => ($this->request)(['from' => $day90->addDay(), 'to' => $day90->addDay()->addHour()]))->toThrow(ValidationException::class);
});

test('an organisation can change its own limits', function (): void {
    $this->tenant->update(['booking_limits' => ['max_hours' => 2, 'window_days' => 7, 'open_requests' => 1]]);

    expect(fn () => ($this->request)(['to' => $this->nine->addHours(3)]))->toThrow(ValidationException::class);
    expect(fn () => ($this->request)(['from' => $this->nine->addDays(10), 'to' => $this->nine->addDays(10)->addHour()]))->toThrow(ValidationException::class);
    ($this->request)();
    expect(fn () => ($this->request)(['from' => $this->nine->addDay(), 'to' => $this->nine->addDay()->addHour()]))->toThrow(ValidationException::class);
});

test('a time in the past is refused', function (): void {
    expect(fn () => ($this->request)(['from' => CarbonImmutable::now()->subHour(), 'to' => CarbonImmutable::now()->addHour()]))->toThrow(ValidationException::class);
});

test('the sixth open request is refused, and a decided one does not count', function (): void {
    foreach (range(1, 5) as $day) {
        ($this->request)(['from' => $this->nine->addDays($day), 'to' => $this->nine->addDays($day)->addHour()]);
    }

    expect(fn () => ($this->request)(['from' => $this->nine->addDays(9), 'to' => $this->nine->addDays(9)->addHour()]))->toThrow(ValidationException::class);

    app(InternalBookings::class)->decline(VenueBooking::query()->where('kind', 'internal')->first(), User::factory()->create(), 'Room closed that day');

    expect(($this->request)(['from' => $this->nine->addDays(9), 'to' => $this->nine->addDays(9)->addHour()])->status)->toBe(VenueBooking::STATUS_PENDING_APPROVAL);
});

test('an occupied slot is refused, whether a paying booking, a blackout or another request holds it', function (string $status, string $kind): void {
    VenueBooking::factory()->create([
        'tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->room->id,
        'starts_at' => $this->nine->addHour(), 'ends_at' => $this->nine->addHours(3), 'status' => $status, 'kind' => $kind,
    ]);

    expect(fn () => ($this->request)())->toThrow(ValidationException::class);
})->with([
    'confirmed marketplace booking' => [VenueBooking::STATUS_CONFIRMED, VenueBooking::KIND_MARKETPLACE],
    'blackout' => [VenueBooking::STATUS_BLOCKED, VenueBooking::KIND_MARKETPLACE],
    'another staff request' => [VenueBooking::STATUS_PENDING_APPROVAL, VenueBooking::KIND_INTERNAL],
]);

test('approve and decline email the staff member and change the booking once', function (): void {
    $manager = User::factory()->create();
    $approved = ($this->request)();
    $declined = ($this->request)(['from' => $this->nine->addDay(), 'to' => $this->nine->addDay()->addHour()]);

    app(InternalBookings::class)->approve($approved, $manager);
    app(InternalBookings::class)->decline($declined, $manager, 'Closed for maintenance');

    expect($approved->fresh()->status)->toBe(VenueBooking::STATUS_CONFIRMED)
        ->and($declined->fresh()->status)->toBe(VenueBooking::STATUS_DECLINED)
        ->and($declined->fresh()->decline_reason)->toBe('Closed for maintenance');
    Mail::assertQueued(RoomRequestDecided::class, fn (RoomRequestDecided $mail): bool => $mail->hasTo('ama@ugmc.example') && $mail->outcome === 'approved');
    Mail::assertQueued(RoomRequestDecided::class, fn (RoomRequestDecided $mail): bool => $mail->outcome === 'declined' && $mail->reason === 'Closed for maintenance');

    expect(fn () => app(InternalBookings::class)->approve($declined->fresh(), $manager))->toThrow(ValidationException::class);
});

test('a staff member cancels only their own booking, a team member any', function (): void {
    $mine = ($this->request)();
    $theirs = ($this->request)(['from' => $this->nine->addDay(), 'to' => $this->nine->addDay()->addHour(), 'for' => Member::factory()->create(['tenant_id' => $this->tenant->id, 'is_staff' => true])]);
    $stranger = Member::factory()->create(['tenant_id' => $this->tenant->id, 'is_staff' => true]);

    expect(fn () => app(InternalBookings::class)->cancel($mine, $stranger))->toThrow(ValidationException::class);

    app(InternalBookings::class)->cancel($mine, $this->staff);
    app(InternalBookings::class)->cancel($theirs, User::factory()->create());

    expect($mine->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED)->and($theirs->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED);
});

test('two requests for the same slot cannot both succeed', function (): void {
    ($this->request)();

    expect(fn () => ($this->request)(['for' => Member::factory()->create(['tenant_id' => $this->tenant->id, 'is_staff' => true])]))->toThrow(ValidationException::class)
        ->and(VenueBooking::query()->where('kind', 'internal')->count())->toBe(1);
});
