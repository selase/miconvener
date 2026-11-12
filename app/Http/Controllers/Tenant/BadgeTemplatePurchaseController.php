<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\PurchaseBadgeTemplateRequest;
use App\Models\BadgeLibraryVersion;
use App\Models\BadgeTemplatePurchase;
use App\Models\Event;
use App\Services\Badges\BadgeTemplatePurchases;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Marketplace\StorePayments;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class BadgeTemplatePurchaseController extends Controller
{
    public function store(PurchaseBadgeTemplateRequest $request, string $subdomain, string $event): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404);
        $event = Event::query()->where('tenant_id', $tenant->id)->whereKey($event)->firstOrFail();
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        abort_if($email === null, 403, 'Verify the payer email before purchasing.');
        $version = BadgeLibraryVersion::query()->whereKey($request->validated('version_id'))->firstOrFail();
        $purchase = app(BadgeTemplatePurchases::class)->quote($event, $version, $request->user(), $email, $request->validated('operation_key'));

        return $this->payload($purchase);
    }

    public function checkout(PurchaseBadgeTemplateRequest $request, string $subdomain, string $event, string $purchase): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404);
        $event = Event::query()->where('tenant_id', $tenant->id)->whereKey($event)->firstOrFail();
        app(BadgeTemplatePurchases::class)->authorize($request->user(), $event);
        $purchase = BadgeTemplatePurchase::query()->where('event_id', $event->id)->where('buyer_tenant_id', $tenant->id)->whereKey($purchase)->firstOrFail();
        abort_unless($purchase->version_id === $request->validated('version_id'), 422);
        $attempt = app(StorePayments::class)->start($purchase->order, $request->session(), $request->validated('operation_key'), $request->validated('accepted_terms_hash'));

        return response()->json(['status' => $attempt?->status, 'checkout_url' => $attempt?->checkout_url])->header('Cache-Control', 'private, no-store');
    }

    private function payload(BadgeTemplatePurchase $purchase): JsonResponse
    {
        return response()->json(['purchase_id' => $purchase->id, 'state' => $purchase->state, 'terms' => $purchase->terms_snapshot,
            'terms_hash' => $purchase->order->terms_hash, 'expires_at' => $purchase->order->expires_at->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }
}
