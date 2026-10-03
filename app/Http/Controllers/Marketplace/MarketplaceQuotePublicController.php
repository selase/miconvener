<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceQuote;
use App\Models\MarketplaceReview;
use App\Models\StoreListing;
use App\Services\Marketplace\MarketplaceVendorService;
use App\Services\Payment\PaystackGateway;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class MarketplaceQuotePublicController extends Controller
{
    public function __construct(
        private readonly MarketplaceVendorService $vendorService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Submit an RFQ from a marketplace vendor/equipment/venue listing page.
     */
    public function storeRfq(Request $request, string $slug): RedirectResponse
    {
        /** @var StoreListing $listing */
        $listing = StoreListing::query()
            ->with('shop')
            ->where('slug', $slug)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->firstOrFail();

        $validated = $request->validate([
            'planner_name' => ['required', 'string', 'max:255'],
            'planner_email' => ['required', 'email', 'max:255'],
            'planner_phone' => ['nullable', 'string', 'max:50'],
            'event_title' => ['nullable', 'string', 'max:255'],
            'event_date' => ['nullable', 'date', 'after:today'],
            'guest_count' => ['nullable', 'integer', 'min:1', 'max:500000'],
            'location_address' => ['nullable', 'string', 'max:500'],
            'requirements_description' => ['required', 'string', 'max:3000'],
            'event_id' => ['nullable', 'uuid', 'exists:landlord.events,id'],
        ]);

        $plannerTenant = null;
        try {
            $plannerTenant = $this->tenantContext->getTenant();
        } catch (Throwable) {
            // Unauthenticated guest RFQ submission
        }

        $quote = $this->vendorService->createRfq($listing, $validated, $plannerTenant);

        return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
            ->with('success', 'Your Request for Quote (RFQ) has been sent to the vendor! They will prepare a customized proposal.');
    }

    /**
     * View a quotation proposal (Client / Public view).
     */
    public function showProposal(string $reference): Response
    {
        /** @var MarketplaceQuote $quote */
        $quote = MarketplaceQuote::query()
            ->with(['listing.primaryMedia', 'shop', 'plannerTenant', 'event'])
            ->where('quote_reference', $reference)
            ->firstOrFail();

        $existingReview = null;
        if ($quote->isAccepted() || $quote->isPaid()) {
            $existingReview = MarketplaceReview::query()
                ->where('marketplace_quote_id', $quote->id)
                ->first();
        }

        return Inertia::render('Public/Marketplace/Quotes/Show', [
            'quote' => $quote->toPayload(),
            'existingReview' => $existingReview?->toPayload(),
        ]);
    }

    /**
     * Checkout deposit for quotation acceptance via Paystack.
     */
    public function checkout(string $reference): SymfonyResponse
    {
        /** @var MarketplaceQuote $quote */
        $quote = MarketplaceQuote::query()
            ->with('shop')
            ->where('quote_reference', $reference)
            ->firstOrFail();

        if ($quote->status !== MarketplaceQuote::STATUS_QUOTED) {
            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'Only active proposals can be accepted.');
        }

        if ($quote->isExpired()) {
            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'This proposal has expired. Please contact the vendor for a revised quote.');
        }

        $callbackUrl = route('marketplace.quotes.callback', ['reference' => $quote->quote_reference]);

        try {
            $session = $this->vendorService->initializeCheckout($quote, $callbackUrl);

            return Inertia::location($session['checkout_url']);
        } catch (Throwable $e) {
            Log::error('Failed to initiate Paystack checkout for quote', [
                'quote_reference' => $quote->quote_reference,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'Unable to initiate payment: '.$e->getMessage());
        }
    }

    /**
     * Paystack return callback for quotation deposit payment.
     */
    public function callback(Request $request, string $reference): RedirectResponse
    {
        /** @var MarketplaceQuote $quote */
        $quote = MarketplaceQuote::query()
            ->where('quote_reference', $reference)
            ->firstOrFail();

        $paystackRef = (string) ($request->query('trxref') ?? $request->query('reference') ?? '');

        if (empty($paystackRef)) {
            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'Missing transaction reference from payment provider.');
        }

        $secret = config('services.settlement.paystack.secret_key') ?? config('services.paystack.secret_key');
        $gateway = new PaystackGateway(['secret_key' => $secret]);

        try {
            $verified = $gateway->verifyTransaction($paystackRef);

            if (($verified['status'] ?? '') === 'success') {
                $this->vendorService->confirmPayment($paystackRef, $verified);

                return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                    ->with('success', 'Your booking deposit has been confirmed! The vendor has been notified and your date is reserved.');
            }

            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'Payment verification failed: '.($verified['gateway_response'] ?? 'Transaction was not successful'));
        } catch (Throwable $e) {
            Log::error('Error verifying Paystack quote deposit callback', [
                'quote_reference' => $quote->quote_reference,
                'trxref' => $paystackRef,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'Payment verification encountered an error: '.$e->getMessage());
        }
    }

    /**
     * Submit verified client review for a vendor.
     */
    public function storeReview(Request $request, string $reference): RedirectResponse
    {
        /** @var MarketplaceQuote $quote */
        $quote = MarketplaceQuote::query()
            ->with('shop')
            ->where('quote_reference', $reference)
            ->firstOrFail();

        if (! $quote->isPaid() && $quote->status !== MarketplaceQuote::STATUS_ACCEPTED) {
            return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
                ->with('error', 'Reviews can only be submitted after accepting and confirming a booking.');
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:255'],
            'comment' => ['required', 'string', 'max:2000'],
        ]);

        MarketplaceReview::query()->updateOrCreate(
            [
                'marketplace_quote_id' => $quote->id,
            ],
            [
                'shop_id' => $quote->shop_id,
                'store_listing_id' => $quote->store_listing_id,
                'planner_tenant_id' => $quote->planner_tenant_id,
                'planner_name' => $quote->planner_name,
                'planner_email' => $quote->planner_email,
                'rating' => (int) $validated['rating'],
                'title' => $validated['title'] ?? null,
                'comment' => (string) $validated['comment'],
                'is_verified_booking' => true,
                'is_published' => true,
            ]
        );

        return redirect()->route('marketplace.quotes.show', ['reference' => $quote->quote_reference])
            ->with('success', 'Thank you! Your verified client review and rating have been published.');
    }
}
