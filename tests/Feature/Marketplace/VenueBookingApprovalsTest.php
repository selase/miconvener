<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Services\Marketplace\VenueBookingApprovals;
use App\Services\Marketplace\VenueBookingService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Queue::fake();
    Mail::fake();
    RateLimiter::clear('venue-request-submissions:'.sha1('buyer@planner.test'));
    $this->tenant = Tenant::factory()->create();
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create([
        'shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_HOUR, 'rental_price_pesewas' => 50000, 'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'capacity_breakdown' => ['banquet' => 100], 'is_bookable' => true,
    ]);
    Artisan::call('venue:enable-requests', ['tenant' => $this->tenant->slug]);

    Permission::query()->firstOrCreate(['name' => 'manage venue', 'guard_name' => 'web'], ['category' => 'venue']);
    $this->host = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->host->givePermissionTo('manage venue');
    app(TenantContext::class)->setTenant($this->tenant);

    $this->approvals = app(VenueBookingApprovals::class);
    $this->from = CarbonImmutable::now()->addDays(10)->setTime(9, 0);
    $this->session = fn (?string $email = 'buyer@planner.test'): Store => tap(new Store('venue-approvals', new ArraySessionHandler(120)), fn (Store $s) => $email !== null && $s->put(proofFor($email)));
    $this->payload = fn (array $overrides = []): array => $overrides + [
        'planner_name' => 'Ama Planner', 'planner_email' => 'buyer@planner.test', 'event_type' => 'Workshop', 'guest_count' => 20, 'layout_style' => 'banquet',
        'starts_at' => $this->from->toIso8601String(), 'ends_at' => $this->from->addHours(3)->toIso8601String(), 'agreed_to_terms' => true,
    ];
    $this->request = fn (array $overrides = [], ?string $key = null, ?StoreListing $listing = null): VenueBooking => $this->approvals->request($listing ?? $this->listing, ($this->payload)($overrides), ($this->session)(), $key ?? (string) Str::uuid());
    $this->blocking = fn (): bool => VenueBooking::query()->where('store_listing_id', $this->listing->id)->blockingCalendar()
        ->overlapping($this->from, $this->from->addHours(3))->exists();
});

describe('requesting', function (): void {
    test('a verified request records an indicative price and never holds the calendar', function (): void {
        $booking = ($this->request)();

        expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL)
            ->and($booking->usesApprovalFlow())->toBeTrue()
            ->and($booking->payment_status)->toBe(VenueBooking::PAYMENT_UNPAID)
            ->and($booking->hold_expires_at)->toBeNull()
            ->and($booking->quote_valid_until)->toBeNull()
            ->and($booking->total_amount_pesewas)->toBeGreaterThan(0)
            ->and($booking->contract_terms_snapshot['policy'])->toMatchArray(['default_hold_hours' => 48, 'max_hold_hours' => 168])
            ->and(($this->blocking)())->toBeFalse();
        expect(VenueBookingAction::query()->where('booking_id', $booking->id)->sole())
            ->action->toBe(VenueBookingAction::ACTION_REQUESTED)
            ->actor_type->toBe(VenueBookingAction::ACTOR_BUYER)
            ->buyer_email->toBe('buyer@planner.test')
            ->result_status->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('a price-on-request venue enters the same approval flow', function (): void {
        $this->listing->update(['price_visibility' => StoreListing::PRICE_VISIBILITY_ON_REQUEST, 'rental_price_pesewas' => 0]);

        $booking = ($this->request)();

        expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL)
            ->and(str_starts_with($booking->booking_reference, 'VRQ-'))->toBeTrue();
    });

    test('overlapping pending requests are all accepted', function (): void {
        ($this->request)(['planner_name' => 'First']);
        $second = $this->approvals->request($this->listing, ($this->payload)(['planner_email' => 'other@planner.test']), ($this->session)('other@planner.test'), (string) Str::uuid());

        expect($second->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('dates that are already taken are refused up front', function (): void {
        VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'starts_at' => $this->from, 'ends_at' => $this->from->addHours(3)]);

        expect(fn () => ($this->request)())->toThrow(ValidationException::class);
    });

    test('refuses an unverified or different email, an offset-less time and a past start', function (array $overrides, ?string $sessionEmail): void {
        $call = fn () => $this->approvals->request($this->listing, ($this->payload)($overrides), ($this->session)($sessionEmail), (string) Str::uuid());

        expect($call)->toThrow(ValidationException::class)
            ->and(VenueBooking::query()->count())->toBe(0);
    })->with([
        'no proof' => [[], null],
        'someone else\'s proof' => [[], 'stranger@planner.test'],
        'time without an offset' => [['starts_at' => '2030-05-01 09:00', 'ends_at' => '2030-05-01 12:00'], 'buyer@planner.test'],
        'start in the past' => [['starts_at' => '2020-05-01T09:00:00+00:00', 'ends_at' => '2020-05-01T12:00:00+00:00'], 'buyer@planner.test'],
        'end before start' => [['ends_at' => '2020-05-01T12:00:00+00:00'], 'buyer@planner.test'],
        'more guests than the space holds' => [['guest_count' => 5000], 'buyer@planner.test'],
    ]);

    test('refuses venues that are not open for requests', function (Closure $close): void {
        $close($this);

        expect(fn () => ($this->request)())->toThrow(ValidationException::class);
    })->with([
        'feature off' => [fn ($t) => Artisan::call('venue:enable-requests', ['tenant' => $t->tenant->slug, '--off' => true])],
        'not bookable' => [fn ($t) => $t->listing->update(['is_bookable' => false])],
        'unpublished' => [fn ($t) => $t->listing->update(['status' => StoreListing::STATUS_DRAFT])],
        'inactive shop' => [fn ($t) => $t->shop->update(['is_active' => false])],
        'policy turned approval off' => [fn ($t) => $t->listing->update(['public_booking_policy' => ['approval_required' => false]])],
        'merchandise' => [fn ($t) => $t->listing->update(['listing_kind' => StoreListing::KIND_MERCHANDISE])],
    ]);

    test('a staff-only room cannot be requested publicly', function (): void {
        $this->listing->update(['audience' => StoreListing::AUDIENCE_STAFF]);

        expect(fn () => ($this->request)())->toThrow(ValidationException::class);
    });

    test('a repeated key returns the original request and a changed payload under that key is refused', function (): void {
        $key = (string) Str::uuid();
        $first = ($this->request)([], $key);
        $again = ($this->request)([], $key);

        expect($again->id)->toBe($first->id)
            ->and(VenueBooking::query()->count())->toBe(1)
            ->and(VenueBookingAction::query()->count())->toBe(1)
            ->and(fn () => ($this->request)(['guest_count' => 21], $key))->toThrow(ValidationException::class);
    });

    test('one verified email is limited to ten submissions an hour', function (): void {
        foreach (range(1, 10) as $day) {
            $at = $this->from->addDays($day)->toIso8601String();
            $this->approvals->request($this->listing, ($this->payload)(['starts_at' => $at, 'ends_at' => $this->from->addDays($day)->addHours(2)->toIso8601String()]), ($this->session)(), (string) Str::uuid());
        }

        expect(fn () => ($this->request)(['starts_at' => $this->from->addDays(20)->toIso8601String(), 'ends_at' => $this->from->addDays(20)->addHours(2)->toIso8601String()]))->toThrow(ValidationException::class);
    });

    test('a later policy edit does not change an existing request\'s snapshot', function (): void {
        $booking = ($this->request)();
        $this->listing->update(['public_booking_policy' => ['default_hold_hours' => 1, 'max_hold_hours' => 2, 'timezone' => 'Africa/Accra']]);

        expect($booking->fresh()->contract_terms_snapshot['policy'])->toMatchArray(['default_hold_hours' => 48, 'max_hold_hours' => 168]);
    });
});

describe('host decisions', function (): void {
    test('approval holds exactly the requested hours and blocks competing writers', function (): void {
        $this->freezeTime();
        $booking = ($this->request)();

        $held = $this->approvals->approve($booking, $this->host, 24, (string) Str::uuid());

        expect($held->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->and($held->fresh()->hold_expires_at->toDateTimeString())->toBe(now()->addHours(24)->toDateTimeString())
            ->and(($this->blocking)())->toBeTrue()
            ->and(fn () => app(VenueBookingService::class)->createBooking($this->listing, ['planner_name' => 'x', 'planner_email' => 'x@y.test', 'starts_at' => $this->from->toDateTimeString(), 'ends_at' => $this->from->addHours(3)->toDateTimeString(), 'guest_count' => 5]))->toThrow(ValidationException::class);
        expect(VenueBookingAction::query()->where('booking_id', $booking->id)->where('action', VenueBookingAction::ACTION_APPROVED)->sole())
            ->actor_id->toBe((int) $this->host->id)
            ->previous_status->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('hold hours must be between one and the policy maximum', function (int $hours): void {
        $booking = ($this->request)();

        expect(fn () => $this->approvals->approve($booking, $this->host, $hours, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    })->with([0, -1, 169, 10000]);

    test('only one of two competing requests can be approved', function (): void {
        $first = ($this->request)();
        $second = $this->approvals->request($this->listing, ($this->payload)(['planner_email' => 'other@planner.test']), ($this->session)('other@planner.test'), (string) Str::uuid());

        $this->approvals->approve($first, $this->host, 48, (string) Str::uuid());

        expect(fn () => $this->approvals->approve($second, $this->host, 48, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and($second->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL)
            ->and(VenueBookingAction::query()->where('booking_id', $second->id)->where('action', VenueBookingAction::ACTION_APPROVED)->exists())->toBeFalse();
    });

    test('an approval cannot take dates an existing legacy hold or paid booking occupies', function (string $status): void {
        $request = ($this->request)();
        VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'status' => $status, 'quote_valid_until' => now()->addHour(), 'starts_at' => $this->from, 'ends_at' => $this->from->addHours(3)]);

        expect(fn () => $this->approvals->approve($request, $this->host, 24, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and($request->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    })->with([VenueBooking::STATUS_PENDING_PAYMENT, VenueBooking::STATUS_CONFIRMED, VenueBooking::STATUS_BLOCKED, VenueBooking::STATUS_PENDING_APPROVAL]);

    test('replaying an approval after more time has passed returns the same hold and does not extend it', function (): void {
        $this->freezeTime();
        $booking = ($this->request)();
        $key = (string) Str::uuid();
        $first = $this->approvals->approve($booking, $this->host, 24, $key);
        $deadline = $first->fresh()->hold_expires_at->toDateTimeString();

        $this->travel(5)->hours();
        $again = $this->approvals->approve($booking->fresh(), $this->host, 24, $key);

        expect($again->hold_expires_at->toDateTimeString())->toBe($deadline)
            ->and(VenueBookingAction::query()->where('booking_id', $booking->id)->where('action', VenueBookingAction::ACTION_APPROVED)->count())->toBe(1);
    });

    test('replaying an approval key after the request moved on is an explicit conflict, not a success', function (): void {
        $booking = ($this->request)();
        $key = (string) Str::uuid();
        $this->approvals->approve($booking, $this->host, 24, $key);
        $this->approvals->release($booking->fresh(), $this->host, 'Double booked', (string) Str::uuid());

        expect(fn () => $this->approvals->approve($booking->fresh(), $this->host, 24, $key))->toThrow(ValidationException::class)
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED)
            ->and(VenueBookingAction::query()->where('booking_id', $booking->id)->where('action', VenueBookingAction::ACTION_APPROVED)->count())->toBe(1);
    });

    test('replaying an approval key after a renewal is a conflict too', function (): void {
        $this->freezeTime();
        $booking = ($this->request)();
        $key = (string) Str::uuid();
        $this->approvals->approve($booking, $this->host, 2, $key);
        $this->travel(3)->hours();
        $this->approvals->renew($booking->fresh(), $this->host, 2, (string) Str::uuid());

        expect(fn () => $this->approvals->approve($booking->fresh(), $this->host, 2, $key))->toThrow(ValidationException::class);
    });

    test('an approval key reused for a different decision is refused', function (): void {
        $booking = ($this->request)();
        $key = (string) Str::uuid();
        $this->approvals->approve($booking, $this->host, 24, $key);

        expect(fn () => $this->approvals->approve($booking->fresh(), $this->host, 72, $key))->toThrow(ValidationException::class);
    });

    test('the hold ends at its exact deadline without any scheduler', function (): void {
        $this->freezeTime();
        $held = $this->approvals->approve(($this->request)(), $this->host, 2, (string) Str::uuid());

        $this->travelTo($held->fresh()->hold_expires_at->copy()->subSecond());
        expect(($this->blocking)())->toBeTrue();

        $this->travelTo($held->fresh()->hold_expires_at);
        expect(($this->blocking)())->toBeFalse()
            ->and($held->fresh()->effectiveStatus())->toBe(VenueBooking::STATUS_HOLD_EXPIRED);
    });

    test('an expired hold can be renewed once its dates are still free, and not before', function (): void {
        $this->freezeTime();
        $held = $this->approvals->approve(($this->request)(), $this->host, 2, (string) Str::uuid());

        expect(fn () => $this->approvals->renew($held->fresh(), $this->host, 24, (string) Str::uuid()))->toThrow(ValidationException::class);

        $this->travel(3)->hours();
        $key = (string) Str::uuid();
        $renewed = $this->approvals->renew($held->fresh(), $this->host, 24, $key);

        expect($renewed->hold_expires_at->toDateTimeString())->toBe(now()->addHours(24)->toDateTimeString())
            ->and(($this->blocking)())->toBeTrue();

        $this->travel(1)->hours();
        expect($this->approvals->renew($held->fresh(), $this->host, 24, $key)->hold_expires_at->toDateTimeString())->toBe(now()->subHour()->addHours(24)->toDateTimeString());
    });

    test('an expired hold cannot be renewed over dates someone else has since taken', function (): void {
        $this->freezeTime();
        $held = $this->approvals->approve(($this->request)(), $this->host, 2, (string) Str::uuid());
        $this->travel(3)->hours();
        VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'starts_at' => $this->from, 'ends_at' => $this->from->addHours(3)]);

        expect(fn () => $this->approvals->renew($held->fresh(), $this->host, 24, (string) Str::uuid()))->toThrow(ValidationException::class);
    });

    test('declining and releasing are final and cannot be resurrected', function (): void {
        $declined = $this->approvals->decline(($this->request)(), $this->host, 'Closed for repairs', (string) Str::uuid());
        $released = $this->approvals->release($this->approvals->approve(($this->request)(['starts_at' => $this->from->addDays(2)->toIso8601String(), 'ends_at' => $this->from->addDays(2)->addHours(3)->toIso8601String()]), $this->host, 48, (string) Str::uuid()), $this->host, 'Changed plans', (string) Str::uuid());

        expect($declined->status)->toBe(VenueBooking::STATUS_DECLINED)
            ->and($declined->decline_reason)->toBe('Closed for repairs')
            ->and($released->status)->toBe(VenueBooking::STATUS_CANCELLED)
            ->and(fn () => $this->approvals->approve($declined->fresh(), $this->host, 48, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => $this->approvals->renew($released->fresh(), $this->host, 48, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => $this->approvals->release($declined->fresh(), $this->host, 'again', (string) Str::uuid()))->toThrow(ValidationException::class);
    });

    test('releasing a hold frees the dates', function (): void {
        $held = $this->approvals->approve(($this->request)(), $this->host, 48, (string) Str::uuid());
        expect(($this->blocking)())->toBeTrue();

        $this->approvals->release($held, $this->host, 'Changed plans', (string) Str::uuid());

        expect(($this->blocking)())->toBeFalse();
    });

    test('a decision reason is required and bounded', function (string $reason): void {
        $booking = ($this->request)();

        expect(fn () => $this->approvals->decline($booking, $this->host, $reason, (string) Str::uuid()))->toThrow(ValidationException::class);
    })->with(['', '   ', fn () => str_repeat('x', 1001)]);
});

describe('buyer cancellation', function (): void {
    test('the verified requester can cancel an open request or hold', function (): void {
        $booking = ($this->request)();
        $held = $this->approvals->approve(($this->request)(['starts_at' => $this->from->addDays(2)->toIso8601String(), 'ends_at' => $this->from->addDays(2)->addHours(3)->toIso8601String()]), $this->host, 48, (string) Str::uuid());

        expect($this->approvals->cancelByBuyer($booking, ($this->session)(), (string) Str::uuid())->status)->toBe(VenueBooking::STATUS_CANCELLED)
            ->and($this->approvals->cancelByBuyer($held, ($this->session)(), (string) Str::uuid())->status)->toBe(VenueBooking::STATUS_CANCELLED);
    });

    test('someone else, or an unverified session, cannot cancel', function (?string $email): void {
        $booking = ($this->request)();

        expect(fn () => $this->approvals->cancelByBuyer($booking, ($this->session)($email), (string) Str::uuid()))->toThrow(AuthorizationException::class)
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    })->with([[null], ['stranger@planner.test']]);

    test('cancelling twice with the same key is a safe replay', function (): void {
        $booking = ($this->request)();
        $key = (string) Str::uuid();

        $this->approvals->cancelByBuyer($booking, ($this->session)(), $key);
        $this->approvals->cancelByBuyer($booking->fresh(), ($this->session)(), $key);

        expect(VenueBookingAction::query()->where('booking_id', $booking->id)->where('action', VenueBookingAction::ACTION_CANCELLED)->count())->toBe(1);
    });
});

describe('who may decide', function (): void {
    test('an actor from another organisation or without the permission is refused', function (): void {
        $booking = ($this->request)();
        $outsider = User::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id]);

        expect(fn () => $this->approvals->approve($booking, $outsider, 24, (string) Str::uuid()))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->approvals->approve($booking, $plain, 24, (string) Str::uuid()))->toThrow(AuthorizationException::class)
            ->and(fn () => $this->approvals->decline($booking, $plain, 'No', (string) Str::uuid()))->toThrow(AuthorizationException::class)
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('a decision made while another organisation is current is refused', function (): void {
        $booking = ($this->request)();
        app(TenantContext::class)->setTenant(Tenant::factory()->create());

        expect(fn () => $this->approvals->approve($booking, $this->host, 24, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    });

    test('turning the feature off stops approvals and renewals but not declines, releases or cancellations', function (): void {
        $pending = ($this->request)();
        $held = $this->approvals->approve(($this->request)(['starts_at' => $this->from->addDays(2)->toIso8601String(), 'ends_at' => $this->from->addDays(2)->addHours(3)->toIso8601String()]), $this->host, 48, (string) Str::uuid());
        Artisan::call('venue:enable-requests', ['tenant' => $this->tenant->slug, '--off' => true]);

        expect(fn () => $this->approvals->approve($pending, $this->host, 24, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and($this->approvals->decline($pending->fresh(), $this->host, 'Paused', (string) Str::uuid())->status)->toBe(VenueBooking::STATUS_DECLINED)
            ->and($this->approvals->release($held->fresh(), $this->host, 'Paused', (string) Str::uuid())->status)->toBe(VenueBooking::STATUS_CANCELLED);
    });

    test('a legacy booking cannot be driven through the approval transitions', function (): void {
        $legacy = VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id, 'status' => VenueBooking::STATUS_PENDING_QUOTE]);

        expect(fn () => $this->approvals->approve($legacy, $this->host, 24, (string) Str::uuid()))->toThrow(ValidationException::class)
            ->and(fn () => $this->approvals->decline($legacy, $this->host, 'No', (string) Str::uuid()))->toThrow(ValidationException::class);
    });
});

describe('the legacy payment paths', function (): void {
    test('cannot start checkout for or confirm a payment against an approval-flow booking', function (): void {
        $held = $this->approvals->approve(($this->request)(), $this->host, 48, (string) Str::uuid());
        $service = app(VenueBookingService::class);

        expect(fn () => $service->initializeDepositCheckout($held, 'https://example.test/cb'))->toThrow(RuntimeException::class);

        $result = $service->confirmBookingPayment('pstk_forged', ['amount' => 50000, 'fees' => 0, 'metadata' => ['booking_reference' => $held->booking_reference]]);

        expect($result->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->and($result->paystack_reference)->toBeNull()
            ->and($result->payment_status)->toBe(VenueBooking::PAYMENT_UNPAID)
            ->and(VenueBookingAction::query()->where('booking_id', $held->id)->where('action', VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT)->sole()->decision_reason)->toBe('approval_flow_no_payment');
    });

    test('the legacy host shortcuts refuse approval-flow bookings', function (): void {
        $booking = ($this->request)();
        $service = app(VenueBookingService::class);

        expect(fn () => $service->placeHostHold($this->shop, $booking->id))->toThrow(ValidationException::class)
            ->and(fn () => $service->quoteAsHost($this->shop, $booking->id, 1000, 0, 1000, null))->toThrow(ValidationException::class)
            ->and(fn () => $service->rejectAsHost($this->shop, $booking->id, 'x'))->toThrow(ValidationException::class)
            ->and($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });
});
