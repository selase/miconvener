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
     * What a USD price is charged as, said before the person pays; null for GHS.
     */
    public function note(): ?string
    {
        if ($this->rate === null) {
            return null;
        }

        return sprintf(
            'Charged as %s at the Bank of Ghana rate of %s (%s).',
            $this->charged()->format(),
            mb_rtrim(mb_rtrim(number_format($this->rate->rate, 4, '.', ''), '0'), '.'),
            $this->rate->asOf->format('j M Y'),
        );
    }

    /**
     * A price ready to show: the price itself and, for USD, what it is charged as.
     *
     * @return array{price: string, charge_note: string|null}
     */
    public function display(): array
    {
        return ['price' => $this->price->format(), 'charge_note' => $this->note()];
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
