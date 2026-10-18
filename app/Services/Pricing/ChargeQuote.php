<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * What is actually charged for a price: Paystack settles the platform in GHS,
 * so a USD price is converted at the current Bank of Ghana rate (rounded half
 * up to the pesewa) and the rate is kept with the payment.
 */
final class ChargeQuote
{
    private int $ghsMinor = 0;

    private Money $price;

    private ?Rate $rate = null;

    public function __construct(private readonly ExchangeRates $rates) {}

    public function for(Money $price): self
    {
        $quote = new self($this->rates);
        $quote->price = $price;

        if ($price->currency === 'USD' && ($rate = $this->rates->usdToGhs()) !== null) {
            $quote->rate = $rate;
            $quote->ghsMinor = (int) round($price->minor * $rate->rate, 0, PHP_ROUND_HALF_UP);
        } else {
            $quote->ghsMinor = $price->minor;
        }

        return $quote;
    }

    public function ghsMinor(): int
    {
        return $this->ghsMinor;
    }

    public function price(): Money
    {
        return $this->price;
    }

    /** The GHS amount to be charged, as text ("GHS 223.63"). */
    public function charged(): Money
    {
        return new Money($this->ghsMinor, 'GHS');
    }

    /**
     * @return array{display_currency: string, display_amount_minor: int, fx_rate: float|null, fx_as_of: string|null, fx_source: string|null}
     */
    public function meta(): array
    {
        return [
            'display_currency' => $this->price->currency,
            'display_amount_minor' => $this->price->minor,
            'fx_rate' => $this->rate?->rate,
            'fx_as_of' => $this->rate?->asOf->toDateString(),
            'fx_source' => $this->rate?->source,
        ];
    }
}
