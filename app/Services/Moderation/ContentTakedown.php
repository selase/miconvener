<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Models\Event;
use App\Models\StoreListing;
use App\Models\User;

/**
 * Take down an abusive event or marketplace listing, and restore it.
 *
 * Taking down hides it from the public (it leaves the published status every
 * public page filters on), records who did it and why, and keeps the status
 * it had so restoring puts that back. While it is down the owner's edits
 * cannot republish it (see EventController and VenueListingController).
 * The takedown columns are not mass-assignable, so no owner request can set
 * or clear them.
 */
final class ContentTakedown
{
    public function takeDown(Event|StoreListing $item, User $by, string $reason): void
    {
        if ($item->taken_down_at !== null) {
            return;
        }

        $item->forceFill([
            'status_before_takedown' => $item->status,
            'status' => $item instanceof Event ? Event::STATUS_SUSPENDED : StoreListing::STATUS_SUSPENDED,
            'taken_down_at' => now(),
            'takedown_reason' => $reason,
            'taken_down_by' => $by->id,
        ])->save();

        activity()
            ->causedBy($by)
            ->performedOn($item)
            ->withProperties(['reason' => $reason])
            ->log('Took down '.$this->describe($item));
    }

    public function restore(Event|StoreListing $item, User $by): void
    {
        if ($item->taken_down_at === null) {
            return;
        }

        $item->forceFill([
            'status' => $item->status_before_takedown ?: ($item instanceof Event ? Event::STATUS_DRAFT : StoreListing::STATUS_DRAFT),
            'status_before_takedown' => null,
            'taken_down_at' => null,
            'takedown_reason' => null,
            'taken_down_by' => null,
        ])->save();

        activity()
            ->causedBy($by)
            ->performedOn($item)
            ->log('Restored '.$this->describe($item));
    }

    private function describe(Event|StoreListing $item): string
    {
        return $item instanceof Event ? "event \"{$item->name}\"" : "listing \"{$item->title}\"";
    }
}
