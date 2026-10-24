<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StoreQuoteSubmission;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\FeeTerms;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreQuotes
{
    public function __construct(private readonly CommerceInventoryLocks $locks, private readonly StoreCommercialTerms $terms, private readonly MerchandiseAccess $access, private readonly PurchaseReservations $reservations, private readonly FeeCalculator $fees) {}

    /** @param array<array-key, mixed> $lines */
    public function create(Tenant $seller, Session $session, array $lines, string $submissionKey): StoreOrder
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($session);
        if ($email === null || ! Str::isUuid($submissionKey) || ! array_is_list($lines) || count($lines) < 1 || count($lines) > 20) {
            $this->refuse();
        }
        $email = PlatformAttendeeVerification::normalise($email);
        $cart = [];
        foreach ($lines as $line) {
            if (! is_array($line) || count($line) !== 2 || ! is_string($line['variant_id'] ?? null) || ! Str::isUuid($line['variant_id'])
                || ! is_int($line['quantity'] ?? null) || $line['quantity'] < 1 || $line['quantity'] > 99 || isset($cart[$line['variant_id']])) {
                $this->refuse();
            }
            $cart[$line['variant_id']] = $line['quantity'];
        }
        ksort($cart, SORT_STRING);
        $cartHash = hash('sha256', json_encode($cart, JSON_THROW_ON_ERROR));

        return $seller->getConnection()->transaction(function () use ($seller, $session, $submissionKey, $email, $cart, $cartHash): StoreOrder {
            $seller = $this->locks->tenant($seller->id);
            if (! app(CommerceCatalogRollout::class)->enabled($seller) || ! $seller->features()->where('feature_key', 'commerce_checkout')->where('enabled', true)->exists()) {
                $this->refuse();
            }
            $shop = $seller->shop;
            $alias = StoreQuoteSubmission::query()->where('shop_id', $shop->id)->where('buyer_email', $email)->where('submission_key', $submissionKey)->first();
            $prior = $alias === null ? StoreOrder::query()->where('shop_id', $shop->id)->where('buyer_email', $email)->where('submission_key', $submissionKey)->lockForUpdate()->first() : StoreOrder::query()->where('buyer_email', $email)->where('shop_id', $shop->id)->lockForUpdate()->findOrFail($alias->order_id);
            if ($prior !== null) {
                if (! hash_equals($prior->cart_hash, $cartHash) || $prior->expires_at->lessThanOrEqualTo(now()) || $prior->state !== 'quoted') {
                    $this->refuse();
                }

                return $prior;
            }
            $agreement = $this->terms->resolve($seller);
            $variants = StoreVariant::query()->where('shop_id', $shop->id)->where('is_active', true)->whereKey(array_keys($cart))->get();
            if ($variants->count() !== count($cart)) {
                $this->refuse();
            }
            $listings = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->with('merchandise')->where('shop_id', $shop->id)->where('listing_kind', StoreListing::KIND_MERCHANDISE)
                ->whereIn('id', $variants->pluck('listing_id'))->where('status', StoreListing::STATUS_PUBLISHED)->whereNull('taken_down_at')->get()->keyBy('id');
            $members = null;
            $principal = 0;
            $snapshots = [];
            foreach ($variants as $variant) {
                $listing = $listings->get($variant->listing_id);
                if ($listing === null || $listing->merchandise === null || $listing->merchandise->archived_at !== null || (blank($listing->merchandise->collection_instructions) && ! \App\Models\StoreFulfilmentPolicy::query()->where('shop_id', $shop->id)->exists())
                    || ($listing->merchandise->visibility !== 'public' && ! ($members ??= $this->access->canReadMembersFrom($seller, $session)))) {
                    $this->refuse();
                }
                $quantity = $cart[$variant->id];
                if ($variant->price_pesewas < 0 || $variant->price_pesewas > intdiv(9007199254740991 - $principal, $quantity)) {
                    $this->refuse();
                }
                $amount = $variant->price_pesewas * $quantity;
                $principal += $amount;
                $snapshots[] = ['variant_id' => $variant->id, 'listing_id' => $variant->listing_id, 'shop_id' => $shop->id,
                    'title_snapshot' => $listing->title, 'sku_snapshot' => $variant->sku, 'options_snapshot' => $variant->options,
                    'quantity' => $quantity, 'unit_price_pesewas' => $variant->price_pesewas, 'line_amount_pesewas' => $amount];
            }
            $open = StoreOrder::query()->where('shop_id', $shop->id)->where('buyer_email', $email)->whereIn('state', ['quoted', 'awaiting_payment', 'review'])->orderBy('id')->lockForUpdate()->get();
            foreach ($open as $old) {
                if ($old->state !== 'quoted') {
                    throw ValidationException::withMessages(['checkout' => 'Resolve the existing payment attempt before starting another checkout.']);
                }
                if ($old->expires_at->isFuture() && hash_equals($old->cart_hash, $cartHash)) {
                    StoreQuoteSubmission::query()->create(['order_id' => $old->id, 'tenant_id' => $seller->id, 'shop_id' => $shop->id, 'buyer_email' => $email, 'submission_key' => $submissionKey, 'cart_hash' => $cartHash]);

                    return $old;
                }
                $this->reservations->release($old, $old->expires_at->lessThanOrEqualTo(now()) ? 'expired' : 'replaced');
                $old->update(['state' => 'cancelled', 'fulfilment_state' => 'cancelled']);
            }
            $split = $this->fees->forTerms($principal, new FeeTerms((float) $agreement->percentage, $agreement->cap_pesewas, $agreement->fee_bearer, (float) $agreement->gateway_estimate_rate));
            if ($split->organizerNet < 0) {
                $this->refuse();
            }
            $terms = $agreement->only(['id', 'version', 'percentage', 'cap_pesewas', 'fee_bearer', 'gateway_estimate_rate', 'refund_gateway_fee_bearer', 'collection_terms', 'refund_terms', 'support_contact', 'receipt_terms', 'payout_policy']);
            $order = StoreOrder::query()->create(['tenant_id' => $seller->id, 'shop_id' => $shop->id, 'agreement_id' => $agreement->id,
                'buyer_email' => $email, 'submission_key' => $submissionKey, 'cart_hash' => $cartHash, 'currency' => 'GHS',
                'principal_pesewas' => $principal, 'platform_fee_pesewas' => $split->platformFee, 'gateway_estimate_pesewas' => $split->gatewayFeeEstimate,
                'charged_pesewas' => $split->chargedAmount, 'fee_bearer' => $agreement->fee_bearer,
                'settlement_mode' => $agreement->settlement_mode, 'provider' => $agreement->provider, 'account_fingerprint' => $agreement->account_fingerprint,
                'seller_snapshot' => ['name' => $shop->name], 'terms_snapshot' => $terms, 'terms_hash' => hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR)),
                'expires_at' => now()->addMinutes(10)]);
            $order->lines()->createMany($snapshots);
            $this->reservations->reserve($order);
            StoreQuoteSubmission::query()->create(['order_id' => $order->id, 'tenant_id' => $seller->id, 'shop_id' => $shop->id, 'buyer_email' => $email, 'submission_key' => $submissionKey, 'cart_hash' => $cartHash]);

            return $order;
        });
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['checkout' => 'This selection is unavailable. Verify your email and choose available items from one store.']);
    }
}
