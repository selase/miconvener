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
    public function payer(\Illuminate\Http\Request $request, string $subdomain, string $event): JsonResponse
    {
        $this->purchaseEvent($request, $event);

        return response()->json(['verified_email' => app(PlatformAttendeeVerification::class)->verifiedEmail($request->session())])->header('Cache-Control', 'private, no-store');
    }

    public function verifyPayer(\App\Http\Requests\Tenant\BadgeTemplatePayerRequest $request, string $subdomain, string $event): JsonResponse
    {
        $this->purchaseEvent($request, $event);
        $verification = app(PlatformAttendeeVerification::class);
        if ($request->filled('code')) {
            $confirmed = $verification->confirm($request->session(), $request->validated('email'), $request->validated('code'), $request->ip());
            abort_unless($confirmed, 422, 'The code has expired or could not be verified. Request another code.');

            return response()->json(['email' => $verification->verifiedEmail($request->session()), 'verified_email' => $verification->verifiedEmail($request->session())])->header('Cache-Control', 'private, no-store');
        }

        $result = $verification->requestSend($request->validated('email'), $request->ip(), $request->validated('turnstile_token'));
        $status = match ($result['status']) {
            \App\Services\Events\AttendeePortalRateLimiter::RESULT_ALLOWED => 202,
            \App\Services\Events\AttendeePortalRateLimiter::RESULT_RATE_LIMITED => 429,
            \App\Services\Events\AttendeePortalRateLimiter::RESULT_CHALLENGE_REQUIRED => 428,
            default => 422,
        };

        return response()->json($result, $status)->header('Cache-Control', 'private, no-store');
    }

    public function draft(\Illuminate\Http\Request $request, string $subdomain, string $event): JsonResponse
    {
        $event = $this->purchaseEvent($request, $event);
        $stored = \Illuminate\Support\Facades\Cache::get($this->draftKey($request, $event));
        $draft = is_string($stored) ? \Illuminate\Support\Facades\Crypt::decrypt($stored) : null;

        $metadata = null;
        if (is_array($draft)) {
            $version = app(\App\Services\Badges\BadgeLibraryService::class)->visibleVersion($event, $draft['version_id']);
            $settings = $version->snapshotSettings();
            $metadata = ['id' => $version->id, 'family_id' => $version->design_id, 'version' => $version->version,
                'width_mm' => $settings['width_mm'] ?? 54, 'height_mm' => $settings['height_mm'] ?? 85,
                'orientation' => $settings['orientation'] ?? 'portrait', 'compositions' => array_keys($settings['compositions'] ?? [])];
        }

        return response()->json(['draft' => $draft, 'version' => $metadata])->header('Cache-Control', 'private, no-store');
    }

    public function saveDraft(\App\Http\Requests\Tenant\BadgeTemplateDraftRequest $request, string $subdomain, string $event): JsonResponse
    {
        $event = $this->purchaseEvent($request, $event);
        app(\App\Services\Badges\BadgeLibraryService::class)->visibleVersion($event, $request->validated('version_id'));
        if ($request->filled('purchase_id')) {
            BadgeTemplatePurchase::query()->where('event_id', $event->id)->where('buyer_tenant_id', $event->tenant_id)->findOrFail($request->validated('purchase_id'));
        }
        \Illuminate\Support\Facades\Cache::put($this->draftKey($request, $event), \Illuminate\Support\Facades\Crypt::encrypt($request->validated()), now()->addHours(24));

        return response()->json(['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function show(\Illuminate\Http\Request $request, string $subdomain, string $event, string $purchase): JsonResponse
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404);
        $event = Event::query()->where('tenant_id', $tenant->id)->findOrFail($event);
        app(BadgeTemplatePurchases::class)->authorize($request->user(), $event);
        $purchase = BadgeTemplatePurchase::query()->where('event_id', $event->id)->where('buyer_tenant_id', $tenant->id)->findOrFail($purchase);

        return $this->payload($purchase);
    }

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

        return response()->json(['status' => $attempt?->status, 'checkout_url' => $attempt?->status === 'ready' ? $attempt->checkout_url : null])->header('Cache-Control', 'private, no-store');
    }

    private function purchaseEvent(\Illuminate\Http\Request $request, string $id): Event
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_if($tenant === null, 404);
        $event = Event::query()->where('tenant_id', $tenant->id)->findOrFail($id);
        app(BadgeTemplatePurchases::class)->authorize($request->user(), $event);

        return $event;
    }

    private function draftKey(\Illuminate\Http\Request $request, Event $event): string
    {
        return 'badge-purchase-draft:'.$event->id.':'.$request->user()->id;
    }

    private function payload(BadgeTemplatePurchase $purchase): JsonResponse
    {
        return response()->json(app(\App\Services\Badges\BadgeTemplatePurchasePayload::class)->forPurchase($purchase))->header('Cache-Control', 'private, no-store');
    }
}
