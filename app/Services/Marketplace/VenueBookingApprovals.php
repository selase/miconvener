<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Jobs\DeliverVenueApprovalNotice;
use App\Models\StoreListing;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\TenantContext;
use App\Services\Venue\VenueCalendarLocks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Approval-first public venue requests. A request records interest and holds
 * nothing; a host approval holds the exact time for a bounded number of hours;
 * every transition is rechecked under the shared calendar lock and leaves
 * immutable evidence keyed by its operation key, so a repeat returns the
 * original result and never extends a hold.
 */
final class VenueBookingApprovals
{
    public const int SUBMISSIONS_PER_HOUR = 10;

    private const string REFERENCE_PREFIX = 'VRQ';

    public function __construct(
        private readonly VenueCalendarLocks $locks,
        private readonly VenueAvailabilityService $availability,
        private readonly VenueRequestRollout $rollout,
        private readonly PlatformAttendeeVerification $verification,
    ) {}

    /**
     * Whether this venue currently takes approval-first requests instead of pay-now bookings.
     */
    public function acceptsRequests(StoreListing $listing): bool
    {
        $current = StoreListing::query()->withStaffOnly()->with('shop.tenant')->find($listing->id);
        if ($current === null) {
            return false;
        }

        try {
            $this->assertOpenForRequests($current);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function request(StoreListing $listing, array $validated, Session $session, string $operationKey): VenueBooking
    {
        $email = PlatformAttendeeVerification::normalise((string) ($validated['planner_email'] ?? ''));
        $proven = $this->verification->verifiedEmail($session);
        if ($proven === null || ! hash_equals(PlatformAttendeeVerification::normalise($proven), $email)) {
            throw ValidationException::withMessages(['planner_email' => 'Verify this email address before requesting a venue.']);
        }

        [$startsAt, $endsAt] = $this->window($validated);
        $guestCount = (int) ($validated['guest_count'] ?? 1);
        $layoutStyle = (string) ($validated['layout_style'] ?? 'banquet');
        $hash = $this->hash([
            'request', $listing->id, $startsAt->toIso8601String(), $endsAt->toIso8601String(), $guestCount, $layoutStyle,
            $email, (string) ($validated['planner_name'] ?? ''), (string) ($validated['planner_phone'] ?? ''), (string) ($validated['planner_company'] ?? ''),
            (string) ($validated['event_type'] ?? ''), (string) ($validated['special_requests'] ?? ''),
        ]);

        $current = StoreListing::query()->withStaffOnly()->with('shop.tenant')->find($listing->id);
        if ($current === null) {
            throw ValidationException::withMessages(['starts_at' => 'This venue is not taking online requests right now. Please contact the host directly.']);
        }
        $this->assertOpenForRequests($current);

        return $this->locks->forListing($listing->id, function (StoreListing $locked) use ($validated, $operationKey, $email, $startsAt, $endsAt, $guestCount, $layoutStyle, $hash): VenueBooking {
            $existing = VenueBooking::query()->where('shop_id', $locked->shop_id)->where('submission_key', $operationKey)->first();
            if ($existing !== null) {
                $this->assertSameOperation($existing, $operationKey, $hash);

                return $existing;
            }

            $this->assertOpenForRequests($locked);

            $limiterKey = 'venue-request-submissions:'.sha1($email);
            if (RateLimiter::tooManyAttempts($limiterKey, self::SUBMISSIONS_PER_HOUR)) {
                throw ValidationException::withMessages(['planner_email' => 'Too many venue requests from this email address. Please try again later.']);
            }

            if (! $this->availability->isSlotAvailable($locked, $startsAt, $endsAt)) {
                throw ValidationException::withMessages(['starts_at' => 'The requested venue space is unavailable for the selected date and time window.']);
            }

            $pricing = $this->availability->calculatePricing($locked, $startsAt, $endsAt, $guestCount, $layoutStyle);
            if (! $pricing['capacity_check']['valid']) {
                throw ValidationException::withMessages(['guest_count' => $pricing['capacity_check']['message'] ?? 'Guest count exceeds space capacity.']);
            }

            do {
                $reference = self::REFERENCE_PREFIX.'-'.mb_strtoupper(Str::random(8));
            } while (VenueBooking::query()->where('booking_reference', $reference)->exists());

            $agreedAt = ! empty($validated['agreed_to_terms']) ? now() : null;
            $booking = VenueBooking::query()->create([
                'booking_reference' => $reference,
                'tenant_id' => $locked->shop->tenant_id,
                'shop_id' => $locked->shop_id,
                'store_listing_id' => $locked->id,
                'planner_name' => (string) $validated['planner_name'],
                'planner_email' => $email,
                'planner_phone' => isset($validated['planner_phone']) ? (string) $validated['planner_phone'] : null,
                'planner_company' => isset($validated['planner_company']) ? (string) $validated['planner_company'] : null,
                'event_type' => (string) ($validated['event_type'] ?? 'Event'),
                'guest_count' => $guestCount,
                'layout_style' => $layoutStyle,
                'special_requests' => isset($validated['special_requests']) ? (string) $validated['special_requests'] : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'time_slot_type' => $pricing['time_slot_type'],
                'pricing_model' => $locked->pricing_model ?? StoreListing::PRICING_MODEL_PER_DAY,
                'rate_pesewas' => $pricing['rate_pesewas'],
                'duration_units' => $pricing['duration_units'],
                'rental_amount_pesewas' => $pricing['rental_amount_pesewas'],
                'security_deposit_pesewas' => $pricing['security_deposit_pesewas'],
                'total_amount_pesewas' => $pricing['total_amount_pesewas'],
                'deposit_required_pesewas' => $pricing['deposit_required_pesewas'],
                'status' => VenueBooking::STATUS_PENDING_HOST_APPROVAL,
                'payment_status' => VenueBooking::PAYMENT_UNPAID,
                'approval_flow_version' => VenueBooking::APPROVAL_FLOW_VERSION,
                'submission_key' => $operationKey,
                'contract_agreed_at' => $agreedAt,
                'contract_terms_snapshot' => [
                    'venue_title' => $locked->title,
                    'host_shop_name' => $locked->shop->name,
                    'rules_and_policies' => $locked->rules_and_policies ?? [],
                    'pricing_model' => $locked->pricing_model,
                    'rate_pesewas' => $pricing['rate_pesewas'],
                    'security_deposit_pesewas' => $pricing['security_deposit_pesewas'],
                    'agreed_timestamp' => $agreedAt?->toIso8601String(),
                    'platform_terms_version' => '2026.1',
                    'policy' => $locked->publicBookingPolicy(),
                    'price_is_indicative' => true,
                    'is_price_on_request' => $pricing['is_price_on_request'],
                ],
            ]);

            RateLimiter::hit($limiterKey, 3600);
            $this->record($booking, VenueBookingAction::ACTOR_BUYER, null, $email, VenueBookingAction::ACTION_REQUESTED, $operationKey, $hash, null, null, null, true);

            return $booking;
        });
    }

    /**
     * Approve a request, holding its exact dates for $holdHours.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approve(VenueBooking $booking, User $actor, int $holdHours, string $operationKey): VenueBooking
    {
        $hash = $this->hash(['approve', $booking->id, $holdHours]);

        return $this->hostTransition($booking, $actor, $operationKey, $hash, function (StoreListing $listing, VenueBooking $locked) use ($actor, $holdHours, $operationKey, $hash): VenueBooking {
            $this->assertState($locked, [VenueBooking::STATUS_PENDING_HOST_APPROVAL]);
            $this->assertCanHold($listing, $locked, $holdHours);

            return $this->hold($locked, $actor, VenueBookingAction::ACTION_APPROVED, $holdHours, $operationKey, $hash);
        });
    }

    /**
     * Put an expired hold back on its dates, if they are still free.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function renew(VenueBooking $booking, User $actor, int $holdHours, string $operationKey): VenueBooking
    {
        $hash = $this->hash(['renew', $booking->id, $holdHours]);

        return $this->hostTransition($booking, $actor, $operationKey, $hash, function (StoreListing $listing, VenueBooking $locked) use ($actor, $holdHours, $operationKey, $hash): VenueBooking {
            $this->assertState($locked, [VenueBooking::STATUS_APPROVED_HOLD]);
            if (! $locked->isHoldExpired()) {
                throw ValidationException::withMessages(['booking' => 'This hold is still active. Only an expired hold can be renewed.']);
            }
            $this->assertCanHold($listing, $locked, $holdHours);

            return $this->hold($locked, $actor, VenueBookingAction::ACTION_RENEWED, $holdHours, $operationKey, $hash);
        });
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function decline(VenueBooking $booking, User $actor, string $reason, string $operationKey): VenueBooking
    {
        $reason = $this->reason($reason);
        $hash = $this->hash(['decline', $booking->id, $reason]);

        return $this->hostTransition($booking, $actor, $operationKey, $hash, function (StoreListing $listing, VenueBooking $locked) use ($actor, $reason, $operationKey, $hash): VenueBooking {
            $this->assertState($locked, [VenueBooking::STATUS_PENDING_HOST_APPROVAL, VenueBooking::STATUS_APPROVED_HOLD]);

            return $this->close($locked, $actor, VenueBooking::STATUS_DECLINED, VenueBookingAction::ACTION_DECLINED, $reason, $operationKey, $hash);
        });
    }

    /**
     * Release an approved hold, freeing its dates.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function release(VenueBooking $booking, User $actor, string $reason, string $operationKey): VenueBooking
    {
        $reason = $this->reason($reason);
        $hash = $this->hash(['release', $booking->id, $reason]);

        return $this->hostTransition($booking, $actor, $operationKey, $hash, function (StoreListing $listing, VenueBooking $locked) use ($actor, $reason, $operationKey, $hash): VenueBooking {
            $this->assertState($locked, [VenueBooking::STATUS_APPROVED_HOLD]);

            return $this->close($locked, $actor, VenueBooking::STATUS_CANCELLED, VenueBookingAction::ACTION_RELEASED, $reason, $operationKey, $hash);
        });
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function cancelByBuyer(VenueBooking $booking, Session $session, string $operationKey): VenueBooking
    {
        $hash = $this->hash(['cancel', $booking->id]);

        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($session, $operationKey, $hash): VenueBooking {
            $proven = $this->verification->verifiedEmail($session);
            if ($proven === null || ! hash_equals(PlatformAttendeeVerification::normalise($locked->planner_email), PlatformAttendeeVerification::normalise($proven))) {
                throw new AuthorizationException('Verify the email address used for this request first.');
            }

            if ($this->replay($locked, $operationKey, $hash)) {
                return $locked;
            }

            $this->assertApprovalFlow($locked);
            $this->assertState($locked, [VenueBooking::STATUS_PENDING_HOST_APPROVAL, VenueBooking::STATUS_APPROVED_HOLD]);

            $previous = $locked->status;
            $locked->update(['status' => VenueBooking::STATUS_CANCELLED]);
            $this->record($locked, VenueBookingAction::ACTOR_BUYER, null, PlatformAttendeeVerification::normalise($proven), VenueBookingAction::ACTION_CANCELLED, $operationKey, $hash, $previous, null, null, false);

            return $locked;
        });
    }

    /**
     * @param  Closure(StoreListing, VenueBooking): VenueBooking  $transition
     */
    private function hostTransition(VenueBooking $booking, User $actor, string $operationKey, string $hash, Closure $transition): VenueBooking
    {
        return $this->locks->forBooking($booking->id, function (StoreListing $listing, VenueBooking $locked) use ($actor, $operationKey, $hash, $transition): VenueBooking {
            $this->authoriseHost($locked, $actor);

            if ($this->replay($locked, $operationKey, $hash)) {
                return $locked;
            }

            $this->assertApprovalFlow($locked);

            return $transition($listing, $locked);
        }, $booking->shop_id);
    }

    private function hold(VenueBooking $locked, User $actor, string $action, int $holdHours, string $operationKey, string $hash): VenueBooking
    {
        $previous = $locked->status;
        $deadline = now()->addHours($holdHours);
        $locked->update(['status' => VenueBooking::STATUS_APPROVED_HOLD, 'hold_expires_at' => $deadline, 'decline_reason' => null]);
        $this->record($locked, VenueBookingAction::ACTOR_HOST, $actor, null, $action, $operationKey, $hash, $previous, $deadline, null, true);

        return $locked;
    }

    private function close(VenueBooking $locked, User $actor, string $status, string $action, string $reason, string $operationKey, string $hash): VenueBooking
    {
        $previous = $locked->status;
        $locked->update(['status' => $status, 'decline_reason' => $reason]);
        $this->record($locked, VenueBookingAction::ACTOR_HOST, $actor, null, $action, $operationKey, $hash, $previous, null, $reason, true);

        return $locked;
    }

    /**
     * Whether this operation key was already used on the booking. A key reused
     * for a different decision is an error, never a second action.
     */
    private function replay(VenueBooking $booking, string $operationKey, string $hash): bool
    {
        $prior = VenueBookingAction::query()->where('booking_id', $booking->id)->where('operation_key', $operationKey)->first();
        if ($prior === null) {
            return false;
        }

        if (! hash_equals($prior->request_hash, $hash)) {
            throw ValidationException::withMessages(['operation_key' => 'This request key was already used for a different decision.']);
        }

        return true;
    }

    private function assertSameOperation(VenueBooking $existing, string $operationKey, string $hash): void
    {
        $prior = VenueBookingAction::query()->where('booking_id', $existing->id)->where('operation_key', $operationKey)->first();
        if ($prior === null || ! hash_equals($prior->request_hash, $hash)) {
            throw ValidationException::withMessages(['operation_key' => 'This request key was already used for a different request.']);
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertState(VenueBooking $locked, array $allowed): void
    {
        if (! in_array($locked->status, $allowed, true)) {
            throw ValidationException::withMessages(['booking' => 'This request has already been decided or closed.']);
        }
    }

    private function assertApprovalFlow(VenueBooking $locked): void
    {
        if (! $locked->usesApprovalFlow() || $locked->kind !== VenueBooking::KIND_MARKETPLACE) {
            throw ValidationException::withMessages(['booking' => 'This booking does not use venue approval.']);
        }
    }

    private function assertCanHold(StoreListing $listing, VenueBooking $locked, int $holdHours): void
    {
        $max = min(168, (int) ($locked->approvalPolicy()['max_hold_hours']));
        if ($holdHours < 1 || $holdHours > $max) {
            throw ValidationException::withMessages(['hold_hours' => "A hold lasts between 1 and {$max} hours."]);
        }

        $this->assertOpenForRequests($listing, false);

        if ($locked->ends_at->isPast()) {
            throw ValidationException::withMessages(['booking' => 'These dates have already passed.']);
        }

        if (! $this->availability->isSlotAvailable($listing, $locked->starts_at, $locked->ends_at, $locked->id)) {
            throw ValidationException::withMessages(['starts_at' => 'Those dates are now taken by another reservation, hold or blackout.']);
        }
    }

    private function assertOpenForRequests(StoreListing $listing, bool $requirePolicy = true): void
    {
        $shop = $listing->shop;
        $open = $listing->listing_kind === StoreListing::KIND_VENUE
            && $listing->status === StoreListing::STATUS_PUBLISHED
            && $listing->audience === StoreListing::AUDIENCE_PUBLIC
            && $listing->isBookable()
            && $shop !== null && $shop->is_active
            && $shop->tenant !== null && $this->rollout->enabled($shop->tenant)
            && (! $requirePolicy || $listing->publicBookingPolicy()['approval_required']);

        if (! $open) {
            throw ValidationException::withMessages(['starts_at' => 'This venue is not taking online requests right now. Please contact the host directly.']);
        }
    }

    /**
     * Only the owning organisation's members holding `manage venue` may decide;
     * a model instance or a permission held elsewhere proves nothing.
     */
    private function authoriseHost(VenueBooking $booking, User $actor): void
    {
        $tenant = app(TenantContext::class)->getTenant();
        $member = $tenant !== null
            && (User::query()->whereKey($actor->id)->where('tenant_id', $tenant->id)->exists() || $actor->tenants()->whereKey($tenant->id)->exists());
        if (! $member || $tenant->id !== $booking->tenant_id || $booking->shop?->tenant_id !== $tenant->id) {
            throw new AuthorizationException('You cannot decide this request.');
        }

        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            $allowed = Gate::forUser($actor->fresh())->allows('manage venue');
        } finally {
            setPermissionsTeamId($team);
        }

        if (! $allowed) {
            throw new AuthorizationException('You cannot decide this request.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(array $validated): array
    {
        $parse = function (string $key) use ($validated): CarbonImmutable {
            $raw = (string) ($validated[$key] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $raw) !== 1) {
                throw ValidationException::withMessages([$key => 'Send the time as an ISO 8601 timestamp with its UTC offset.']);
            }
            try {
                return CarbonImmutable::parse($raw)->utc();
            } catch (Throwable) {
                throw ValidationException::withMessages([$key => 'That time is not valid.']);
            }
        };

        $startsAt = $parse('starts_at');
        $endsAt = $parse('ends_at');
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a start time in the future.']);
        }
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }

        return [$startsAt, $endsAt];
    }

    private function reason(string $reason): string
    {
        $reason = mb_trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of 1 to 1000 characters.']);
        }

        return $reason;
    }

    /**
     * @param  list<mixed>  $parts
     */
    private function hash(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function record(VenueBooking $booking, string $actorType, ?User $actor, ?string $buyerEmail, string $action, string $operationKey, string $hash, ?string $previous, ?CarbonInterface $holdExpiresAt, ?string $reason, bool $notify): VenueBookingAction
    {
        $record = VenueBookingAction::query()->create([
            'booking_id' => $booking->id,
            'shop_id' => $booking->shop_id,
            'tenant_id' => $booking->tenant_id,
            'actor_type' => $actorType,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'buyer_email' => $buyerEmail,
            'action' => $action,
            'operation_key' => $operationKey,
            'request_hash' => $hash,
            'previous_status' => $previous,
            'result_status' => $booking->status,
            'hold_expires_at' => $holdExpiresAt,
            'decision_reason' => $reason,
            'delivery_state' => $notify ? VenueBookingAction::DELIVERY_QUEUED : VenueBookingAction::DELIVERY_NONE,
        ]);

        if ($notify) {
            DB::connection('landlord')->afterCommit(fn () => DeliverVenueApprovalNotice::dispatch($record->id));
        }

        return $record;
    }
}
