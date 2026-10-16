<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\PlatformPhoneSendReservation;
use InvalidArgumentException;

final class PhoneSignInBudget
{
    public function reserve(string $requestId): bool
    {
        $connection = (new PlatformPhoneSendReservation)->getConnection();

        return $connection->transaction(function () use ($connection, $requestId): bool {
            $day = now('UTC')->toDateString();
            $connection->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['phone-signin-budget:'.$day]);
            $cap = (int) config('attendee_portal.phone.daily_cap', 500);
            if ($cap <= 0 || PlatformPhoneSendReservation::query()->whereKey($requestId)->exists()
                || PlatformPhoneSendReservation::query()->whereDate('day', $day)->where('status', '!=', 'failed')->count() >= $cap) {
                return false;
            }
            PlatformPhoneSendReservation::query()->create(['id' => $requestId, 'day' => $day, 'status' => 'reserved']);

            return true;
        });
    }

    public function finish(string $requestId, string $outcome): void
    {
        if (! in_array($outcome, ['submitted', 'failed', 'unknown'], true)) {
            throw new InvalidArgumentException('Invalid phone submission outcome.');
        }
        PlatformPhoneSendReservation::query()->whereKey($requestId)->where('status', 'reserved')
            ->update(['status' => $outcome, 'submitted_at' => $outcome === 'failed' ? null : now()]);
    }
}
