<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\Event;

/**
 * Resolves the commercial terms that apply to one event.
 *
 * Every setting follows the same cascade — event, then tenant, then package
 * default, then global config — so a bargain struck with one tenant, or a
 * waiver granted to one charity event, is a data change rather than a deploy.
 */
final class PlatformFeeResolver
{
    public const string BEARER_ORGANIZER = 'organizer';

    public const string BEARER_ATTENDEE = 'attendee';

    public function percentageFor(Event $event): float
    {
        return $event->effectivePlatformFeePercentage();
    }

    public function capAmountFor(Event $event): ?int
    {
        return $event->effectivePlatformFeeCapAmount();
    }

    /**
     * An unrecognised bearer resolves to the organizer. The alternative would
     * quietly add a fee to what a buyer is charged on the strength of a typo.
     */
    public function bearerFor(Event $event): string
    {
        return $event->effectiveFeeBearer() === self::BEARER_ATTENDEE
            ? self::BEARER_ATTENDEE
            : self::BEARER_ORGANIZER;
    }

    /**
     * Paystack's published collection rate, for pre-sale estimates only. The
     * ledger always books the actual fee the webhook reports.
     */
    public function gatewayFeePercentage(): float
    {
        return (float) config('services.platform.gateway_fee_percentage');
    }
}
