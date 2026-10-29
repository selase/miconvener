<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Mail\Marketplace\BookingConfirmationGuest;
use App\Mail\Marketplace\NewVenueBookingNotification;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Models\VenueBookingAction;
use App\Services\Payment\PaystackGateway;
use App\Services\Venue\VenueCalendarLocks;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

final class VenueBookingService
{
    public function __construct(
        private readonly VenueAvailabilityService $availabilityService,
        private readonly VenueCalendarLocks $locks,
    ) {}

    /**
     * Create a new booking request or quote inquiry.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function createBooking(
        StoreListing $listing,
        array $payload,
        ?User $user = null,
        ?Tenant $plannerTenant = null
    ): VenueBooking {
        if ($listing->listing_kind === StoreListing::KIND_MERCHANDISE) {
            throw ValidationException::withMessages(['store_listing_id' => 'Merchandise cannot be booked as a venue.']);
        }

        $startsAt = Carbon::parse((string) $payload['starts_at']);
        $endsAt = Carbon::parse((string) $payload['ends_at']);
        $guestCount = (int) ($payload['guest_count'] ?? 1);
        $layoutStyle = (string) ($payload['layout_style'] ?? 'banquet');

        if (! $listing->isBookable()) {
            throw ValidationException::withMessages([
                'starts_at' => ['Direct online reservations are currently unavailable for this venue. Please contact the host directly.'],
            ]);
        }

        $booking = $this->locks->forListing($listing->id, function () use ($listing, $payload, $user, $plannerTenant, $startsAt, $endsAt, $guestCount, $layoutStyle): VenueBooking {
            // Verify calendar availability
            if (! $this->availabilityService->isSlotAvailable($listing, $startsAt, $endsAt)) {
                throw ValidationException::withMessages([
                    'starts_at' => ['The requested venue space is unavailable for the selected date and time window.'],
                ]);
            }

            // Calculate pricing & layout capacity
            $pricing = $this->availabilityService->calculatePricing(
                $listing,
                $startsAt,
                $endsAt,
                $guestCount,
                $layoutStyle
            );

            if (! $pricing['capacity_check']['valid']) {
                throw ValidationException::withMessages([
                    'guest_count' => [$pricing['capacity_check']['message'] ?? 'Guest count exceeds space capacity.'],
                ]);
            }

            $isQuote = $pricing['is_price_on_request'] || ($payload['request_quote_only'] ?? false);
            $prefix = $isQuote ? 'INQ' : 'VBK';

            do {
                $reference = $prefix.'-'.mb_strtoupper(Str::random(8));
            } while (VenueBooking::query()->where('booking_reference', $reference)->exists());

            $contractAgreedAt = ! empty($payload['agreed_to_terms']) ? now() : null;

            $termsSnapshot = [
                'venue_title' => $listing->title,
                'host_shop_name' => $listing->shop?->name,
                'rules_and_policies' => $listing->rules_and_policies ?? [],
                'pricing_model' => $listing->pricing_model,
                'rate_pesewas' => $pricing['rate_pesewas'],
                'security_deposit_pesewas' => $pricing['security_deposit_pesewas'],
                'agreed_timestamp' => $contractAgreedAt?->toIso8601String(),
                'platform_terms_version' => '2026.1',
            ];

            /** @var VenueBooking $booking */
            $booking = VenueBooking::query()->create([
                'booking_reference' => $reference,
                'tenant_id' => $listing->shop->tenant_id,
                'shop_id' => $listing->shop_id,
                'store_listing_id' => $listing->id,
                'user_id' => $user?->id,
                'planner_tenant_id' => $plannerTenant?->id,
                'planner_name' => (string) $payload['planner_name'],
                'planner_email' => (string) $payload['planner_email'],
                'planner_phone' => isset($payload['planner_phone']) ? (string) $payload['planner_phone'] : null,
                'planner_company' => isset($payload['planner_company']) ? (string) $payload['planner_company'] : null,
                'event_type' => (string) ($payload['event_type'] ?? 'Event'),
                'guest_count' => $guestCount,
                'layout_style' => $layoutStyle,
                'special_requests' => isset($payload['special_requests']) ? (string) $payload['special_requests'] : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'time_slot_type' => $pricing['time_slot_type'],
                'pricing_model' => $listing->pricing_model ?? StoreListing::PRICING_MODEL_PER_DAY,
                'rate_pesewas' => $pricing['rate_pesewas'],
                'duration_units' => $pricing['duration_units'],
                'rental_amount_pesewas' => $pricing['rental_amount_pesewas'],
                'security_deposit_pesewas' => $pricing['security_deposit_pesewas'],
                'total_amount_pesewas' => $pricing['total_amount_pesewas'],
                'deposit_required_pesewas' => $pricing['deposit_required_pesewas'],
                'status' => $isQuote ? VenueBooking::STATUS_PENDING_QUOTE : VenueBooking::STATUS_PENDING_PAYMENT,
                'quote_valid_until' => $isQuote ? null : now()->addHours(2),
                'payment_status' => VenueBooking::PAYMENT_UNPAID,
                'contract_agreed_at' => $contractAgreedAt,
                'contract_terms_snapshot' => $termsSnapshot,
            ]);

            return $booking;
        });

        // Notify Venue Host
        $this->notifyHostOfNewBooking($booking);

        return $booking;
    }

    /**
     * A host's tentative hold on an enquiry or request: payable and occupying for 48 hours.
     *
     * @throws ValidationException when the booking is settled or the time is no longer free
     */
    public function placeHostHold(Shop $shop, string $bookingId): VenueBooking
    {
        return $this->locks->forBooking($bookingId, function (StoreListing $listing, VenueBooking $booking): VenueBooking {
            $this->assertHostMayChange($booking);
            $this->assertStillFree($listing, $booking);

            $booking->update([
                'status' => VenueBooking::STATUS_PENDING_PAYMENT,
                'host_notes' => 'Host has placed a 48-hour tentative reservation hold on this space.',
                'quote_valid_until' => now()->addHours(48),
            ]);

            return $booking;
        }, $shop->id);
    }

    /**
     * A host's custom quotation: payable and occupying for seven days.
     *
     * @throws ValidationException when the booking is settled or the time is no longer free
     */
    public function quoteAsHost(Shop $shop, string $bookingId, int $rentalPesewas, int $securityDepositPesewas, int $requiredPesewas, ?string $notes): VenueBooking
    {
        return $this->locks->forBooking($bookingId, function (StoreListing $listing, VenueBooking $booking) use ($rentalPesewas, $securityDepositPesewas, $requiredPesewas, $notes): VenueBooking {
            $this->assertHostMayChange($booking);
            $this->assertStillFree($listing, $booking);

            $total = $rentalPesewas + $securityDepositPesewas;
            $booking->update([
                'rental_amount_pesewas' => $rentalPesewas,
                'security_deposit_pesewas' => $securityDepositPesewas,
                'total_amount_pesewas' => $total,
                'deposit_required_pesewas' => min($requiredPesewas, $total),
                'host_notes' => $notes,
                'status' => VenueBooking::STATUS_PENDING_PAYMENT,
                'quote_valid_until' => now()->addDays(7),
            ]);

            return $booking;
        }, $shop->id);
    }

    /**
     * A host declines an unsettled booking, freeing its time.
     *
     * @throws ValidationException when the booking is already confirmed or paid
     */
    public function rejectAsHost(Shop $shop, string $bookingId, ?string $reason): VenueBooking
    {
        return $this->locks->forBooking($bookingId, function (StoreListing $listing, VenueBooking $booking) use ($reason): VenueBooking {
            $this->assertLegacyBooking($booking);

            if ($booking->isConfirmed() || $booking->isDepositPaid()) {
                throw ValidationException::withMessages(['booking' => 'Cannot decline an already confirmed or paid reservation.']);
            }

            $booking->update([
                'status' => VenueBooking::STATUS_REJECTED,
                'host_notes' => $reason ?? 'Declined by venue host.',
            ]);

            return $booking;
        }, $shop->id);
    }

    /**
     * Initialize Paystack deposit checkout session.
     */
    public function initializeDepositCheckout(VenueBooking $booking, string $callbackUrl): string
    {
        if ($booking->usesApprovalFlow()) {
            throw new RuntimeException('Payment is not available for venue requests that await host approval.');
        }

        $gateway = $this->getPlatformGateway();
        $customerId = $gateway->createCustomer($booking->planner_email, $booking->planner_name);

        // The buyer's service fee, by their plan, fixed now so the amount
        // charged and the amount booked to the ledger are the same.
        $buyerFee = app(MarketplaceFees::class)->buyerFeeOn($booking->plannerTenant, $booking->deposit_required_pesewas);
        $booking->update(['buyer_fee_pesewas' => $buyerFee]);

        return $gateway->createOneTimeCheckoutSession(
            $customerId,
            $booking->deposit_required_pesewas + $buyerFee,
            'GHS',
            $callbackUrl,
            [
                'type' => 'venue_booking',
                'booking_reference' => $booking->booking_reference,
                'venue_booking_id' => $booking->id,
                'tenant_id' => $booking->tenant_id,
                'source' => config('services.paystack.metadata_source'),
            ]
        );
    }

    /**
     * Idempotently confirm payment for a venue booking.
     *
     * @param  array<string, mixed>  $gatewayData
     */
    public function confirmBookingPayment(string $reference, array $gatewayData = []): VenueBooking
    {
        // 1. Idempotency by Paystack reference
        $existing = VenueBooking::query()
            ->with(['listing', 'shop'])
            ->where('paystack_reference', $reference)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // 2. Resolve booking by booking_reference or venue_booking_id in metadata
        $metadata = \App\Http\Controllers\Billing\WebhookController::metadata($gatewayData['metadata'] ?? null);
        $bookingRef = $metadata['booking_reference'] ?? null;
        $bookingId = $metadata['venue_booking_id'] ?? null;

        $found = null;
        if ($bookingRef !== null) {
            $found = VenueBooking::query()->where('booking_reference', $bookingRef)->first();
        } elseif ($bookingId !== null) {
            $found = VenueBooking::query()->where('id', $bookingId)->first();
        }

        if ($found === null) {
            throw new RuntimeException("Venue booking not found for Paystack reference: {$reference}");
        }

        // 3. Confirm under the same calendar lock as every other writer. A payment
        // that can no longer be honoured is kept as evidence, never as a reservation.
        [$booking, $confirmed] = $this->locks->forBooking($found->id, function (StoreListing $listing, VenueBooking $booking) use ($reference, $gatewayData): array {
            if ($booking->paystack_reference === $reference) {
                return [$booking, false];
            }

            $conflict = $booking->usesApprovalFlow() ? 'approval_flow_no_payment' : $this->paymentCalendarConflict($listing, $booking);
            if ($conflict !== null) {
                $this->recordPaymentCalendarConflict($booking, $reference, $gatewayData, $conflict);

                return [$booking, false];
            }

            $this->settleLegacyPayment($booking, $reference, $gatewayData);

            return [$booking, true];
        });

        if ($confirmed) {
            $this->sendGuestConfirmation($booking);
        }

        return $booking;
    }

    private function assertHostMayChange(VenueBooking $booking): void
    {
        $this->assertLegacyBooking($booking);

        if ($booking->isConfirmed() || $booking->isDepositPaid()) {
            throw ValidationException::withMessages(['booking' => 'Cannot modify an already confirmed or paid reservation.']);
        }
    }

    private function assertLegacyBooking(VenueBooking $booking): void
    {
        if ($booking->usesApprovalFlow()) {
            throw ValidationException::withMessages(['booking' => 'Use the approval controls for this request.']);
        }
    }

    private function assertStillFree(StoreListing $listing, VenueBooking $booking): void
    {
        if (! $this->availabilityService->isSlotAvailable($listing, $booking->starts_at, $booking->ends_at, $booking->id)) {
            throw ValidationException::withMessages(['starts_at' => 'Those dates are now taken by another reservation, hold or blackout.']);
        }
    }

    /**
     * Why a received payment cannot take the booking's time, or null when it can.
     */
    private function paymentCalendarConflict(StoreListing $listing, VenueBooking $booking): ?string
    {
        if (in_array($booking->status, [VenueBooking::STATUS_REJECTED, VenueBooking::STATUS_CANCELLED, VenueBooking::STATUS_DECLINED], true)) {
            return 'booking_closed';
        }

        if (! $this->availabilityService->isSlotAvailable($listing, $booking->starts_at, $booking->ends_at, $booking->id)) {
            return 'calendar_taken';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gatewayData
     */
    private function recordPaymentCalendarConflict(VenueBooking $booking, string $reference, array $gatewayData, string $reason): void
    {
        $operationKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'venue-payment-calendar-conflict:'.$reference)->toString();
        if (VenueBookingAction::query()->where('booking_id', $booking->id)->where('operation_key', $operationKey)->exists()) {
            return;
        }

        $evidence = [
            'provider_reference' => $reference,
            'amount_pesewas' => (int) ($gatewayData['amount'] ?? 0),
            'currency' => mb_strtoupper((string) ($gatewayData['currency'] ?? 'GHS')),
            'fees_pesewas' => (int) ($gatewayData['fees'] ?? 0),
        ];

        VenueBookingAction::query()->create([
            'booking_id' => $booking->id,
            'shop_id' => $booking->shop_id,
            'tenant_id' => $booking->tenant_id,
            'actor_type' => VenueBookingAction::ACTOR_SYSTEM,
            'action' => VenueBookingAction::ACTION_PAYMENT_CALENDAR_CONFLICT,
            'operation_key' => $operationKey,
            'request_hash' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
            'previous_status' => $booking->status,
            'result_status' => $booking->status,
            'decision_reason' => $reason,
            'evidence' => $evidence,
            'delivery_state' => VenueBookingAction::DELIVERY_NONE,
        ]);

        Log::warning('Venue booking payment could not take its dates; reconciliation required', [
            'booking_id' => $booking->id,
            'paystack_reference' => $reference,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $gatewayData
     */
    private function settleLegacyPayment(VenueBooking $booking, string $reference, array $gatewayData): void
    {
        $buyerFee = (int) $booking->buyer_fee_pesewas;
        $amountPaid = (int) ($gatewayData['amount'] ?? $booking->deposit_required_pesewas + $buyerFee);
        $gatewayFee = (int) ($gatewayData['fees'] ?? 0);

        // What was paid toward the venue's price, without the service fee.
        $paymentStatus = ($amountPaid - $buyerFee >= $booking->total_amount_pesewas)
            ? VenueBooking::PAYMENT_FULLY_PAID
            : VenueBooking::PAYMENT_DEPOSIT_PAID;

        $booking->update([
            'status' => VenueBooking::STATUS_CONFIRMED,
            'payment_status' => $paymentStatus,
            'paystack_reference' => $reference,
            'paid_at' => now(),
            'amount_paid_pesewas' => $amountPaid,
            'gateway_fee_pesewas' => $gatewayFee,
        ]);

        $booking->load(['listing', 'shop', 'tenant']);

        // The venue's commission and the buyer's service fee, booked to the
        // venue's ledger with what it is owed. Venue bookings used to record
        // nothing at all.
        if ($booking->tenant !== null) {
            app(MarketplaceLedger::class)->post(
                seller: $booking->tenant,
                event: $booking->event,
                label: "venue booking {$booking->booking_reference}",
                reference: "MKT-{$booking->booking_reference}-{$reference}",
                amountPaid: $amountPaid,
                gatewayFee: $gatewayFee,
                buyerFee: $buyerFee,
            );
        }
    }

    private function sendGuestConfirmation(VenueBooking $booking): void
    {
        try {
            $bookingUrl = route('marketplace.bookings.show', $booking->booking_reference);
            Mail::to($booking->planner_email)->queue(new BookingConfirmationGuest($booking, $bookingUrl));
        } catch (Throwable $e) {
            Log::error('Failed to queue guest booking confirmation email', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyHostOfNewBooking(VenueBooking $booking): void
    {
        $shop = $booking->shop;
        if ($shop === null || empty($shop->email)) {
            return;
        }

        try {
            $hostTenant = $shop->tenant;
            $subdomain = $hostTenant?->slug;
            $inboxUrl = $subdomain !== null
                ? route('tenant.venue.inquiries.show', ['subdomain' => $subdomain, 'inquiry' => $booking->id])
                : route('marketplace.venues.show', $booking->listing->slug);

            Mail::to($shop->email)->queue(new NewVenueBookingNotification($booking, $inboxUrl));
        } catch (Throwable $e) {
            Log::error('Failed to queue host venue booking notification', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getPlatformGateway(): PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key') ?? config('services.paystack.secret_key');

        return new PaystackGateway(['secret_key' => $secret]);
    }
}
