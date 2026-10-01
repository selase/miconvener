<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\StoreListing;
use App\Models\VenueBooking;
use App\Services\Marketplace\VenueAvailabilityService;
use App\Services\Marketplace\VenueBookingService;
use App\Services\Payment\PaystackGateway;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class VenueBookingController extends Controller
{
    public function __construct(
        private readonly VenueAvailabilityService $availabilityService,
        private readonly VenueBookingService $bookingService
    ) {}

    /**
     * Check slot availability and preview real-time pricing.
     */
    public function checkAvailability(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'layout_style' => ['nullable', 'string', 'in:banquet,theater,cocktail,classroom,boardroom'],
        ]);

        /** @var StoreListing $listing */
        $listing = StoreListing::query()
            ->where('slug', $slug)
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->firstOrFail();

        $startsAt = Carbon::parse($validated['starts_at']);
        $endsAt = Carbon::parse($validated['ends_at']);
        $guestCount = (int) ($validated['guest_count'] ?? 1);
        $layoutStyle = (string) ($validated['layout_style'] ?? 'banquet');

        $isAvailable = $this->availabilityService->isSlotAvailable($listing, $startsAt, $endsAt);

        $pricing = null;
        if ($isAvailable) {
            $pricing = $this->availabilityService->calculatePricing(
                $listing,
                $startsAt,
                $endsAt,
                $guestCount,
                $layoutStyle
            );
        }

        return response()->json([
            'available' => $isAvailable,
            'pricing' => $pricing,
        ]);
    }

    /**
     * Get booked slots for an availability calendar window.
     */
    public function bookedSlots(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ]);

        /** @var StoreListing $listing */
        $listing = StoreListing::query()
            ->where('slug', $slug)
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->firstOrFail();

        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : now()->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : now()->addMonths(6)->endOfDay();

        $slots = $this->availabilityService->getBookedSlots($listing, $from, $to);

        return response()->json([
            'slots' => $slots,
        ]);
    }

    /**
     * Submit a booking request and initiate deposit checkout.
     */
    public function book(Request $request, string $slug): SymfonyResponse
    {
        $validated = $request->validate([
            'planner_name' => ['required', 'string', 'max:255'],
            'planner_email' => ['required', 'email', 'max:255'],
            'planner_phone' => ['nullable', 'string', 'max:50'],
            'planner_company' => ['nullable', 'string', 'max:255'],
            'event_type' => ['required', 'string', 'max:100'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'layout_style' => ['required', 'string', 'in:banquet,theater,cocktail,classroom,boardroom'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'special_requests' => ['nullable', 'string', 'max:2000'],
            'agreed_to_terms' => ['accepted'],
            'request_quote_only' => ['nullable', 'boolean'],
        ]);

        /** @var StoreListing $listing */
        $listing = StoreListing::query()
            ->with('shop.tenant')
            ->where('slug', $slug)
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->whereHas('shop', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $user = $request->user();
        /** @var \App\Models\Tenant|null $plannerTenant */
        $plannerTenant = $request->attributes->get('tenant');

        $booking = $this->bookingService->createBooking($listing, $validated, $user, $plannerTenant);

        // If booking requires a deposit payment, route through Paystack
        if ($booking->status === VenueBooking::STATUS_PENDING_PAYMENT && $booking->deposit_required_pesewas > 0) {
            $callbackUrl = route('marketplace.bookings.callback', [
                'reference' => $booking->booking_reference,
            ]);

            try {
                $checkoutUrl = $this->bookingService->initializeDepositCheckout($booking, $callbackUrl);

                return Inertia::location($checkoutUrl);
            } catch (Throwable $e) {
                Log::error('Failed to initialize Paystack venue booking deposit checkout', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                    ->with('warning', 'Booking request recorded, but checkout initialization encountered an error. The venue host has been notified.');
            }
        }

        // Quote request / Price on request
        return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
            ->with('success', 'Your venue inquiry and quote request has been submitted to the host.');
    }

    /**
     * Show booking dossier, payment receipt, and contract terms snapshot.
     */
    public function show(string $reference): Response
    {
        /** @var VenueBooking $booking */
        $booking = VenueBooking::query()
            ->with(['listing.primaryMedia', 'shop'])
            ->where('booking_reference', $reference)
            ->firstOrFail();

        return Inertia::render('Public/Marketplace/Bookings/Show', [
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'planner_name' => $booking->planner_name,
                'planner_email' => $booking->planner_email,
                'planner_phone' => $booking->planner_phone,
                'planner_company' => $booking->planner_company,
                'event_type' => $booking->event_type,
                'guest_count' => $booking->guest_count,
                'layout_style' => $booking->layout_style,
                'special_requests' => $booking->special_requests,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'time_slot_type' => $booking->time_slot_type,
                'duration_units' => (float) $booking->duration_units,
                'rental_amount_pesewas' => $booking->rental_amount_pesewas,
                'security_deposit_pesewas' => $booking->security_deposit_pesewas,
                'total_amount_pesewas' => $booking->total_amount_pesewas,
                'deposit_required_pesewas' => $booking->deposit_required_pesewas,
                'amount_paid_pesewas' => $booking->amount_paid_pesewas,
                'paystack_reference' => $booking->paystack_reference,
                'paid_at' => $booking->paid_at?->toIso8601String(),
                'contract_agreed_at' => $booking->contract_agreed_at?->toIso8601String(),
                'contract_terms_snapshot' => $booking->contract_terms_snapshot,
                'host_notes' => $booking->host_notes,
                'venue' => [
                    'id' => $booking->listing?->id,
                    'title' => $booking->listing?->title,
                    'slug' => $booking->listing?->slug,
                    'pricing_model' => $booking->listing?->pricing_model,
                    'primary_media' => $booking->listing?->primaryMedia,
                ],
                'shop' => [
                    'name' => $booking->shop?->name,
                    'slug' => $booking->shop?->slug,
                    'address' => $booking->shop?->address,
                    'city' => $booking->shop?->city,
                    'region' => $booking->shop?->region,
                    'phone' => $booking->shop?->phone,
                    'email' => $booking->shop?->email,
                ],
            ],
        ]);
    }

    /**
     * Initiate Paystack deposit checkout for an existing booking / accepted quote.
     */
    public function checkout(Request $request, string $reference): SymfonyResponse
    {
        /** @var VenueBooking $booking */
        $booking = VenueBooking::query()
            ->with(['shop', 'listing'])
            ->where('booking_reference', $reference)
            ->firstOrFail();

        if ($booking->isDepositPaid() || $booking->isConfirmed()) {
            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('info', 'This reservation deposit has already been paid.');
        }

        if ($booking->status !== VenueBooking::STATUS_PENDING_PAYMENT || $booking->deposit_required_pesewas <= 0) {
            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('error', 'This reservation is not currently awaiting a deposit payment.');
        }

        if ($booking->isExpired()) {
            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('error', 'This quotation or reservation hold has expired. Please contact the venue host.');
        }

        $callbackUrl = route('marketplace.bookings.callback', [
            'reference' => $booking->booking_reference,
        ]);

        try {
            $checkoutUrl = $this->bookingService->initializeDepositCheckout($booking, $callbackUrl);

            return Inertia::location($checkoutUrl);
        } catch (Throwable $e) {
            Log::error('Failed to initialize Paystack deposit checkout for existing booking', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('error', 'Unable to initialize checkout. Please try again or contact the venue.');
        }
    }

    /**
     * Browser callback from Paystack after deposit payment.
     */
    public function callback(Request $request, string $reference): RedirectResponse
    {
        $paystackRef = (string) ($request->query('reference') ?? $request->query('trxref') ?? '');

        /** @var VenueBooking $booking */
        $booking = VenueBooking::query()
            ->where('booking_reference', $reference)
            ->firstOrFail();

        if ($paystackRef === '') {
            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('error', 'No payment reference received from gateway.');
        }

        try {
            $secret = config('services.settlement.paystack.secret_key') ?? config('services.paystack.secret_key');
            $gateway = new PaystackGateway(['secret_key' => $secret]);
            $verification = $gateway->verifyTransaction($paystackRef);

            if (($verification['status'] ?? null) === 'success') {
                $verificationMetadata = \App\Http\Controllers\Billing\WebhookController::metadata($verification['metadata'] ?? null);

                $matchesBooking = (
                    ((string) ($verificationMetadata['venue_booking_id'] ?? '')) === (string) $booking->id
                    || ((string) ($verificationMetadata['booking_reference'] ?? '')) === $booking->booking_reference
                );

                $verifiedAmount = (int) ($verification['amount'] ?? 0);
                $verifiedCurrency = mb_strtoupper((string) ($verification['currency'] ?? ''));

                if (! $matchesBooking || $verifiedAmount < $booking->deposit_required_pesewas || $verifiedCurrency !== 'GHS') {
                    Log::warning('Paystack verification metadata or amount mismatch for venue booking', [
                        'booking_id' => $booking->id,
                        'booking_reference' => $booking->booking_reference,
                        'verification' => $verification,
                    ]);

                    return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                        ->with('error', 'Payment verification failed: transaction details did not match this reservation.');
                }

                $this->bookingService->confirmBookingPayment($paystackRef, [
                    'metadata' => $verificationMetadata,
                    'amount' => $verifiedAmount,
                    'fees' => (int) ($verification['fees'] ?? 0),
                ]);

                return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                    ->with('success', 'Your reservation deposit has been confirmed! The venue calendar is now locked for your event.');
            }

            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('warning', 'Payment could not be verified automatically. If money was debited, the booking will confirm shortly.');
        } catch (Throwable $e) {
            Log::error('Venue booking payment verification exception', [
                'booking_reference' => $reference,
                'paystack_reference' => $paystackRef,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('marketplace.bookings.show', $booking->booking_reference)
                ->with('info', 'We are finalizing your payment with Paystack. Your booking status will update momentarily.');
        }
    }
}
