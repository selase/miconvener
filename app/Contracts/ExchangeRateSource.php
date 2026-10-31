<?php

declare(strict_types=1);

namespace App\Contracts;

interface ExchangeRateSource
{
    /** The name stored with every rate this source returns. */
    public function name(): string;

    /**
     * The latest published USD to GHS rate, or null when unavailable.
     *
     * @return array{rate: float, as_of: string}|null
     */
    public function fetchUsdGhs(): ?array;
}
