<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Illuminate\Http\Request;

/**
 * Which currency a visitor sees prices in: their own choice from the currency
 * switch, otherwise GHS in Ghana and USD everywhere else (the country comes
 * from Cloudflare's CF-IPCountry header in front of Laravel Cloud; unknown is
 * USD). This only chooses what is displayed, so a spoofed header gains nothing
 * the switch does not already offer.
 */
final class VisitorCurrency
{
    public const string COOKIE = 'mc_currency';

    /** @var list<string> */
    public const array SUPPORTED = ['GHS', 'USD'];

    public function resolve(Request $request): string
    {
        $chosen = mb_strtoupper((string) $request->cookie(self::COOKIE));

        if (in_array($chosen, self::SUPPORTED, true)) {
            return $chosen;
        }

        return mb_strtoupper((string) $request->header('CF-IPCountry')) === 'GH' ? 'GHS' : 'USD';
    }
}
