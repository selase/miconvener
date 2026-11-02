<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Store\IssueStoreSellerQuoteRequest;
use App\Http\Requests\Tenant\Store\WithdrawStoreSellerQuoteRequest;
use App\Models\StoreFulfilmentPolicy;
use App\Models\StoreListing;
use App\Models\StoreSellerQuote;
use App\Models\Tenant;
use App\Scopes\MerchandiseVisibilityScope;
use App\Services\Marketplace\CommerceCatalogRollout;
use App\Services\Marketplace\StoreCommercialTerms;
use App\Services\Marketplace\StoreOrderPayload;
use App\Services\Marketplace\StoreSellerQuotes;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class StoreSellerQuoteController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $this->seller($request);
        $shop = $tenant->shop;
        $policy = $shop === null ? null : StoreFulfilmentPolicy::query()->where('shop_id', $shop->id)->orderByDesc('version')->first();
        $canPrice = app(StoreSellerQuotes::class)->canManage($tenant, $request->user(), true);
        $canIssue = $canPrice && $policy !== null && app(CommerceCatalogRollout::class)->enabled($tenant)
            && $tenant->features()->where('feature_key', 'commerce_checkout')->where('enabled', true)->exists();
        if ($canIssue) {
            try {
                app(StoreCommercialTerms::class)->resolve($tenant);
            } catch (ValidationException) {
                $canIssue = false;
            }
        }
        $variants = [];
        $listings = $shop === null ? collect() : StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)
            ->where('shop_id', $shop->id)->where('listing_kind', StoreListing::KIND_MERCHANDISE)->where('status', StoreListing::STATUS_PUBLISHED)->whereNull('taken_down_at')
            ->whereHas('merchandise', fn ($details) => $details->where('order_mode', 'made_to_order')->whereNull('archived_at'))
            ->with(['merchandise', 'variants' => fn ($variants) => $variants->where('is_active', true)])->orderBy('title')->get();
        foreach ($listings as $listing) {
            foreach ($listing->variants as $variant) {
                $options = [];
                foreach ($variant->options as $key => $value) {
                    $options[] = $key.': '.$value;
                }
                $variants[] = ['id' => $variant->id, 'label' => $listing->title.' · '.implode(' · ', $options),
                    'visibility' => $listing->merchandise->visibility, 'price_pesewas' => $variant->price_pesewas];
            }
        }
        $quotes = StoreSellerQuote::query()->where('tenant_id', $tenant->id)->with(['order.lines', 'order.tenant.features', 'order.attempts', 'order.sellerQuote', 'order.receipt.outbox', 'order.fulfilmentArrangement', 'order.collection'])->latest()->paginate(15);
        $quotes->through(fn (StoreSellerQuote $quote): array => $this->payload($quote, $canPrice));
        $data = ['quotes' => $quotes, 'variants' => $variants, 'fulfilment_policy' => $policy === null ? null : ['policy_id' => $policy->id, ...$policy->snapshot],
            'can_issue' => $canIssue,
            'issuance_message' => $canIssue ? null : 'Issuing payment links is paused. Catalogue-pricing and order-management permissions, approved checkout, commercial terms and a current fulfilment policy are required. Existing quote history remains available.'];
        $response = $request->expectsJson() ? response()->json($data) : Inertia::render('Tenant/Store/Quotes/Index', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function store(IssueStoreSellerQuoteRequest $request, StoreSellerQuotes $quotes): JsonResponse
    {
        $tenant = $this->seller($request);
        $quote = $quotes->issue($tenant, $request->user(), $request->validated());

        return response()->json(['quote' => $this->payload($quote)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function withdraw(WithdrawStoreSellerQuoteRequest $request, string $subdomain, string $quote, StoreSellerQuotes $quotes): JsonResponse
    {
        $tenant = $this->seller($request);
        $owned = StoreSellerQuote::query()->where('tenant_id', $tenant->id)->findOrFail($quote);
        $withdrawn = $quotes->withdraw($owned, $request->user(), $request->validated('reason'));

        return response()->json(['quote' => $this->payload($withdrawn)])->header('Cache-Control', 'private, no-store');
    }

    private function seller(Request $request): Tenant
    {
        $tenant = app(TenantContext::class)->getTenant() ?? abort(403);
        app(StoreSellerQuotes::class)->authorize($tenant, $request->user());

        return $tenant;
    }

    /** @return array<string,mixed> */
    private function payload(StoreSellerQuote $quote, bool $allowChanges = true): array
    {
        $order = $quote->order;
        $order->loadMissing('attempts');
        $canWithdraw = $allowChanges && in_array($order->state, ['quoted', 'awaiting_payment'], true) && $order->payment_state === 'awaiting'
            && $quote->withdrawn_at === null && ! $order->attempts->contains(fn ($attempt): bool => ! in_array($attempt->status, ['failed', 'expired'], true));

        return ['id' => $quote->id, 'buyer_email' => $order->buyer_email, 'issued_at' => $quote->created_at?->toIso8601String(),
            'withdrawn_at' => $quote->withdrawn_at?->toIso8601String(), 'withdrawal_reason' => $quote->withdrawal_reason,
            'can_withdraw' => $canWithdraw, 'order' => [...app(StoreOrderPayload::class)->buyer($order), 'destination' => $order->fulfilmentArrangement?->destination],
            'payment_link' => mb_rtrim((string) config('app.url'), '/').route('store.orders.show', ['order' => $order->id], false)];
    }
}
