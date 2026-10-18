<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Contracts\ExchangeRateSource;
use App\Models\ExchangeRate;
use App\Services\Pricing\Sources\BankOfGhanaRateSource;
use App\Services\Pricing\Sources\RepublisherRateSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The USD to GHS rate used to charge USD prices in GHS. Sources are asked at
 * most once per cache window; every rate fetched is stored, so the last known
 * rate survives an outage. A rate older than the alert threshold is still used
 * but warned about once a day; older than the pause threshold, USD charging is
 * paused and visitors are charged the GHS list price instead.
 */
final class ExchangeRates
{
    private const string CACHE_KEY = 'pricing.fx.usd_ghs';

    public function usdToGhs(): ?Rate
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes((int) config('pricing.fx.cache_minutes', 90)), fn (): ?Rate => $this->fetchAndStore() ?? $this->latestStored());
    }

    public function refresh(bool $force = false): ?Rate
    {
        if (! $force) {
            return $this->usdToGhs();
        }

        $rate = $this->fetchAndStore() ?? $this->latestStored();
        Cache::put(self::CACHE_KEY, $rate, now()->addMinutes((int) config('pricing.fx.cache_minutes', 90)));
        $this->warnIfStale($rate);

        return $rate;
    }

    public function usdPaused(): bool
    {
        $rate = $this->usdToGhs();

        return $rate === null || $rate->ageDays() > (int) config('pricing.fx.pause_after_days', 10);
    }

    private function fetchAndStore(): ?Rate
    {
        foreach ($this->sources() as $source) {
            $fetched = $source->fetchUsdGhs();

            if ($fetched === null) {
                continue;
            }

            ExchangeRate::query()->firstOrCreate(
                ['base' => 'USD', 'quote' => 'GHS', 'as_of' => $fetched['as_of'], 'source' => $source->name()],
                ['rate' => $fetched['rate'], 'fetched_at' => now()],
            );

            return new Rate($fetched['rate'], CarbonImmutable::parse($fetched['as_of']), $source->name());
        }

        return null;
    }

    private function latestStored(): ?Rate
    {
        $row = ExchangeRate::query()->where('base', 'USD')->where('quote', 'GHS')->orderByDesc('as_of')->orderByDesc('fetched_at')->first();

        return $row ? new Rate((float) $row->rate, CarbonImmutable::parse($row->as_of), $row->source) : null;
    }

    private function warnIfStale(?Rate $rate): void
    {
        if ($rate === null || $rate->ageDays() <= (int) config('pricing.fx.alert_after_days', 4)) {
            return;
        }

        if (Cache::add('pricing.fx.stale_warned.'.now()->toDateString(), true, now()->endOfDay())) {
            Log::warning('pricing.fx.stale', ['as_of' => $rate->asOf->toDateString(), 'age_days' => $rate->ageDays(), 'source' => $rate->source]);
        }
    }

    /**
     * @return list<ExchangeRateSource>
     */
    private function sources(): array
    {
        return [app(BankOfGhanaRateSource::class), app(RepublisherRateSource::class)];
    }
}
