<?php

declare(strict_types=1);

use App\Jobs\DeliverVenueApprovalNotice;
use App\Mail\Marketplace\VenueApprovalNotice;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    RateLimiter::clear('venue-request-submissions:'.sha1('buyer@planner.test'));

    $this->tenant = Tenant::factory()->create(['slug' => 'host-hotel', 'isolation_mode' => 'shared']);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $this->admin->assignRole('Org Admin');
    $this->tenant->users()->attach($this->admin->id);
    $this->shop = Shop::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
    $this->listing = StoreListing::factory()->create([
        'shop_id' => $this->shop->id, 'listing_kind' => StoreListing::KIND_VENUE, 'status' => StoreListing::STATUS_PUBLISHED,
        'pricing_model' => StoreListing::PRICING_MODEL_PER_HOUR, 'rental_price_pesewas' => 50000, 'price_visibility' => StoreListing::PRICE_VISIBILITY_PUBLIC,
        'capacity_breakdown' => ['banquet' => 100], 'is_bookable' => true,
    ]);
    Artisan::call('venue:enable-requests', ['tenant' => $this->tenant->slug]);

    $this->host = "{$this->tenant->slug}.".mb_ltrim((string) config('session.domain'), '.');
    $this->from = CarbonImmutable::now()->addDays(10)->setTime(9, 0);
    $this->body = fn (array $overrides = []): array => $overrides + [
        'planner_name' => 'Ama Planner', 'planner_email' => 'buyer@planner.test', 'event_type' => 'Workshop', 'guest_count' => 20, 'layout_style' => 'banquet',
        'starts_at' => $this->from->toIso8601String(), 'ends_at' => $this->from->addHours(3)->toIso8601String(), 'agreed_to_terms' => '1',
        'operation_key' => (string) Str::uuid(),
    ];
    $this->asBuyer = fn (string $email = 'buyer@planner.test') => $this->withSession(proofFor($email));
    $this->submit = fn (array $overrides = []) => ($this->asBuyer)()->post(route('marketplace.venues.request', $this->listing->slug), ($this->body)($overrides));
    $this->pending = function (): VenueBooking {
        ($this->submit)();

        return VenueBooking::query()->where('approval_flow_version', 1)->latest('created_at')->firstOrFail();
    };
    $this->hostPost = fn (string $action, VenueBooking $booking, array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->admin)
        ->post("http://{$this->host}/venue/inquiries/{$booking->id}/{$action}", $data + ['operation_key' => (string) Str::uuid()], ['HTTP_HOST' => $this->host]);
});

describe('requesting over HTTP', function (): void {
    test('a verified visitor can request and lands on their private request page', function (): void {
        $response = ($this->submit)();
        $booking = VenueBooking::query()->where('approval_flow_version', 1)->sole();

        $response->assertRedirect(route('marketplace.bookings.show', $booking->booking_reference));
        expect($booking->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('an unverified visitor cannot request', function (): void {
        $response = $this->post(route('marketplace.venues.request', $this->listing->slug), ($this->body)());

        $response->assertSessionHasErrors('planner_email');
        expect(VenueBooking::query()->count())->toBe(0);
    });

    test('a visitor verified as someone else cannot request in another person\'s name', function (): void {
        ($this->asBuyer)('stranger@planner.test')->post(route('marketplace.venues.request', $this->listing->slug), ($this->body)())
            ->assertSessionHasErrors('planner_email');

        expect(VenueBooking::query()->count())->toBe(0);
    });

    test('submitting twice with the same key makes one request', function (): void {
        $key = (string) Str::uuid();

        ($this->submit)(['operation_key' => $key]);
        ($this->submit)(['operation_key' => $key]);

        expect(VenueBooking::query()->count())->toBe(1);
    });

    test('a request needs an operation key and offset timestamps', function (array $overrides): void {
        ($this->submit)($overrides)->assertSessionHasErrors();

        expect(VenueBooking::query()->count())->toBe(0);
    })->with([
        'no key' => [['operation_key' => '']],
        'not a uuid' => [['operation_key' => 'abc']],
        'no offset' => [['starts_at' => '2031-01-01 09:00', 'ends_at' => '2031-01-01 12:00']],
    ]);

    test('the old pay-now route no longer books a venue that takes approval-first requests', function (): void {
        $this->post(route('marketplace.venues.book', $this->listing->slug), [
            'planner_name' => 'Ama', 'planner_email' => 'buyer@planner.test', 'event_type' => 'Workshop', 'guest_count' => 20, 'layout_style' => 'banquet',
            'starts_at' => $this->from->toDateTimeString(), 'ends_at' => $this->from->addHours(3)->toDateTimeString(), 'agreed_to_terms' => '1',
        ])->assertSessionHasErrors('starts_at');

        expect(VenueBooking::query()->count())->toBe(0);
    });

    test('a venue that has not opted in still books the old way', function (): void {
        Artisan::call('venue:enable-requests', ['tenant' => $this->tenant->slug, '--off' => true]);

        $this->post(route('marketplace.venues.book', $this->listing->slug), [
            'planner_name' => 'Ama', 'planner_email' => 'buyer@planner.test', 'event_type' => 'Workshop', 'guest_count' => 20, 'layout_style' => 'banquet',
            'starts_at' => $this->from->toDateTimeString(), 'ends_at' => $this->from->addHours(3)->toDateTimeString(), 'agreed_to_terms' => '1', 'request_quote_only' => '1',
        ]);

        expect(VenueBooking::query()->sole()->approval_flow_version)->toBeNull();
    });
});

describe('the private request page', function (): void {
    test('opens only for the verified requester, with no internal notes', function (): void {
        $booking = ($this->pending)();
        $booking->update(['host_notes' => 'internal secret note']);

        ($this->asBuyer)()->get(route('marketplace.bookings.show', $booking->booking_reference))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Marketplace/Bookings/Show')
                ->where('booking.status', VenueBooking::STATUS_PENDING_HOST_APPROVAL)
                ->where('booking.approval.flow', true)
                ->where('booking.approval.can_cancel', true)
                ->where('booking.approval.timezone', config('app.timezone'))
                ->missing('booking.host_notes'));
    });

    test('sends an unverified visitor to verify and come back', function (): void {
        $booking = ($this->pending)();
        $path = route('marketplace.bookings.show', $booking->booking_reference, false);
        $this->flushSession();

        $this->get($path)->assertRedirect('/my?return_to='.rawurlencode($path));
    });

    test('shows a requester who is someone else nothing at all', function (): void {
        $booking = ($this->pending)();

        ($this->asBuyer)('stranger@planner.test')->get(route('marketplace.bookings.show', $booking->booking_reference))->assertNotFound();
    });

    test('leaves legacy booking pages open exactly as before', function (): void {
        $legacy = VenueBooking::factory()->create(['tenant_id' => $this->tenant->id, 'shop_id' => $this->shop->id, 'store_listing_id' => $this->listing->id]);

        $this->get(route('marketplace.bookings.show', $legacy->booking_reference))->assertOk();
    });

    test('shows the hold deadline and an expired state', function (): void {
        $this->freezeTime();
        $booking = ($this->pending)();
        ($this->hostPost)('approve', $booking, ['hold_hours' => 2]);

        ($this->asBuyer)()->get(route('marketplace.bookings.show', $booking->booking_reference))
            ->assertInertia(fn (Assert $page) => $page->where('booking.approval.hold_expires_at', now()->addHours(2)->toIso8601String())->where('booking.approval.effective_status', VenueBooking::STATUS_APPROVED_HOLD));

        $this->travel(3)->hours();
        ($this->asBuyer)()->get(route('marketplace.bookings.show', $booking->booking_reference))
            ->assertInertia(fn (Assert $page) => $page->where('booking.approval.effective_status', VenueBooking::STATUS_HOLD_EXPIRED));
    });

    test('lets the requester cancel and no one else', function (): void {
        $booking = ($this->pending)();
        $cancel = fn (string $email) => ($this->asBuyer)($email)->post(route('marketplace.bookings.cancel', $booking->booking_reference), ['operation_key' => (string) Str::uuid()]);

        $cancel('stranger@planner.test')->assertNotFound();
        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);

        $cancel('buyer@planner.test')->assertRedirect();
        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED);
    });
});

describe('the public calendar', function (): void {
    test('shows busy times only, never a pending request, contact or reason', function (): void {
        $pending = ($this->pending)();
        $held = ($this->pending)();
        $held->update(['starts_at' => $this->from->addDays(2), 'ends_at' => $this->from->addDays(2)->addHours(3)]);
        ($this->hostPost)('approve', $held, ['hold_hours' => 24]);

        $slots = $this->getJson(route('marketplace.venues.booked-slots', $this->listing->slug))->assertOk()->json('slots');

        expect($slots)->toHaveCount(1)
            ->and(array_keys($slots[0]))->toEqualCanonicalizing(['starts_at', 'ends_at', 'status'])
            ->and($slots[0]['status'])->toBe(VenueBooking::STATUS_PENDING_PAYMENT)
            ->and(json_encode($slots))->not->toContain('planner')->not->toContain($pending->booking_reference);
    });
});

describe('host decisions over HTTP', function (): void {
    test('an organisation admin approves, and the dates are then held', function (): void {
        $booking = ($this->pending)();

        ($this->hostPost)('approve', $booking, ['hold_hours' => 24])->assertRedirect();

        expect($booking->fresh())
            ->status->toBe(VenueBooking::STATUS_APPROVED_HOLD)
            ->hold_expires_at->not->toBeNull();
    });

    test('hold hours default to the policy snapshot when omitted', function (): void {
        $this->freezeTime();
        $booking = ($this->pending)();

        ($this->hostPost)('approve', $booking);

        expect($booking->fresh()->hold_expires_at->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString());
    });

    test('rejects out-of-range hours and a missing reason before any change', function (): void {
        $booking = ($this->pending)();

        ($this->hostPost)('approve', $booking, ['hold_hours' => 0])->assertSessionHasErrors('hold_hours');
        ($this->hostPost)('approve', $booking, ['hold_hours' => 169])->assertSessionHasErrors('hold_hours');
        ($this->hostPost)('decline', $booking, ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->hostPost)('decline', $booking, ['reason' => str_repeat('x', 1001)])->assertSessionHasErrors('reason');

        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('a user without manage venue is refused', function (): void {
        $booking = ($this->pending)();
        $plain = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->tenant->users()->attach($plain->id);

        ($this->hostPost)('approve', $booking, ['hold_hours' => 24], $plain)->assertForbidden();
        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('another organisation\'s admin cannot see or decide this request', function (): void {
        $booking = ($this->pending)();
        $other = Tenant::factory()->create(['slug' => 'other-hotel', 'isolation_mode' => 'shared']);
        $outsider = User::factory()->create(['tenant_id' => $other->id]);
        setPermissionsTeamId($other->id);
        $outsider->assignRole('Org Admin');
        $other->users()->attach($outsider->id);
        Shop::factory()->create(['tenant_id' => $other->id]);
        $otherHost = "{$other->slug}.".mb_ltrim((string) config('session.domain'), '.');

        $this->actingAs($outsider)->post("http://{$otherHost}/venue/inquiries/{$booking->id}/approve", ['operation_key' => (string) Str::uuid(), 'hold_hours' => 24], ['HTTP_HOST' => $otherHost])->assertNotFound();
        $this->actingAs($outsider)->get("http://{$otherHost}/venue/inquiries/{$booking->id}", ['HTTP_HOST' => $otherHost])->assertNotFound();

        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_PENDING_HOST_APPROVAL);
    });

    test('a stale tab gets a clear error and the earlier decision stands', function (): void {
        $booking = ($this->pending)();
        ($this->hostPost)('decline', $booking, ['reason' => 'Booked elsewhere']);

        ($this->hostPost)('approve', $booking, ['hold_hours' => 24])->assertSessionHas('error');

        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_DECLINED);
    });

    test('release, renew and decline follow the same rules over HTTP', function (): void {
        $this->freezeTime();
        $booking = ($this->pending)();
        ($this->hostPost)('approve', $booking, ['hold_hours' => 1]);
        ($this->hostPost)('renew', $booking, ['hold_hours' => 24])->assertSessionHas('error');

        $this->travel(2)->hours();
        ($this->hostPost)('renew', $booking, ['hold_hours' => 24])->assertSessionHas('success');
        ($this->hostPost)('release', $booking, ['reason' => 'Changed plans'])->assertSessionHas('success');

        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_CANCELLED);
    });

    test('the host page carries the approval state, evidence and delivery status', function (): void {
        $booking = ($this->pending)();
        ($this->hostPost)('approve', $booking, ['hold_hours' => 24]);

        $this->actingAs($this->admin)->get("http://{$this->host}/venue/inquiries/{$booking->id}", ['HTTP_HOST' => $this->host])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('inquiry.approval.flow', true)
                ->where('inquiry.approval.effective_status', VenueBooking::STATUS_APPROVED_HOLD)
                ->has('inquiry.approval.actions', 2)
                ->where('inquiry.approval.actions.1.action', VenueBookingAction::ACTION_APPROVED)
                ->has('inquiry.approval.actions.1.delivery_state')
                ->where('inquiry.approval.conflicts', []));
    });
});

describe('decision notices', function (): void {
    test('each transition queues one notice after it commits, and a cancellation by the buyer queues none', function (): void {
        Queue::fake();
        $booking = ($this->pending)();
        ($this->hostPost)('approve', $booking, ['hold_hours' => 24]);
        ($this->asBuyer)()->post(route('marketplace.bookings.cancel', $booking->booking_reference), ['operation_key' => (string) Str::uuid()]);

        Queue::assertPushed(DeliverVenueApprovalNotice::class, 2);
        expect(VenueBookingAction::query()->where('action', VenueBookingAction::ACTION_CANCELLED)->sole()->delivery_state)->toBe(VenueBookingAction::DELIVERY_NONE);
    });

    test('a notice is sent once, says the dates are held and is not a confirmation or payment request', function (): void {
        Queue::fake();
        Mail::fake();
        $booking = ($this->pending)();
        ($this->hostPost)('approve', $booking, ['hold_hours' => 24]);
        $action = VenueBookingAction::query()->where('action', VenueBookingAction::ACTION_APPROVED)->sole();

        (new DeliverVenueApprovalNotice($action->id))->handle();
        (new DeliverVenueApprovalNotice($action->id))->handle();

        Mail::assertSent(VenueApprovalNotice::class, 1);
        Mail::assertSent(VenueApprovalNotice::class, function (VenueApprovalNotice $mail) use ($booking): bool {
            $html = $mail->render();

            return $mail->hasTo($booking->planner_email)
                && str_contains($html, 'not a confirmed booking')
                && ! str_contains(mb_strtolower($html), 'paystack')
                && ! str_contains($html, 'checkout')
                && str_contains($html, route('marketplace.bookings.show', $booking->booking_reference));
        });
        expect($action->fresh()->delivery_state)->toBe(VenueBookingAction::DELIVERY_SENT)
            ->and($action->fresh()->delivery_attempts)->toBe(1);
    });

    test('an uncertain send stays uncertain and is never sent again', function (): void {
        Queue::fake();
        $booking = ($this->pending)();
        ($this->hostPost)('decline', $booking, ['reason' => 'Booked elsewhere']);
        $action = VenueBookingAction::query()->where('action', VenueBookingAction::ACTION_DECLINED)->sole();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('transport dropped'));

        (new DeliverVenueApprovalNotice($action->id))->handle();

        expect($action->fresh()->delivery_state)->toBe(VenueBookingAction::DELIVERY_UNCERTAIN)
            ->and($action->fresh()->delivery_error)->toContain('transport dropped');

        Event::forget(MessageSending::class);
        Mail::fake();
        (new DeliverVenueApprovalNotice($action->id))->handle();
        Mail::assertNothingSent();
    });

    test('a failed notice never undoes the decision', function (): void {
        $booking = ($this->pending)();
        Event::listen(MessageSending::class, fn () => throw new RuntimeException('down'));

        ($this->hostPost)('approve', $booking, ['hold_hours' => 24]);

        expect($booking->fresh()->status)->toBe(VenueBooking::STATUS_APPROVED_HOLD);
    });
});
