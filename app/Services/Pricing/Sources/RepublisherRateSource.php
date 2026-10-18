<?php

declare(strict_types=1);

namespace App\Services\Pricing\Sources;

use App\Contracts\ExchangeRateSource;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Backup: a service that republishes the Bank of Ghana rate as JSON. Expects
 * `rate` and `date` in the response. Disabled when no URL is configured.
 */
final class RepublisherRateSource implements ExchangeRateSource
{
    public function name(): string
    {
        return 'republisher';
    }

    public function fetchUsdGhs(): ?array
    {
        $url = (string) config('pricing.fx.republisher_url');

        if ($url === '') {
            return null;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(6)->acceptJson()
                ->withHeaders(array_filter(['Authorization' => config('pricing.fx.republisher_key') ? 'Bearer '.config('pricing.fx.republisher_key') : null]))
                ->get($url, ['base' => 'USD', 'quote' => 'GHS']);
        } catch (Throwable) {
            return null;
        }

        $rate = (float) $response->json('rate');
        $date = (string) $response->json('date');

        return $response->successful() && $rate > 0 && $date !== '' ? ['rate' => $rate, 'as_of' => $date] : null;
    }
}
