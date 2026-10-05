<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Mail\Marketplace\BookingConfirmationGuest;
use App\Mail\Marketplace\NewVenueBookingNotification;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VenueBooking;
use App\Services\Payment\PaystackGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class VenueBookingService
{
    public function __construct(
        private readonly VenueAvailabilityService $availabilityService
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
        $startsAt = Carbon::parse((string) $payload['starts_at']);
        $endsAt = Carbon::parse((string) $payload['ends_at']);
        $guestCount = (int) ($payload['guest_count'] ?? 1);
        $layoutStyle = (string) ($payload['layout_style'] ?? 'banquet');

        if (! $listing->isBookable()) {
            throw ValidationException::withMessages([
                'starts_at' => ['Direct online reservations are currently unavailable for this venue. Please contact the host directly.'],
            ]);
        }

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

        // Notify Venue Host
        $this->notifyHostOfNewBooking($booking);

        return $booking;
    }

    /**
     * Initialize Paystack deposit checkout session.
     */
    public function initializeDepositCheckout(VenueBooking $booking, string $callbackUrl): string
    {
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

        $booking = null;
        if ($bookingRef !== null) {
            $booking = VenueBooking::query()->where('booking_reference', $bookingRef)->first();
        } elseif ($bookingId !== null) {
            $booking = VenueBooking::query()->where('id', $bookingId)->first();
        }

        if ($booking === null) {
            throw new RuntimeException("Venue booking not found for Paystack reference: {$reference}");
        }

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

        // Send confirmation receipt to guest
        try {
            $bookingUrl = route('marketplace.bookings.show', $booking->booking_reference);
            Mail::to($booking->planner_email)->queue(new BookingConfirmationGuest($booking, $bookingUrl));
        } catch (Throwable $e) {
            Log::error('Failed to queue guest booking confirmation email', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $booking;
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
