<?php

declare(strict_types=1);

namespace App\Services\Pricing\Sources;

use App\Contracts\ExchangeRateSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the Bank of Ghana daily interbank FX page. The latest rates are in the
 * page's first table as rows of: date, currency, pair, buying, selling, mid.
 * The mid rate is the one used.
 */
final class BankOfGhanaRateSource implements ExchangeRateSource
{
    public function name(): string
    {
        return 'bank_of_ghana';
    }

    public function fetchUsdGhs(): ?array
    {
        try {
            $response = Http::timeout(10)->withUserAgent('MiConvener exchange-rate check')->get((string) config('pricing.fx.bog_url'));
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return self::parse($response->body());
    }

    /**
     * @return array{rate: float, as_of: string}|null
     */
    public static function parse(string $html): ?array
    {
        if (! preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows)) {
            return null;
        }

        foreach ($rows[1] as $row) {
            if (! str_contains($row, 'USDGHS')) {
                continue;
            }

            preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row, $cells);
            $values = array_map(fn (string $cell): string => trim(html_entity_decode(strip_tags($cell))), $cells[1]);
            $pairAt = array_search('USDGHS', $values, true);

            if ($pairAt === false || ! isset($values[$pairAt + 3]) || ! is_numeric($values[$pairAt + 3])) {
                continue;
            }

            try {
                $date = CarbonImmutable::parse($values[0])->toDateString();
            } catch (Throwable) {
                continue;
            }

            $rate = (float) $values[$pairAt + 3];

            return $rate > 0 ? ['rate' => $rate, 'as_of' => $date] : null;
        }

        return null;
    }
}
