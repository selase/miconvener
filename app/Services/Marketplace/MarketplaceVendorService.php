<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Mail\Marketplace\NewQuoteRequestNotification;
use App\Mail\Marketplace\QuoteProposalReady;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MarketplaceQuote;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Services\Finance\LedgerService;
use App\Services\Payment\PaystackGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class MarketplaceVendorService
{
    public function __construct(
        private readonly LedgerService $ledgerService
    ) {}

    /**
     * Create an RFQ for vendor services/rentals.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function createRfq(
        StoreListing $listing,
        array $payload,
        ?Tenant $plannerTenant = null
    ): MarketplaceQuote {
        do {
            $reference = 'RFQ-'.mb_strtoupper(Str::random(10));
        } while (MarketplaceQuote::query()->where('quote_reference', $reference)->exists());

        $eventDate = ! empty($payload['event_date']) ? Carbon::parse((string) $payload['event_date']) : null;

        /** @var MarketplaceQuote $quote */
        $quote = MarketplaceQuote::query()->create([
            'quote_reference' => $reference,
            'tenant_id' => $listing->shop->tenant_id,
            'shop_id' => $listing->shop_id,
            'store_listing_id' => $listing->id,
            'planner_tenant_id' => $plannerTenant?->id,
            'event_id' => $payload['event_id'] ?? null,
            'planner_name' => (string) $payload['planner_name'],
            'planner_email' => (string) $payload['planner_email'],
            'planner_phone' => $payload['planner_phone'] ?? null,
            'event_title' => $payload['event_title'] ?? null,
            'event_date' => $eventDate,
            'guest_count' => ! empty($payload['guest_count']) ? (int) $payload['guest_count'] : null,
            'location_address' => $payload['location_address'] ?? null,
            'requirements_description' => (string) $payload['requirements_description'],
            'status' => MarketplaceQuote::STATUS_PENDING_QUOTE,
        ]);

        $this->notifyVendorOfRequest($quote);

        return $quote;
    }

    /**
     * Submit an itemized quote proposal by the vendor.
     *
     * @param  array<string, mixed>  $payload
     */
    public function submitProposal(MarketplaceQuote $quote, array $payload): MarketplaceQuote
    {
        $items = (array) ($payload['items'] ?? []);
        $subtotal = 0;

        foreach ($items as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = max(0, (int) ($item['unit_price_pesewas'] ?? 0));
            $subtotal += ($qty * $unitPrice);
        }

        $deliveryFee = max(0, (int) ($payload['delivery_fee_pesewas'] ?? 0));
        $tax = max(0, (int) ($payload['tax_pesewas'] ?? 0));
        $totalAmount = $subtotal + $deliveryFee + $tax;

        $depositRequired = isset($payload['deposit_required_pesewas'])
            ? max(0, min((int) $payload['deposit_required_pesewas'], $totalAmount))
            : $totalAmount;

        $validUntil = ! empty($payload['valid_until'])
            ? Carbon::parse((string) $payload['valid_until'])
            : now()->addDays(7);

        $quote->update([
            'items' => $items,
            'subtotal_pesewas' => $subtotal,
            'delivery_fee_pesewas' => $deliveryFee,
            'tax_pesewas' => $tax,
            'total_amount_pesewas' => $totalAmount,
            'deposit_required_pesewas' => $depositRequired,
            'valid_until' => $validUntil,
            'vendor_notes' => $payload['vendor_notes'] ?? null,
            'status' => MarketplaceQuote::STATUS_QUOTED,
        ]);

        $quote = $quote->fresh();
        $this->notifyPlannerOfProposal($quote);

        return $quote;
    }

    /**
     * Initialize Paystack checkout for quotation deposit payment.
     *
     * @return array{checkout_url: string, reference: string}
     */
    public function initializeCheckout(MarketplaceQuote $quote, string $callbackUrl): array
    {
        if ($quote->isExpired()) {
            throw new RuntimeException('This quotation proposal has expired.');
        }

        $gateway = $this->getPlatformGateway();
        $amountToPay = $quote->deposit_required_pesewas > 0
            ? $quote->deposit_required_pesewas
            : $quote->total_amount_pesewas;

        $customerId = $gateway->createCustomer($quote->planner_email, $quote->planner_name);

        $checkoutUrl = $gateway->createOneTimeCheckoutSession(
            $customerId,
            $amountToPay,
            'GHS',
            $callbackUrl,
            [
                'type' => 'marketplace_quote',
                'marketplace_quote_id' => $quote->id,
                'quote_reference' => $quote->quote_reference,
                'tenant_id' => $quote->tenant_id,
                'source' => config('services.paystack.metadata_source', 'miconvener'),
            ]
        );

        return [
            'checkout_url' => $checkoutUrl,
            'reference' => $quote->quote_reference,
        ];
    }

    /**
     * Confirm verified payment from Paystack, update status and book ledger entries.
     *
     * @param  array<string, mixed>  $gatewayData
     */
    public function confirmPayment(string $reference, array $gatewayData): MarketplaceQuote
    {
        if (isset($gatewayData['status']) && $gatewayData['status'] !== 'success') {
            throw new RuntimeException("Cannot confirm quotation payment with non-success status: {$gatewayData['status']}");
        }

        // Idempotency check: if this Paystack reference was already recorded
        $existing = MarketplaceQuote::query()->where('paystack_reference', $reference)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Look up by reference or metadata
        $quoteId = $gatewayData['metadata']['marketplace_quote_id'] ?? null;
        $quoteRef = $gatewayData['metadata']['quote_reference'] ?? null;

        /** @var MarketplaceQuote|null $quote */
        $quote = null;
        if ($quoteId) {
            $quote = MarketplaceQuote::query()->find($quoteId);
        } elseif ($quoteRef) {
            $quote = MarketplaceQuote::query()->where('quote_reference', $quoteRef)->first();
        }

        if (! $quote) {
            throw new RuntimeException("Marketplace quote not found for payment confirmation {$reference}");
        }

        // If quote is already accepted and paid, return early (idempotency guard against multiple callbacks)
        if ($quote->status === MarketplaceQuote::STATUS_ACCEPTED && $quote->isPaid()) {
            return $quote;
        }

        $amountPaid = (int) ($gatewayData['amount'] ?? $quote->deposit_required_pesewas);
        $gatewayFee = (int) ($gatewayData['fees'] ?? 0);

        return DB::connection('landlord')->transaction(function () use ($quote, $reference, $amountPaid, $gatewayFee) {
            $quote->update([
                'status' => MarketplaceQuote::STATUS_ACCEPTED,
                'amount_paid_pesewas' => $amountPaid,
                'paystack_reference' => $reference,
                'accepted_at' => now(),
                'paid_at' => now(),
            ]);

            $this->postQuoteLedger($quote, $reference, $amountPaid, $gatewayFee);

            return $quote->fresh();
        });
    }

    private function notifyVendorOfRequest(MarketplaceQuote $quote): void
    {
        $shop = $quote->shop;
        if ($shop === null || blank($shop->email)) {
            return;
        }

        try {
            $subdomain = $shop->tenant?->slug;
            $inboxUrl = $subdomain !== null
                ? route('tenant.venue.quotes.show', ['subdomain' => $subdomain, 'quote' => $quote->id])
                : route('marketplace.quotes.show', ['reference' => $quote->quote_reference]);

            Mail::to($shop->email)->queue(new NewQuoteRequestNotification($quote, $inboxUrl));
        } catch (Throwable $e) {
            Log::error('Failed to queue vendor quote request notification', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyPlannerOfProposal(MarketplaceQuote $quote): void
    {
        try {
            Mail::to($quote->planner_email)->queue(new QuoteProposalReady(
                $quote,
                route('marketplace.quotes.show', ['reference' => $quote->quote_reference]),
            ));
        } catch (Throwable $e) {
            Log::error('Failed to queue quote proposal notification', ['quote_id' => $quote->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Book balanced double-entry ledger entries for marketplace vendor booking.
     */
    private function postQuoteLedger(
        MarketplaceQuote $quote,
        string $reference,
        int $amountPaid,
        int $gatewayFee
    ): void {
        $tenant = $quote->tenant;
        if (! $tenant) {
            return;
        }

        // Platform commission is 10% on marketplace bookings
        $commissionRate = (float) config('services.platform.marketplace_commission_percent', 10.0);
        $netClearing = max(0, $amountPaid - $gatewayFee);
        $platformFee = min($netClearing, (int) round($amountPaid * $commissionRate / 100));
        $organizerPayable = max(0, $netClearing - $platformFee);

        $entries = [
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $netClearing,
                'description' => "Cash clearing from Paystack for quote {$quote->quote_reference} (net of gateway fee)",
            ],
            [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $platformFee,
                'description' => "Platform commission (10%) on marketplace quote {$quote->quote_reference}",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $organizerPayable,
                'description' => "Vendor payable balance for quote {$quote->quote_reference}",
            ],
        ];

        // Filter out any zero amounts so debits and credits stay balanced
        $validEntries = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($entries as $e) {
            if ($e['amount'] > 0) {
                $validEntries[] = $e;
                if ($e['direction'] === LedgerEntry::DIRECTION_DEBIT) {
                    $totalDebits += $e['amount'];
                } else {
                    $totalCredits += $e['amount'];
                }
            }
        }

        if ($totalDebits === $totalCredits && ! empty($validEntries)) {
            try {
                $this->ledgerService->postTransaction(
                    tenant: $tenant,
                    event: $quote->event,
                    transactionType: 'marketplace_booking',
                    description: "Marketplace quote booking payment: {$quote->quote_reference}",
                    reference: "MKT-{$quote->quote_reference}-{$reference}",
                    entries: $validEntries
                );
            } catch (Throwable $e) {
                Log::error('Failed to post marketplace quote to ledger', [
                    'quote_id' => $quote->id,
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($totalDebits !== $totalCredits) {
            Log::warning('Imbalanced debits and credits calculated for marketplace quote ledger', [
                'quote_id' => $quote->id,
                'reference' => $reference,
                'debits' => $totalDebits,
                'credits' => $totalCredits,
            ]);
        }
    }

    private function getPlatformGateway(): PaystackGateway
    {
        $secret = config('services.settlement.paystack.secret_key') ?? config('services.paystack.secret_key');

        return new PaystackGateway(['secret_key' => $secret]);
    }
}
