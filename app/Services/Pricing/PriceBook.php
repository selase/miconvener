<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Package;
use App\Services\Billing\TenantAddonService;

/**
 * Every platform price shown or charged, in GHS or USD. GHS comes from where it
 * always lived (packages, the add-on catalogue, token packs); USD from the
 * fixed prices beside them. An item without a USD price, or any item while USD
 * is paused for a stale rate, is priced in GHS.
 */
final class PriceBook
{
    public function __construct(private readonly ExchangeRates $rates) {}

    public function plan(Package $package, string $interval, string $currency): Money
    {
        $ghs = $interval === 'year' ? ($package->yearly_price ?? (float) $package->price * 10) : $package->price;
        $usd = $interval === 'year' ? $package->yearly_price_usd : $package->price_usd;

        return $this->pick((int) round((float) $ghs * 100), $usd === null ? null : (int) round((float) $usd * 100), $currency);
    }

    public function addon(string $key, string $currency, int $multiplier = 1): Money
    {
        $config = TenantAddonService::CATALOG[$key];
        return $this->pick($config['unit_price'] * $multiplier, $config['usd_unit_price'] * $multiplier, $currency);
    }

    public function tokenPack(string $key, string $currency): Money
    {
        $pack = (array) config("llm.token_packs.{$key}");
        $usd = isset($pack['price_usd']) ? (int) round((float) $pack['price_usd'] * 100) : null;

        return $this->pick((int) round((float) $pack['price'] * 100), $usd, $currency);
    }

    private function pick(int $ghsMinor, ?int $usdMinor, string $currency): Money
    {
        if ($currency === 'USD' && $usdMinor !== null && ! $this->rates->usdPaused()) {
            return new Money($usdMinor, 'USD');
        }

        return new Money($ghsMinor, 'GHS');
    }
}
