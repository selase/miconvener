<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Which currency prices are shown in. Nobody can choose the Ghana price: it
 * follows where the money is, not what someone clicks.
 *
 * - An organisation keeps the currency it was settled in: Ghana-eligible
 *   organisations see GHS and organisations onboarded in USD see USD, wherever
 *   their people are browsing from.
 * - Everyone else sees the currency their country gives them: GHS from Ghana
 *   (Cloudflare's CF-IPCountry header in front of Laravel Cloud), USD from
 *   anywhere else or when the country is unknown. A visitor in Ghana may choose
 *   to see USD; asking for GHS from anywhere else changes nothing.
 *
 * This chooses what is displayed. What is charged is decided at checkout.
 */
final class VisitorCurrency
{
    public const string COOKIE = 'mc_currency';

    /** @var list<string> */
    public const array SUPPORTED = ['GHS', 'USD'];

    public function resolve(Request $request, ?Tenant $tenant = null): string
    {
        $tenant ??= app(TenantContext::class)->getTenant();

        if ($tenant instanceof Tenant && $tenant->pricing_region !== null) {
            return $tenant->isGhanaEligible() ? 'GHS' : 'USD';
        }

        return $this->forVisitor($request);
    }

    /**
     * What a visitor with no organisation sees: their country's currency, or USD by their choice.
     */
    public function forVisitor(Request $request): string
    {
        if (mb_strtoupper((string) $request->cookie(self::COOKIE)) === 'USD') {
            return 'USD';
        }

        return $this->inGhana($request) ? 'GHS' : 'USD';
    }

    public function inGhana(Request $request): bool
    {
        return mb_strtoupper((string) $request->header('CF-IPCountry')) === 'GH';
    }

    /**
     * Whether to offer the switch: only a visitor in Ghana, who has no
     * organisation yet, has anything to choose.
     */
    public function canChoose(Request $request, ?Tenant $tenant = null): bool
    {
        $tenant ??= app(TenantContext::class)->getTenant();

        return $this->inGhana($request) && ! ($tenant instanceof Tenant && $tenant->pricing_region !== null);
    }
}
