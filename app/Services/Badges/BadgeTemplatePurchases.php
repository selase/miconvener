<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\BadgeLibraryVersion;
use App\Models\BadgeTemplateLicence;
use App\Models\BadgeTemplatePurchase;
use App\Models\Event;
use App\Models\StoreOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\EventStaffScope;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Finance\FeeCalculator;
use App\Services\Finance\FeeTerms;
use App\Services\Marketplace\CommerceInventoryLocks;
use App\Services\Marketplace\CommerceOrderLocks;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BadgeTemplatePurchases
{
    public function __construct(private readonly BadgeTemplateReadiness $readiness, private readonly CommerceInventoryLocks $locks, private readonly CommerceOrderLocks $orderLocks, private readonly FeeCalculator $fees) {}

    public function quote(Event $event, BadgeLibraryVersion $version, User $actor, string $verifiedPayerEmail, string $operationKey): BadgeTemplatePurchase
    {
        $event = Event::query()->whereKey($event->id)->firstOrFail();
        $this->authorize($actor, $event);
        if (! Str::isUuid($operationKey) || ! filter_var($verifiedPayerEmail, FILTER_VALIDATE_EMAIL)) {
            $this->refuse();
        }
        $email = PlatformAttendeeVerification::normalise($verifiedPayerEmail);
        $buyer = Tenant::query()->whereKey($event->tenant_id)->firstOrFail();
        $agreement = $this->readiness->agreement($buyer);

        return $event->getConnection()->transaction(function () use ($event, $version, $actor, $email, $operationKey, $buyer, $agreement): BadgeTemplatePurchase {
            $this->locks->tenant($agreement->tenant_id);
            if ($buyer->id !== $agreement->tenant_id) {
                $this->locks->tenant($buyer->id);
            }
            $this->authorize($actor, $event);
            $agreement = $this->readiness->agreement($buyer);
            $version = BadgeLibraryVersion::query()->with('design')->whereKey($version->id)->firstOrFail();
            $family = $version->design;
            $price = app(\App\Services\Pricing\PriceBook::class)->badgeTemplate();
            if ($family->scope !== 'platform' || $family->price_pesewas !== $price->minor || $family->tenant_id !== null || $family->withdrawn_at !== null) {
                $this->refuse();
            }
            $licence = BadgeTemplateLicence::query()->firstOrCreate(['buyer_tenant_id' => $buyer->id, 'event_id' => $event->id, 'family_id' => $family->id]);
            $prior = BadgeTemplatePurchase::query()->where('licence_id', $licence->id)->whereIn('state', ['pending', 'review', 'paid'])->latest()->first();
            if ($prior !== null) {
                $order = $this->orderLocks->order($prior->order);
                if ($licence->fresh()->state === 'active' && $prior->state === 'paid') {
                    return $prior;
                }
                if ($order->expires_at->isFuture() && $order->buyer_email === $email) {
                    return $prior;
                }
                if ($prior->state === 'review' || $order->state === 'review' || $order->attempts()->whereIn('status', ['initializing', 'unknown', 'ready', 'captured', 'review', 'succeeded'])->exists()) {
                    throw ValidationException::withMessages(['checkout' => 'The existing payment needs verification before another purchase.']);
                }
                if ($order->buyer_email !== $email || $order->state !== 'quoted') {
                    $this->refuse();
                }
                $prior->update(['state' => 'cancelled']);
                $order->update(['state' => 'cancelled']);
            }
            if (StoreOrder::query()->where('shop_id', $agreement->shop_id)->where('buyer_email', $email)->where('submission_key', $operationKey)->exists()) {
                $this->refuse();
            }
            app(BadgeTemplateReserve::class)->assertCapacity($agreement->tenant, $price->minor);
            $licence = BadgeTemplateLicence::query()->whereKey($licence->id)->lockForUpdate()->firstOrFail();
            $terms = ['requested_by' => $actor->id, 'seller_name' => $agreement->shop->name, 'commercial_policy_reference' => config('services.badge_templates.commercial_approval'), 'buyer_tenant_id' => $buyer->id, 'event_id' => $event->id, 'event_name' => $event->name,
                'family_id' => $family->id, 'version_id' => $version->id, 'design_name' => $family->name,
                'total_pesewas' => $price->minor, 'currency' => $price->currency, 'refund_days' => 7, 'licence_timezone' => \App\Models\CommunitySettings::for($buyer)->timezone,
                'licence' => 'One event, unlimited attendees, edits and reprints. Later family versions may be explicitly applied.',
                'refund_terms' => 'Full refund within seven calendar days while unused. Requesting clean output records use before download.',
                'support_contact' => $agreement->support_contact, 'receipt_terms' => $agreement->receipt_terms,
                'agreement_id' => $agreement->id, 'agreement_version' => $agreement->version,
                'refund_gateway_fee_bearer' => 'platform'];
            $hash = hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
            $split = $this->fees->forTerms($price->minor, new FeeTerms(0, null, 'organizer', (float) $agreement->gateway_estimate_rate));
            $order = StoreOrder::query()->create(['tenant_id' => $agreement->tenant_id, 'shop_id' => $agreement->shop_id, 'agreement_id' => $agreement->id,
                'buyer_email' => $email, 'submission_key' => $operationKey, 'cart_hash' => $hash, 'currency' => $price->currency, 'offering_type' => 'badge_template',
                'principal_pesewas' => $price->minor, 'platform_fee_pesewas' => 0, 'gateway_estimate_pesewas' => $split->gatewayFeeEstimate, 'charged_pesewas' => $price->minor, 'fee_bearer' => 'organizer',
                'settlement_mode' => $agreement->settlement_mode, 'provider' => $agreement->provider, 'account_fingerprint' => $agreement->account_fingerprint,
                'seller_snapshot' => ['name' => $agreement->shop->name], 'terms_snapshot' => $terms, 'terms_hash' => $hash, 'expires_at' => now()->addMinutes(10)]);
            $purchase = BadgeTemplatePurchase::query()->create(['licence_id' => $licence->id, 'order_id' => $order->id, 'buyer_tenant_id' => $buyer->id,
                'event_id' => $event->id, 'family_id' => $family->id, 'version_id' => $version->id, 'terms_snapshot' => $terms]);
            $order->lines()->create(['shop_id' => $agreement->shop_id, 'offering_type' => 'badge_template', 'badge_purchase_id' => $purchase->id,
                'title_snapshot' => $family->name, 'sku_snapshot' => 'badge-template-'.$family->id, 'options_snapshot' => [], 'quantity' => 1, 'unit_price_pesewas' => $price->minor, 'line_amount_pesewas' => $price->minor]);

            return $purchase;
        });
    }

    public function authorize(User $actor, Event $event): void
    {
        $actor = User::withoutGlobalScopes()->whereKey($actor->id)->firstOrFail();
        abort_unless(($actor->status === null || $actor->status === User::STATUS_ACTIVE)
            && ($actor->isGlobalSuperAdmin() || $actor->tenants()->whereKey($event->tenant_id)->exists()), 403);
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($event->tenant_id);
            abort_unless($actor->can('purchase badge-templates') && EventStaffScope::canAccessEvent($actor, $event), 403);
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['checkout' => 'This paid template selection is unavailable.']);
    }
}
