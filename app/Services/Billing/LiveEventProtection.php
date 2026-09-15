<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Event;
use App\Models\Package;
use App\Models\Tenant;
use App\Scopes\TenantScope;

/**
 * Keeps events that are already live working when their organizer moves to a
 * smaller plan — a lapse to Free or a downgrade.
 *
 * Attendees have registered and paid on the terms the event was published
 * with. Changing those mid-sale closed registration on live events, raised the
 * commission on tickets already on sale, and stopped the organizer editing
 * them. So at the moment of the change:
 *
 *  - every live event of a downgraded tenant is grandfathered: it keeps
 *    registering and selling paid tickets, and stays editable, until it ends;
 *  - any live event whose commission, cap or fee bearer would get worse keeps
 *    the terms it had.
 *
 * An upgrade changes nothing here: a lower rate simply applies.
 */
final class LiveEventProtection
{
    /**
     * @return int the number of events protected
     */
    public function protect(Tenant $tenant, ?Package $from, ?Package $to): int
    {
        if (! $to || ($from && (string) $from->id === (string) $to->id)) {
            return 0;
        }

        $downgrade = ! $from
            ? $to->isFree()
            : ($to->isFree() && ! $from->isFree()) || (int) $to->sort_order < (int) $from->sort_order;

        $protected = 0;

        $events = Event::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('status', Event::STATUS_PUBLISHED)
            ->where('ends_at', '>=', now())
            ->get();

        foreach ($events as $event) {
            $changes = [];

            if ($event->terms_locked_at === null && $this->termsWorsen($tenant, $event, $from, $to)) {
                $changes += [
                    'terms_locked_at' => now(),
                    'locked_platform_fee_percentage' => self::percentageUnder($tenant, $from),
                    'locked_platform_fee_cap_amount' => self::capUnder($tenant, $from),
                    'locked_fee_bearer' => self::bearerUnder($tenant, $from),
                ];
            }

            if ($downgrade && $event->grandfathered_at === null) {
                $changes['grandfathered_at'] = now();
            }

            if ($changes !== []) {
                $event->forceFill($changes)->saveQuietly();
                $protected++;
            }
        }

        return $protected;
    }

    /**
     * The same cascade as Event::effectivePlatformFeePercentage(), below the
     * event's own override, for a given package.
     */
    private static function percentageUnder(Tenant $tenant, ?Package $package): float
    {
        return (float) ($tenant->platform_fee_percentage
            ?? $package->default_platform_fee_percentage
            ?? config('services.platform.default_fee_percentage'));
    }

    private static function capUnder(Tenant $tenant, ?Package $package): ?int
    {
        $cap = $tenant->platform_fee_cap_amount
            ?? $package->default_platform_fee_cap_amount
            ?? config('services.platform.default_fee_cap_amount');

        return $cap === null ? null : (int) $cap;
    }

    private static function bearerUnder(Tenant $tenant, ?Package $package): string
    {
        return (string) ($tenant->fee_bearer
            ?? $package->default_fee_bearer
            ?? config('services.platform.default_fee_bearer'));
    }

    private function termsWorsen(Tenant $tenant, Event $event, ?Package $from, Package $to): bool
    {
        if ($event->platform_fee_percentage === null && self::percentageUnder($tenant, $to) > self::percentageUnder($tenant, $from)) {
            return true;
        }

        if ($event->platform_fee_cap_amount === null) {
            $before = self::capUnder($tenant, $from);
            $after = self::capUnder($tenant, $to);

            if ($before !== null && ($after === null || $after > $before)) {
                return true;
            }
        }

        return $event->fee_bearer === null && self::bearerUnder($tenant, $to) !== self::bearerUnder($tenant, $from);
    }
}
