<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\StoreCommercialAgreement;
use App\Models\Tenant;
use App\Services\Marketplace\StoreCommercialTerms;
use Illuminate\Validation\ValidationException;

final class BadgeTemplateReadiness
{
    public function agreement(Tenant $buyer, bool $requireEnabled = true): StoreCommercialAgreement
    {
        $sellerId = config('services.badge_templates.seller_tenant_id');
        $seller = is_string($sellerId) ? Tenant::query()->whereKey($sellerId)->first() : null;
        if ($seller === null || blank(config('services.badge_templates.commercial_approval'))
            || ($requireEnabled && ! $buyer->features()->where('feature_key', 'paid_badge_templates')->where('enabled', true)->exists())) {
            $this->refuse();
        }
        $agreement = app(StoreCommercialTerms::class)->resolve($seller, StoreCommercialAgreement::SCOPE_BADGE_TEMPLATE);
        if ((float) $agreement->percentage !== 0.0 || $agreement->fee_bearer !== 'organizer'
            || $agreement->refund_gateway_fee_bearer !== 'platform' || blank($agreement->receipt_terms) || blank($agreement->support_contact)) {
            $this->refuse();
        }

        return $agreement;
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['checkout' => 'Paid templates are unavailable until platform payment readiness is approved.']);
    }
}
