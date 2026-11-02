<?php

declare(strict_types=1);

use App\Enum\TenantStatusEnum;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Services\Marketplace\VenueRequestRollout;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    refreshTenantDatabases();
});

function approvalHold(StoreListing $listing, string $status, ?DateTimeInterface $expires, string $startsAt = '2030-01-10 09:00', string $endsAt = '2030-01-10 11:00'): VenueBooking
{
    return VenueBooking::factory()->create([
        'tenant_id' => $listing->shop->tenant_id,
        'shop_id' => $listing->shop_id,
        'store_listing_id' => $listing->id,
        'status' => $status,
        'approval_flow_version' => 1,
        'hold_expires_at' => $expires,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
    ]);
}

test('existing bookings stay legacy with no hold or submission key', function (): void {
    $booking = VenueBooking::factory()->create();

    expect($booking->fresh()->approval_flow_version)->toBeNull()
        ->and($booking->fresh()->hold_expires_at)->toBeNull()
        ->and($booking->fresh()->submission_key)->toBeNull()
        ->and($booking->fresh()->usesApprovalFlow())->toBeFalse()
        ->and(VenueBooking::STATUS_PENDING_HOST_APPROVAL)->toBe('pending_host_approval')
        ->and(VenueBooking::STATUS_APPROVED_HOLD)->toBe('approved_hold');
});

test('a listing without a stored policy reports the approval-first defaults', function (): void {
    $listing = StoreListing::factory()->create();

    expect($listing->fresh()->publicBookingPolicy())->toBe([
        'approval_required' => true,
        'default_hold_hours' => 48,
        'max_hold_hours' => 168,
        'timezone' => config('app.timezone'),
    ]);
});

test('a stored policy overrides only the keys it names', function (): void {
    $listing = StoreListing::factory()->create(['public_booking_policy' => ['default_hold_hours' => 24, 'timezone' => 'Africa/Accra']]);

    expect($listing->fresh()->publicBookingPolicy())->toMatchArray([
        'approval_required' => true,
        'default_hold_hours' => 24,
        'max_hold_hours' => 168,
        'timezone' => 'Africa/Accra',
    ]);
});

test('venue requests are off until the explicit feature row is on', function (): void {
    $tenant = Tenant::factory()->create();
    Shop::factory()->create(['tenant_id' => $tenant->id]);
    $rollout = app(VenueRequestRollout::class);

    expect($rollout->enabled($tenant))->toBeFalse();

    $this->artisan('venue:enable-requests', ['tenant' => $tenant->slug])->assertSuccessful();
    expect($rollout->enabled($tenant->fresh()))->toBeTrue();

    $this->artisan('venue:enable-requests', ['tenant' => $tenant->slug, '--off' => true])->assertSuccessful();
    expect($rollout->enabled($tenant->fresh()))->toBeFalse();
});

test('enabling one organisation does not enable another and unknown organisations fail', function (): void {
    $on = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    Shop::factory()->create(['tenant_id' => $on->id]);
    Shop::factory()->create(['tenant_id' => $other->id]);

    $this->artisan('venue:enable-requests', ['tenant' => $on->slug])->assertSuccessful();
    $this->artisan('venue:enable-requests', ['tenant' => 'no-such-organisation'])->assertFailed();

    expect(app(VenueRequestRollout::class)->enabled($on->fresh()))->toBeTrue()
        ->and(app(VenueRequestRollout::class)->enabled($other->fresh()))->toBeFalse();
});

test('an inactive organisation or one without a shop cannot take requests even when the feature is on', function (): void {
    $deactivated = Tenant::factory()->create(['status' => TenantStatusEnum::DEACTIVATED]);
    $shopless = Tenant::factory()->create();
    Shop::factory()->create(['tenant_id' => $deactivated->id]);

    $this->artisan('venue:enable-requests', ['tenant' => $deactivated->slug])->assertSuccessful();
    $this->artisan('venue:enable-requests', ['tenant' => $shopless->slug])->assertSuccessful();

    expect(app(VenueRequestRollout::class)->enabled($deactivated->fresh()))->toBeFalse()
        ->and(app(VenueRequestRollout::class)->enabled($shopless->fresh()))->toBeFalse();
});

test('action evidence is unique per booking and operation key', function (): void {
    $booking = VenueBooking::factory()->create();
    $key = (string) Str::uuid();

    VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'operation_key' => $key]);

    expect(fn () => DB::connection('landlord')->transaction(fn () => VenueBookingAction::factory()->create(['booking_id' => $booking->id, 'operation_key' => $key])))
        ->toThrow(QueryException::class);
});

test('action evidence cannot be edited or deleted but delivery metadata can change', function (): void {
    $action = VenueBookingAction::factory()->create();

    $guarded = fn (Closure $change) => expect(fn () => DB::connection('landlord')->transaction($change))->toThrow(QueryException::class);
    $guarded(fn () => VenueBookingAction::query()->findOrFail($action->id)->update(['decision_reason' => 'rewritten']));
    $guarded(fn () => VenueBookingAction::query()->findOrFail($action->id)->update(['hold_expires_at' => now()->addDay()]));
    $guarded(fn () => VenueBookingAction::query()->findOrFail($action->id)->delete());

    VenueBookingAction::query()->findOrFail($action->id)->update(['delivery_state' => 'sent', 'delivery_attempts' => 1, 'delivery_claimed_at' => now()]);

    expect($action->fresh()->delivery_state)->toBe('sent')
        ->and($action->fresh()->delivery_attempts)->toBe(1);
});

test('a request is unique per shop and submission key', function (): void {
    $key = (string) Str::uuid();
    $first = VenueBooking::factory()->create(['submission_key' => $key]);

    expect(fn () => DB::connection('landlord')->transaction(fn () => VenueBooking::factory()->create(['shop_id' => $first->shop_id, 'tenant_id' => $first->tenant_id, 'store_listing_id' => $first->store_listing_id, 'submission_key' => $key])))
        ->toThrow(QueryException::class);

    VenueBooking::factory()->create(['submission_key' => null]);
    VenueBooking::factory()->create(['submission_key' => null]);
    expect(VenueBooking::query()->whereNull('submission_key')->count())->toBeGreaterThanOrEqual(2);
});

test('a pending public request never holds the calendar', function (): void {
    $listing = StoreListing::factory()->create();
    approvalHold($listing, VenueBooking::STATUS_PENDING_HOST_APPROVAL, null);

    expect(VenueBooking::query()->where('store_listing_id', $listing->id)->blockingCalendar()->exists())->toBeFalse();
});

test('an approved hold blocks only until the exact expiry', function (): void {
    $this->freezeTime();
    $listing = StoreListing::factory()->create();
    $hold = approvalHold($listing, VenueBooking::STATUS_APPROVED_HOLD, now()->addMinute());
    $blocking = fn (): bool => VenueBooking::query()->where('store_listing_id', $listing->id)->blockingCalendar()->exists();

    expect($blocking())->toBeTrue();

    $this->travelTo($hold->hold_expires_at->copy()->subSecond());
    expect($blocking())->toBeTrue();

    $this->travelTo($hold->hold_expires_at);
    expect($blocking())->toBeFalse()
        ->and($hold->fresh()->isHoldExpired())->toBeTrue()
        ->and($hold->fresh()->effectiveStatus())->toBe(VenueBooking::STATUS_HOLD_EXPIRED);
});

test('an approved hold without a deadline never blocks', function (): void {
    $listing = StoreListing::factory()->create();
    approvalHold($listing, VenueBooking::STATUS_APPROVED_HOLD, null);

    expect(VenueBooking::query()->where('store_listing_id', $listing->id)->blockingCalendar()->exists())->toBeFalse();
});

test('adjacent intervals do not overlap and a one minute overlap does', function (): void {
    $this->freezeTime();
    $listing = StoreListing::factory()->create();
    approvalHold($listing, VenueBooking::STATUS_APPROVED_HOLD, now()->addDay(), '2030-01-10 09:00', '2030-01-10 11:00');
    $overlapping = fn (string $from, string $to): bool => VenueBooking::query()->where('store_listing_id', $listing->id)->blockingCalendar()->overlapping(Carbon\Carbon::parse($from), Carbon\Carbon::parse($to))->exists();

    expect($overlapping('2030-01-10 11:00', '2030-01-10 13:00'))->toBeFalse()
        ->and($overlapping('2030-01-10 07:00', '2030-01-10 09:00'))->toBeFalse()
        ->and($overlapping('2030-01-10 10:59', '2030-01-10 13:00'))->toBeTrue();
});

test('legacy and staff holds keep their existing calendar semantics', function (): void {
    $listing = StoreListing::factory()->create();
    $base = ['tenant_id' => $listing->shop->tenant_id, 'shop_id' => $listing->shop_id, 'store_listing_id' => $listing->id, 'starts_at' => '2030-02-01 09:00', 'ends_at' => '2030-02-01 11:00'];
    VenueBooking::factory()->create($base + ['status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => null]);
    VenueBooking::factory()->create(['starts_at' => '2030-02-02 09:00', 'ends_at' => '2030-02-02 11:00'] + $base + ['status' => VenueBooking::STATUS_PENDING_PAYMENT, 'quote_valid_until' => now()->subMinute()]);
    VenueBooking::factory()->create(['starts_at' => '2030-02-03 09:00', 'ends_at' => '2030-02-03 11:00'] + $base + ['status' => VenueBooking::STATUS_PENDING_APPROVAL, 'kind' => VenueBooking::KIND_INTERNAL]);

    $on = fn (string $day): bool => VenueBooking::query()->where('store_listing_id', $listing->id)->blockingCalendar()->overlapping(Carbon\Carbon::parse("2030-02-{$day} 00:00"), Carbon\Carbon::parse("2030-02-{$day} 23:59"))->exists();

    expect($on('01'))->toBeTrue()
        ->and($on('02'))->toBeFalse()
        ->and($on('03'))->toBeTrue();
});

test('the registered feature appears in the admin feature list and survives a plan sync', function (): void {
    $superadmin = User::factory()->create();
    $superadmin->assignRole(Role::create(['name' => 'Superadmin', 'guard_name' => 'web']));
    $tenant = Tenant::factory()->create();
    Shop::factory()->create(['tenant_id' => $tenant->id]);

    $this->artisan('venue:enable-requests', ['tenant' => $tenant->slug])->assertSuccessful();
    $tenant->syncFeaturesFromPackage();

    expect(app(VenueRequestRollout::class)->enabled($tenant->fresh()))->toBeTrue();
    $this->actingAs($superadmin)->getJson(route('features.index'), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()->assertJsonFragment(['slug' => VenueRequestRollout::FEATURE]);
});

test('a plan sync never turns requests on by itself', function (): void {
    $tenant = Tenant::factory()->create();
    Shop::factory()->create(['tenant_id' => $tenant->id]);

    $tenant->syncFeaturesFromPackage();

    expect(app(VenueRequestRollout::class)->enabled($tenant->fresh()))->toBeFalse();
});
