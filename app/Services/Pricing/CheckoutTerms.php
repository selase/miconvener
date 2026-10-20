<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Tenant;

/**
 * What a platform checkout needs to carry besides the amount. A GHS price
 * charged to an organisation that is not Ghana eligible can be paid only by
 * Ghana mobile money or a card, and the verified payment then proves where the
 * organisation is. That is decided by the price actually charged, not the
 * currency asked for: when USD is unavailable the fallback GHS price is no
 * reason to skip the proof.
 */
final readonly class CheckoutTerms
{
    /** The channels that can prove Ghana: Paystack's mobile money and card. */
    public const array PROOF_CHANNELS = ['mobile_money', 'card'];

    private function __construct(public bool $proofRequired, public ?Money $usdAlternative = null) {}

    /**
     * @param  Money|null  $usdAlternative  what the same item costs in USD, when there is one: the price a
     *                                      foreign card falls back to if it pays the Ghana price
     */
    public static function for(Tenant $tenant, Money $price, ?Money $usdAlternative = null): self
    {
        $proofRequired = $price->currency === 'GHS' && ! $tenant->isGhanaEligible();

        return new self($proofRequired, $proofRequired && $usdAlternative?->currency === 'USD' ? $usdAlternative : null);
    }

    /** A price someone at MiConvener agreed with this organisation: no proof needed. */
    public static function agreed(): self
    {
        return new self(false);
    }

    /**
     * What to add to the checkout metadata: the quote, whether proof is
     * required, and (when it is) the channels Paystack may offer.
     *
     * @return array<string, mixed>
     */
    public function metadata(ChargeQuote $quote): array
    {
        $metadata = ['pricing' => [...$quote->meta(), 'proof_required' => $this->proofRequired]];

        if ($this->usdAlternative !== null) {
            $metadata['pricing']['usd_alternative_minor'] = $this->usdAlternative->minor;
        }

        if ($this->proofRequired) {
            $metadata['allowed_channels'] = self::PROOF_CHANNELS;
        }

        return $metadata;
    }
}
