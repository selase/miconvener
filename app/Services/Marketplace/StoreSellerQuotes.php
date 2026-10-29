<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Requests\Tenant\Store\IssueStoreSellerQuoteRequest;
use App\Models\Member;
use App\Models\StoreFulfilmentArrangement;
use App\Models\StoreFulfilmentPolicy;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StoreSellerQuote;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\MerchandiseVisibilityScope;
use App\Scopes\TenantScope;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\FeeTerms;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StoreSellerQuotes
{
    public function canManage(Tenant $tenant, User $actor): bool
    {
        if (! User::query()->whereKey($actor->id)->where('tenant_id', $tenant->id)->exists() && ! $actor->tenants()->whereKey($tenant->id)->exists()) {
            return false;
        }
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);

            return Gate::forUser($actor->fresh())->allows('manage store orders');
        } finally {
            setPermissionsTeamId($team);
        }
    }

    public function authorize(Tenant $tenant, User $actor): void
    {
        if (! $this->canManage($tenant, $actor)) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string,mixed> $input */
    public function issue(Tenant $tenant, User $actor, array $input): StoreSellerQuote
    {
        $this->authorize($tenant, $actor);
        if (is_string($input['buyer_email'] ?? null)) {
            $input['buyer_email'] = PlatformAttendeeVerification::normalise($input['buyer_email']);
        }
        foreach (['customisation', 'production_terms'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = mb_trim($input[$field]);
            }
        }
        $data = Validator::make($input, IssueStoreSellerQuoteRequest::quoteRules())->validate();
        $data['valid_for_hours'] = (int) $data['valid_for_hours'];
        $data['printing_pesewas'] = (int) $data['printing_pesewas'];
        $data['delivery_pesewas'] = (int) $data['delivery_pesewas'];
        $data['lines'] = array_map(fn (array $line): array => ['variant_id' => $line['variant_id'], 'quantity' => (int) $line['quantity'], 'unit_price_pesewas' => (int) $line['unit_price_pesewas']], $data['lines']);
        usort($data['lines'], fn (array $a, array $b): int => strcmp($a['variant_id'], $b['variant_id']));
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return $tenant->getConnection()->transaction(function () use ($tenant, $actor, $data, $requestHash): StoreSellerQuote {
            $tenant = app(CommerceInventoryLocks::class)->tenant($tenant->id);
            $this->authorize($tenant, $actor);
            if (! app(CommerceCatalogRollout::class)->enabled($tenant)
                || ! $tenant->features()->where('feature_key', 'commerce_checkout')->where('enabled', true)->exists()) {
                $this->refuse('Store checkout must be approved and enabled before issuing a payable quote.');
            }
            $shop = $tenant->shop;
            $prior = StoreSellerQuote::query()->where('shop_id', $shop->id)->where('operation_key', $data['operation_key'])->first();
            if ($prior !== null) {
                if ($prior->issued_by !== $actor->id || ! hash_equals($prior->request_hash, $requestHash)) {
                    $this->refuse('This issue key belongs to a different quote.');
                }

                return $prior->load('order');
            }
            $agreement = app(StoreCommercialTerms::class)->resolve($tenant);
            $policy = StoreFulfilmentPolicy::query()->where('shop_id', $shop->id)->where('tenant_id', $tenant->id)->orderByDesc('version')->first();
            $choice = $data['choice'];
            if ($policy === null || $policy->id !== $choice['policy_id'] || ! in_array($choice['method'], $policy->snapshot['methods'] ?? [], true)
                || ($choice['method'] === 'delivery' && ! in_array($choice['destination']['country'] ?? null, $policy->snapshot['delivery_countries'] ?? [], true))
                || ($choice['method'] === 'collection' && $data['delivery_pesewas'] !== 0)) {
                $this->refuse('Use a current offered fulfilment method and agree the delivery address and charge.');
            }
            $variants = StoreVariant::query()->where('shop_id', $shop->id)->where('is_active', true)->whereKey(array_column($data['lines'], 'variant_id'))->get()->keyBy('id');
            $listings = StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->with('merchandise')->where('shop_id', $shop->id)
                ->where('listing_kind', StoreListing::KIND_MERCHANDISE)->where('status', StoreListing::STATUS_PUBLISHED)->whereNull('taken_down_at')
                ->whereIn('id', $variants->pluck('listing_id'))->get()->keyBy('id');
            $lines = [];
            $goods = 0;
            foreach ($data['lines'] as $line) {
                $variant = $variants->get($line['variant_id']);
                $listing = $variant === null ? null : $listings->get($variant->listing_id);
                if ($listing === null || $listing->merchandise === null || $listing->merchandise->order_mode !== 'made_to_order' || $listing->merchandise->archived_at !== null
                    || ($listing->merchandise->visibility === 'members' && ! Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
                        ->where('status', 'active')->whereRaw('lower(trim(email)) = ?', [$data['buyer_email']])->exists())) {
                    $this->refuse('Choose this seller’s available made-to-order items for an eligible buyer.');
                }
                $amount = $line['quantity'] * $line['unit_price_pesewas'];
                $goods += $amount;
                $lines[] = ['variant_id' => $variant->id, 'listing_id' => $listing->id, 'shop_id' => $shop->id,
                    'title_snapshot' => $listing->title, 'sku_snapshot' => $variant->sku, 'options_snapshot' => $variant->options,
                    'quantity' => $line['quantity'], 'unit_price_pesewas' => $line['unit_price_pesewas'], 'line_amount_pesewas' => $amount];
            }
            $principal = $goods + $data['printing_pesewas'] + $data['delivery_pesewas'];
            if ($principal < 1 || $principal > 10000000000) {
                $this->refuse('The quoted total must be positive and at most GHS100,000,000.');
            }
            $split = app(FeeCalculator::class)->forTerms($principal, new FeeTerms((float) $agreement->percentage, $agreement->cap_pesewas, $agreement->fee_bearer, (float) $agreement->gateway_estimate_rate));
            if ($split->organizerNet < 0) {
                $this->refuse('The quoted total does not cover the agreed fees.');
            }
            $id = (string) Str::uuid7();
            $snapshot = ['quote_id' => $id, 'goods_pesewas' => $goods, 'printing_pesewas' => $data['printing_pesewas'],
                'delivery_pesewas' => $data['delivery_pesewas'], 'production_terms' => $data['production_terms'], 'customisation' => $data['customisation']];
            $terms = $agreement->only(['id', 'version', 'percentage', 'cap_pesewas', 'fee_bearer', 'gateway_estimate_rate', 'refund_gateway_fee_bearer', 'collection_terms', 'refund_terms', 'support_contact', 'receipt_terms', 'payout_policy']);
            $terms['seller_quote'] = $snapshot;
            $order = StoreOrder::query()->create(['tenant_id' => $tenant->id, 'shop_id' => $shop->id, 'agreement_id' => $agreement->id,
                'buyer_email' => $data['buyer_email'], 'submission_key' => $data['operation_key'], 'cart_hash' => $requestHash, 'currency' => 'GHS',
                'principal_pesewas' => $principal, 'platform_fee_pesewas' => $split->platformFee, 'gateway_estimate_pesewas' => $split->gatewayFeeEstimate,
                'charged_pesewas' => $split->chargedAmount, 'fee_bearer' => $agreement->fee_bearer, 'settlement_mode' => $agreement->settlement_mode,
                'provider' => $agreement->provider, 'account_fingerprint' => $agreement->account_fingerprint,
                'seller_snapshot' => ['name' => $shop->name], 'terms_snapshot' => $terms, 'terms_hash' => hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR)),
                'expires_at' => now()->addHours($data['valid_for_hours'])]);
            $order->lines()->createMany($lines);
            StoreFulfilmentArrangement::query()->create(['order_id' => $order->id, 'shop_id' => $shop->id, 'tenant_id' => $tenant->id,
                'policy_id' => $policy->id, 'method' => $choice['method'], 'operation_key' => $data['operation_key'], 'fingerprint' => $requestHash,
                'policy_snapshot' => $policy->snapshot, 'destination' => $choice['destination'] ?? null]);

            return StoreSellerQuote::query()->create(['id' => $id, 'order_id' => $order->id, 'tenant_id' => $tenant->id, 'shop_id' => $shop->id,
                'issued_by' => $actor->id, 'issuer_name' => $actor->name, 'operation_key' => $data['operation_key'], 'request_hash' => $requestHash, 'snapshot' => $snapshot])->fresh(['order']);
        });
    }

    public function validCommitment(StoreOrder $order): bool
    {
        $quote = StoreSellerQuote::query()->where('order_id', $order->id)->where('tenant_id', $order->tenant_id)->where('shop_id', $order->shop_id)->first();
        $snapshot = $order->terms_snapshot['seller_quote'] ?? null;

        return $quote !== null && $quote->withdrawn_at === null && $snapshot === $quote->snapshot
            && ($snapshot['quote_id'] ?? null) === $quote->id
            && ($snapshot['goods_pesewas'] ?? -1) === (int) $order->lines()->sum('line_amount_pesewas')
            && $order->principal_pesewas === ($snapshot['goods_pesewas'] ?? -1) + ($snapshot['printing_pesewas'] ?? -1) + ($snapshot['delivery_pesewas'] ?? -1)
            && ! $order->lines()->doesntExist();
    }

    public function withdraw(StoreSellerQuote $quote, User $actor, string $reason): StoreSellerQuote
    {
        $this->authorize($quote->tenant, $actor);
        $reason = mb_trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            $this->refuse('Provide a reason for withdrawing this quote.');
        }

        return $quote->getConnection()->transaction(function () use ($quote, $actor, $reason): StoreSellerQuote {
            $order = app(CommerceInventoryLocks::class)->order($quote->order);
            $current = StoreSellerQuote::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($quote->id);
            $this->authorize($current->tenant, $actor);
            if ($current->withdrawn_at !== null) {
                return $current;
            }
            if ($order->state !== 'quoted' || $order->payment_state !== 'awaiting' || $order->attempts()->whereNotIn('status', ['failed', 'expired'])->exists()) {
                $this->refuse('Reconcile any existing payment before withdrawing a quote. Paid orders use the existing refund process.');
            }
            $order->update(['state' => 'cancelled', 'fulfilment_state' => 'cancelled', 'review_reason' => 'seller_quote_withdrawn']);
            $current->update(['withdrawn_by' => $actor->id, 'withdrawn_at' => now(), 'withdrawal_reason' => $reason]);

            return $current->fresh();
        });
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['quote' => $message]);
    }
}
