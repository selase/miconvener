<?php

declare(strict_types=1);

namespace App\Services\Dues;

/**
 * The platform's commission on a dues payment, in minor units, at the
 * configured percentage and rounded to the nearest unit.
 */
final class DuesFee
{
    public function for(int $amount): int
    {
        return max(0, (int) round($amount * (float) config('services.dues.platform_fee_percentage', 1) / 100));
    }
}
