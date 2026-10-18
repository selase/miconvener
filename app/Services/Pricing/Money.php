<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * An amount in minor units (pesewas or cents) and its currency.
 */
final readonly class Money
{
    public function __construct(public int $minor, public string $currency) {}

    /** "GHS 249", "US$ 19", "GHS 223.63": no decimals for whole amounts. */
    public function format(): string
    {
        $major = $this->minor / 100;
        $amount = $this->minor % 100 === 0 ? number_format($major) : number_format($major, 2);

        return ($this->currency === 'USD' ? 'US$' : $this->currency).' '.$amount;
    }
}
