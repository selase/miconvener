<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventRegistration;

final class SelfCheckIn
{
    public const string SOURCE = 'self';

    /**
     * Offered while the event is running, to a confirmed registration, at a
     * virtual event or an in-person one whose organiser has asked for it.
     */
    public function isAvailableFor(EventRegistration $registration): bool
    {
        $event = $registration->event;

        if ($event === null || ! $registration->isConfirmed()) {
            return false;
        }

        if (! $event->starts_at->isPast() || ! $event->ends_at->isFuture()) {
            return false;
        }

        return $event->location_type === Event::LOCATION_VIRTUAL
            || (bool) $event->allows_self_check_in;
    }

    /**
     * Records presence the same way a door scan does, but leaves checked_in_by
     * null -- nobody checked this person in but themselves.
     */
    public function perform(EventRegistration $registration): bool
    {
        if ($registration->isPresent()) {
            return true;
        }

        if (! $this->isAvailableFor($registration)) {
            return false;
        }

        $registration->update([
            'status' => EventRegistration::STATUS_CHECKED_IN,
            'checked_in_at' => now(),
            'checked_in_by' => null,
            'checked_in_source' => self::SOURCE,
        ]);

        return true;
    }
}
