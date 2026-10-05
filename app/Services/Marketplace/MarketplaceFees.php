<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Tenant;

/**
 * The marketplace's two fees, in one place so what is charged and what is
 * shown can't disagree:
 *
 * - commission: what MiConvener keeps from the business's price
 *   (services.marketplace.commission_percent, 10% by default);
 * - service fee: what the buyer pays on top, by the buyer's plan
 *   (services.marketplace.buyer_fee_percent). A buyer with no MiConvener
 *   plan pays the Free rate.
 */
final class MarketplaceFees
{
    public function commissionPercent(): float
    {
        return max(0.0, (float) config('services.marketplace.commission_percent', 10));
    }

    public function commissionOn(int $priceAmount): int
    {
        return (int) round(max(0, $priceAmount) * $this->commissionPercent() / 100);
    }

    public function buyerFeePercent(?Tenant $buyer): float
    {
        $rates = (array) config('services.marketplace.buyer_fee_percent', []);
        $plan = $buyer?->package->slug ?? 'free';

        return max(0.0, (float) ($rates[$plan] ?? $rates['free'] ?? 0));
    }

    public function buyerFeeOn(?Tenant $buyer, int $amount): int
    {
        return (int) round(max(0, $amount) * $this->buyerFeePercent($buyer) / 100);
    }

    /**
     * How a payment splits between the platform and the business.
     *
     * @return array{clearing: int, commission: int, platform: int, payable: int}
     */
    public function split(int $amountPaid, int $gatewayFee, int $buyerFee): array
    {
        $clearing = max(0, $amountPaid - $gatewayFee);
        $commission = $this->commissionOn($amountPaid - $buyerFee);
        $platform = min($clearing, $commission + max(0, $buyerFee));

        return [
            'clearing' => $clearing,
            'commission' => $commission,
            'platform' => $platform,
            'payable' => max(0, $clearing - $platform),
        ];
    }
}
