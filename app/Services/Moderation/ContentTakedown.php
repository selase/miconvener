<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Mail\Moderation\TakedownNoticeMail;
use App\Models\Event;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Take down an abusive event or marketplace listing, and restore it.
 *
 * Taking down hides it from the public (it leaves the published status every
 * public page filters on), records who did it and why, and keeps the status
 * it had so restoring puts that back. While it is down the owner's edits
 * cannot republish it (see EventController and VenueListingController).
 * The takedown columns are not mass-assignable, so no owner request can set
 * or clear them. The organisation is emailed either way, with the reason.
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

        $this->notify($item, restored: false, reason: $reason);
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

        $this->notify($item, restored: true, reason: null);
    }

    /**
     * Email the organisation's address and its admins (and, for a listing,
     * the business's own address).
     */
    private function notify(Event|StoreListing $item, bool $restored, ?string $reason): void
    {
        $tenant = $item instanceof Event
            ? Tenant::query()->find($item->tenant_id)
            : $item->shop?->tenant;

        $recipients = array_values(array_unique(array_filter([
            ...($tenant?->organizerNotificationEmails() ?? []),
            $item instanceof StoreListing ? $item->shop?->email : null,
        ])));

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(new TakedownNoticeMail(
            organisationName: (string) ($tenant->name ?? $item->shop->name ?? 'your organisation'),
            kind: $item instanceof Event ? 'event' : 'listing',
            itemName: $item instanceof Event ? (string) $item->name : (string) $item->title,
            restored: $restored,
            reason: $reason,
        ));
    }

    private function describe(Event|StoreListing $item): string
    {
        return $item instanceof Event ? "event \"{$item->name}\"" : "listing \"{$item->title}\"";
    }
}
